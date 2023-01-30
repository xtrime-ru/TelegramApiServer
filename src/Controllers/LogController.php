<?php

namespace TelegramApiServer\Controllers;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\Server\WebsocketHandshakeHandler;
use Amp\Websocket\WebsocketClient;
use Psr\Log\LogLevel;
use TelegramApiServer\EventObservers\LogObserver;
use TelegramApiServer\Logger;

class LogController implements WebsocketClientHandler, WebsocketHandshakeHandler
{

    public static function getRouterCallback(): Websocket
    {
        $class = new static();
        return new Websocket(
            logger: Logger::getInstance(),
            handshakeHandler: $class,
            clientHandler: $class,
        );
    }

    public function handleHandshake(Request $request, Response $response): Response
    {
        $level = $request->getAttribute(Router::class)['level'] ?? LogLevel::DEBUG;
        if (!isset(Logger::$levels[$level])) {
            $response->setStatus(400);
        }
        return $response;
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $level = $request->getAttribute(Router::class)['level'] ?? LogLevel::DEBUG;
        static::subscribeForUpdates($client, $level);

        while ($message = $client->receive()) {
            notice('Recived log websocket message: ' . $message->buffer());
            // Messages received on the connection are ignored and discarded.
            // Messages must be received properly to maintain connection with client (ping-pong check).
        }
    }

    private static function subscribeForUpdates(WebsocketClient $client, string $requestedLevel): void
    {
        $clientId = $client->getId();

        $client->onClose(static function() use($clientId) {
            LogObserver::removeSubscriber($clientId);
        });

        LogObserver::addSubscriber($clientId, static function(string $level, string $message, array $context = []) use($endpoint, $clientId, $requestedLevel) {
            if ($requestedLevel && Logger::$levels[$level] < Logger::$levels[$requestedLevel]) {
                return;
            }
            $update = [
                'jsonrpc' => '2.0',
                'result' => [
                    'level' => $level,
                    'message' => $message,
                    'context' => $context
                ],
                'id' => null,
            ];

            $endpoint->multicast(
                json_encode(
                    $update,
                    JSON_THROW_ON_ERROR |
                    JSON_INVALID_UTF8_SUBSTITUTE |
                    JSON_UNESCAPED_SLASHES |
                    JSON_UNESCAPED_UNICODE
                ),
                [$clientId]
            );
        });
    }
}