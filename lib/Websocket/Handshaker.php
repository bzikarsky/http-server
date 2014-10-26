<?php

namespace Aerys\Websocket;

use Amp\Reactor;
use Amp\Success;
use Aerys\Server;
use Aerys\Status;
use Aerys\ServerObserver;

class Handshaker implements ServerObserver {
    const ACCEPT_CONCAT = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private $endpoints;
    private $isStopping;

    /**
     * Conduct the websocket handshake and respond appropriately
     *
     * @param Aerys\Websocket\Endpoint $endpoint
     * @param array $request The HTTP request environment map
     * @return array|\Aerys\Responder
     */
    public function __invoke(array $request) {
        if ($this->isStopping) {
            return [
                'status' => Status::SERVICE_UNAVAILABLE
            ];
        }

        $uriPath = $request['REQUEST_URI_PATH'];
        if (isset($this->endpoints[$uriPath])) {
            $endpoint = $this->endpoints[$uriPath];
        } else {
            return [
                'status' => Status::NOT_FOUND
            ];
        }

        if ($request['REQUEST_METHOD'] != 'GET') {
            return [
                'status' => Status::METHOD_NOT_ALLOWED
            ];
        }

        if ($request['SERVER_PROTOCOL'] < 1.1) {
            return [
                'status' => Status::HTTP_VERSION_NOT_SUPPORTED
            ];
        }

        if (empty($request['HTTP_UPGRADE']) || strcasecmp($request['HTTP_UPGRADE'], 'websocket') !== 0) {
            return [
                'status' => Status::UPGRADE_REQUIRED
            ];
        }

        if (empty($request['HTTP_CONNECTION']) || stripos($request['HTTP_CONNECTION'], 'Upgrade') === FALSE) {
            return [
                'status' => Status::BAD_REQUEST,
                'reason' => 'Bad Request: "Connection: Upgrade" header required',
            ];
        }

        if (empty($request['HTTP_SEC_WEBSOCKET_KEY'])) {
            return [
                'status' => Status::BAD_REQUEST,
                'reason' => 'Bad Request: "Sec-Broker-Key" header required',
            ];
        }

        if (empty($request['HTTP_SEC_WEBSOCKET_VERSION'])) {
            return [
                'status' => Status::BAD_REQUEST,
                'reason' => 'Bad Request: "Sec-WebSocket-Version" header required',
            ];
        }

        $version = null;
        $requestedVersions = explode(',', $request['HTTP_SEC_WEBSOCKET_VERSION']);
        foreach ($requestedVersions as $requestedVersion) {
            if ($requestedVersion === '13') {
                $version = 13;
                break;
            }
        }

        if (empty($version)) {
            return [
                'status' => Status::BAD_REQUEST,
                'reason' => 'Bad Request: Requested WebSocket version(s) unavailable',
                'header' => 'Sec-WebSocket-Version: 13',
            ];
        }

        $originHeader = empty($request['HTTP_ORIGIN']) ? NULL : $request['HTTP_ORIGIN'];
        if ($originHeader && !$endpoint->allowsOrigin($originHeader)) {
            return ['status' => Status::FORBIDDEN];
        }

        $subprotocol = null;
        $reqSubprotos = !empty($request['HTTP_SEC_WEBSOCKET_PROTOCOL'])
            ? explode(',', $request['HTTP_SEC_WEBSOCKET_PROTOCOL'])
            : [];

        if ($reqSubprotos && (!$subprotocol = $endpoint->negotiateSubprotocol($reqSubprotos))) {
            return [
                'status'  => Status::BAD_REQUEST,
                'reason'  => 'Bad Request: Requested WebSocket subprotocol(s) unavailable',
            ];
        }

        /**
         * @TODO Negotiate supported Sec-WebSocket-Extensions
         *
         * The Sec-WebSocket-Extensions header field is used to select protocol-level extensions as
         * outlined in RFC 6455 Section 9.1:
         *
         * http://tools.ietf.org/html/rfc6455#section-9.1
         *
         * As of 2013-03-08 no extensions have been registered with the IANA:
         *
         * http://www.iana.org/assignments/websocket/websocket.xml#extension-name
         */
        $extensions = [];

        $concatKeyStr = $request['HTTP_SEC_WEBSOCKET_KEY'] . self::ACCEPT_CONCAT;
        $secWebSocketAccept = base64_encode(sha1($concatKeyStr, TRUE));

        $header = [
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Accept: {$secWebSocketAccept}"
        ];

        if ($subprotocol) {
            $header[] = "Sec-WebSocket-Protocol: {$subprotocol}";
        }

        if ($extensions) {
            $header[] = 'Sec-WebSocket-Extensions: ' . implode(',', $extensions);
        }

        $header = implode("\r\n", $header);
        $handshakeResponse = "HTTP/1.1 101 Switching Protocols\r\n{$header}\r\n\r\n";

        return new HandshakeResponder($endpoint, $handshakeResponse);
    }

    /**
     * Listen for server STOPPING notifications
     *
     * If the Server notifies us that it wants to shutdown we shouldn't accept new connections.
     * Store this information so we can return an appropriate response if a request arrives
     * while the server is trying to stop.
     *
     * @param \Aerys\Server $server
     * @param int $event
     */
    public function onServerUpdate(Server $server, $event) {
        if ($event === Server::STOPPING) {
            $this->isStopping = TRUE;
            return new Success;
        }
    }

    /**
     * Add an endpoint resource
     *
     * @param string $uriPath
     * @param Aerys\Websocket\Endpoint $endpoint
     */
    public function addEndpoint($uriPath, Endpoint $endpoint) {
        $this->endpoints[$uriPath] = $endpoint;
    }
}
