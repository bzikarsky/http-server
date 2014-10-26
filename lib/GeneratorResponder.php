<?php

namespace Aerys;

use Amp\Future;
use Amp\Promise;
use Amp\Failure;
use Amp\Success;
use Amp\Promisor;
use Amp\Resolver;
use Amp\Combinator;

class GeneratorResponder {
    const STATUS = 'status';
    const REASON = 'reason';
    const HEADER = 'header';
    const BODY = 'body';

    private $struct;
    private $isWatcherEnabled;
    private $isOutputStarted;
    private $isSocketGone;
    private $shouldNotifyUserAbort;
    private $generator;
    private $promisor;
    private $combinator;
    private $isChunking;
    private $isFinalWrite;
    private $buffer = '';
    private $bufferSize = 0;
    private $status = 200;
    private $reason = '';
    private $headers = [];

    /**
     * Prepare the Responder
     *
     * @param \Aerys\ResponderStruct $responderStruct
     * @return void
     */
    public function prepare(ResponderStruct $struct) {
        $this->struct = $struct;
        $this->generator = $struct->response;
        $this->promisor = new Future($struct->reactor);
        $this->combinator = new Combinator($struct->reactor);
    }

    /**
     * Write the prepared Response
     *
     * @return \Amp\Promise
     */
    public function write() {
        if ($this->bufferSize) {
            $this->doWrite();
        } else {
            $this->advanceGenerator($this->generator, $this->promisor);
        }

        return $this->promisor;
    }

    private function doWrite() {
        $bytesWritten = @fwrite($this->struct->socket, $this->buffer);
        $this->bufferSize -= $bytesWritten;
        $isBufferEmpty = empty($this->bufferSize);

        if ($this->isFinalWrite && $isBufferEmpty) {
            goto write_complete;
        } elseif ($isBufferEmpty) {
            goto awaiting_more_data;
        } elseif ($bytesWritten !== false) {
            goto write_incomplete;
        } else {
            goto write_error;
        }

        write_complete: {
            $this->promisor->succeed($this->struct->mustClose);
            if ($this->isWatcherEnabled) {
                $this->isWatcherEnabled = false;
                $this->struct->reactor->disable($this->struct->writeWatcher);
            }
            return;
        }

        awaiting_more_data: {
            $this->buffer = '';
            if ($this->isWatcherEnabled) {
                $this->isWatcherEnabled = false;
                $this->struct->reactor->disable($this->struct->writeWatcher);
            }
            return;
        }

        write_incomplete: {
            $this->buffer = substr($this->buffer, $bytesWritten);
            if (!$this->isWatcherEnabled) {
                $this->struct->reactor->enable($this->struct->writeWatcher);
                $this->isWatcherEnabled = true;
            }
            return;
        }

        write_error: {
            $this->buffer = '';
            $this->bufferSize = 0;
            $this->isSocketGone = $this->shouldNotifyUserAbort = true;
            $this->struct->reactor->disable($this->struct->writeWatcher);

            // We always notify the HTTP server immediately in the event
            // of a client disconnect even though the application may choose
            // to continue processing. Be sure not to fail the top-level
            // promisor again later or an error will result.
            $this->promisor->fail(new ClientGoneException);
            return;
        }
    }

    private function resolveGenerator(\Generator $generator) {
        $promisor = new Future($this->struct->reactor);
        $this->advanceGenerator($generator, $promisor);

        return $promisor;
    }

    private function advanceGenerator(\Generator $gen, Promisor $promisor, $previousResult = null) {
        try {
            if ($gen->valid()) {
                $key = $gen->key();
                $current = $gen->current();
                $promise = $this->promisifyYield($key, $current);
                $this->struct->reactor->immediately(function() use ($gen, $promisor, $promise) {
                    $promise->when(function($error, $result) use ($gen, $promisor) {
                        $this->sendToGenerator($gen, $promisor, $error, $result);
                    });
                });
            } elseif ($promisor === $this->promisor) {
                $this->finalizeWrite();
            } else {
                $promisor->succeed($previousResult);
            }
        } catch (\Exception $error) {
            $promisor->fail($error);
        }
    }

