<?php

namespace TelegramApiServer\Controllers;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Promise;
use Amp\Success;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Websocket;
use Psr\Log\LogLevel;
use TelegramApiServer\EventObservers\LogObserver;
use TelegramApiServer\Logger;
use function Amp\call;

class LogController implements ClientHandler
{
    private ?Websocket $endpoint;

    public static function getRouterCallback(): Websocket
    {
        return new Websocket(new static());
    }

    public function onStart(Websocket $endpoint): Promise
    {
        $this->endpoint = $endpoint;
        return new Success;
    }

    public function onStop(Websocket $endpoint): Promise
    {
        $this->endpoint = null;
        return new Success;
    }

    public function handleHandshake(Request $request, Response $response): Promise
    {
        $level = $request->getAttribute(Router::class)['level'] ?? LogLevel::DEBUG;
        if (!isset(Logger::$levels[$level])) {
            $response->setStatus(400);
        }
        return new Success($response);
    }

    public function handleClient(\Amp\Websocket\Client $client, Request $request, Response $response): Promise
    {
        return call(function() use($client, $request) {
            $level = $request->getAttribute(Router::class)['level'] ?? LogLevel::DEBUG;
            $this->subscribeForUpdates($client, $level);

            while ($message = yield $client->receive()) {
                // Messages received on the connection are ignored and discarded.
                // Messages must be received properly to maintain connection with client (ping-pong check).
            }
        });
    }

    private function subscribeForUpdates(\Amp\Websocket\Client $client, string $requestedLevel): void
    {
        $clientId = $client->getId();

        $client->onClose(static function() use($clientId) {
            LogObserver::removeSubscriber($clientId);
        });

        LogObserver::addSubscriber($clientId, function(string $level, string $message, array $context = []) use($clientId, $requestedLevel) {
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

            $this->endpoint->multicast(
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