    private function promisifyYield($key, $current) {
        if ($this->isSocketGone && $this->shouldNotifyUserAbort) {
            $this->shouldNotifyUserAbort = false;
            return new Failure(new ClientGoneException);
        } elseif ($current instanceof Promise) {
            return $current;
        } elseif ($key === (string) $key) {
            goto explicit_key;
        } else {
            goto implicit_key;
        }

        explicit_key: {
            switch ($key) {
                case self::STATUS:
                    goto status;
                case self::REASON:
                    goto reason;
                case self::HEADER:
                    goto header;
                case self::BODY:
                    goto body;
                case Resolver::IMMEDIATELY:
                    goto immediately;
                case Resolver::ONCE:
                    // fallthrough
                case Resolver::REPEAT:
                    goto schedule;
                case Resolver::ENABLE:
                    // fallthrough
                case Resolver::DISABLE:
                    // fallthrough
                case Resolver::CANCEL:
                    goto watcher_control;
                case Resolver::WAIT:
                    goto wait;
                case Resolver::ALL:
                    // fallthrough
                case Resolver::ANY:
                    // fallthrough
                case Resolver::SOME:
                    goto combinator;
                default:
                    return new Failure(new \DomainException(
                        sprintf('Unknown yield key: "%s"', $key)
                    ));
            }
        }

        implicit_key: {
            if (is_string($current)) {
                goto body;
            } elseif (is_array($current)) {
                // An array without an explicit key is assumed to be an "all" combinator
                $key = Resolver::ALL;
                goto combinator;
            } elseif ($current instanceof \Generator) {
                return $this->resolveGenerator($current);
            } else {
                return new Success($current);
            }
        }

        immediately: {
            if (!is_callable($current)) {
                return new Failure(new \DomainException(
                    sprintf(
                        '"%s" yield requires callable; %s provided',
                        $key,
                        gettype($current)
                    )
                ));
            }

            $watcherId = $this->struct->reactor->immediately($current);

            return new Success($watcherId);
        }

        schedule: {
            if (!($current && isset($current[0], $current[1]) && is_array($current))) {
                return new Failure(new \DomainException(
                    sprintf(
                        '"%s" yield requires [callable $func, int $msDelay]; %s provided',
                        $key,
                        gettype($current)
                    )
                ));
            }

            list($func, $msDelay) = $current;
            $watcherId = $this->struct->reactor->{$key}($func, $msDelay);

            return new Success($watcherId);
        }

        watcher_control: {
            $this->struct->reactor->{$key}($current);
            return new Success;
        }

        wait: {
            $promisor = new Future($this->struct->reactor);
            $this->struct->reactor->once(function() use ($promisor) {
                $promisor->succeed();
            }, (int) $current);

            return $promisor;
        }

        combinator: {
            $promises = [];
            foreach ($current as $index => $element) {
                if ($element instanceof Promise) {
                    $promise = $element;
                } elseif ($element instanceof \Generator) {
                    $promise = $this->resolveGenerator($element);
                } else {
                    $promise = new Success($element);
                }

                $promises[$index] = $promise;
            }

            return $this->combinator->{$key}($promises);
        }

        status: {
            if ($this->isOutputStarted) {
                return new Failure(new \LogicException(
                    'Cannot assign status code: output already started'
                ));
            }

            $status = (int) $current;

            if ($status < 100 || $status > 599) {
                return new Failure(new \DomainException(
                    'Cannot assign status code: integer in the set [100,599] required'
                ));
            }

            $this->status = $status;

            return new Success($status);
        }

        reason: {
            if ($this->isOutputStarted) {
                return new Failure(new \LogicException(
                    'Cannot assign reason phrase: output already started'
                ));
            }

            $this->reason = $reason = (string) $current;

            return new Success($reason);
        }

        header: {
            if ($this->isOutputStarted) {
                return new Failure(new \LogicException(
                    'Cannot assign header: output already started'
                ));
            }

            if (is_array($current)) {
                $this->headers += $current;
            } elseif (is_string($current)) {
                $this->headers[] = (string) $current;
            } else {
                return new Failure(new \DomainException(
                    sprintf(
                        '"header" key expects a string or array of strings; %s yielded',
                        gettype($current)
                    )
                ));
            }

            return new Success($current);
        }

        body: {
            if ($this->isSocketGone) {
                // If we've gotten this far the application has already
                // caugh the ClientGoneException and chosen to continue
                // processing. Indicate success to the generator but do
                // not buffer any further data for writing.
                return new Success;
            }

            if (!$this->isOutputStarted) {
                $this->startOutput();
            }

            $chunk = $this->isChunking ? dechex(strlen($current)) . "\r\n{$current}\r\n" : $current;
            $this->buffer .= $chunk;
            $this->bufferSize = strlen($this->buffer);

            if (!$this->isWatcherEnabled) {
                $this->doWrite();
            }

            return new Success($current);
        }
    }

    private function sendToGenerator(\Generator $gen, Promisor $promisor, \Exception $error = null, $result = null) {
        try {
            if ($this->shouldNotifyUserAbort) {
                $this->shouldNotifyUserAbort = false;
                $gen->throw(new ClientGoneException);
            } elseif ($error) {
                $gen->throw($error);
            } else {
                $gen->send($result);
            }
            $this->advanceGenerator($gen, $promisor, $result);
        } catch (ClientGoneException $error) {
            // There's nothing else to do. The application didn't catch
            // the user abort and the server has already been notified
            // that the client disconnected. We're finished.
            return;
        } catch (\Exception $error) {
            $promisor->fail($error);
        }
    }

    private function startOutput() {
        $this->isOutputStarted = true;

        $struct = $this->struct;
        $request = $struct->request;
        $protocol = $request['SERVER_PROTOCOL'];
        $status = $this->status;
        $reason = $this->reason;
        $reason = ($reason === '') ? $reason : " {$reason}"; // leading space is important!

        if ($status < 200) {
            $struct->mustClose = false;
            $this->buffer = "HTTP/{$protocol} {$status}{$reason}\r\n\r\n";
            $this->bufferSize = strlen($this->buffer);
            $this->isFinalWrite = true;
            return;
        }

        $headers = $this->headers ? implode("\r\n", $this->headers) : '';
        $headers = removeHeader($headers, 'Content-Length');

        if ($struct->mustClose) {
            $headers = setHeader($headers, 'Connection', 'close');
            $transferEncoding = 'identity';
        } elseif (headerMatches($headers, 'Connection', 'close')) {
            $struct->mustClose = true;
            $transferEncoding = 'identity';
        } elseif ($protocol >= 1.1) {
            // Append Connection header, don't set. There are scenarios where
            // multiple Connection headers are required (e.g. websocket handshakes).
            $headers = addHeaderLine($headers, "Connection: keep-alive");
            $headers = setHeader($headers, 'Keep-Alive', $struct->keepAlive);
            $this->isChunking = true;
            $transferEncoding = 'chunked';
        } else {
            $struct->mustClose = true;
            $headers = setHeader($headers, 'Connection', 'close');
            $transferEncoding = 'identity';
        }

        $headers = setHeader($headers, 'Transfer-Encoding', $transferEncoding);

        $contentType = hasHeader($headers, 'Content-Type')
            ? getHeader($headers, 'Content-Type')
            : $struct->defaultContentType;

        if (stripos($contentType, 'text/') === 0 && stripos($contentType, 'charset=') === false) {
            $contentType .= "; charset={$struct->defaultTextCharset}";
        }

        $headers = setHeader($headers, 'Content-Type', $contentType);
        $headers = setHeader($headers, 'Date', $struct->httpDate);

        if ($struct->serverToken) {
            $headers = setHeader($headers, 'Server', $struct->serverToken);
        }

        if ($request['REQUEST_METHOD'] === 'HEAD') {
            $this->isFinalWrite = true;
        }

        $this->buffer = "HTTP/{$protocol} {$status}{$reason}\r\n{$headers}\r\n\r\n";
        $this->bufferSize = strlen($this->buffer);
    }

    private function finalizeWrite() {
        if ($this->isSocketGone) {
            return;
        }

        if ($this->isFinalWrite || !$this->isChunking) {
            $this->promisor->succeed($this->struct->mustClose);
            return;
        }

        $this->isFinalWrite = true;
        $this->buffer .= "0\r\n\r\n";
        $this->bufferSize += 5;

        if (!$this->isWatcherEnabled) {
            $this->doWrite();
        }
    }
}
