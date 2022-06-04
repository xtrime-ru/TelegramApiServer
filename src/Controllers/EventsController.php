<?php

namespace TelegramApiServer\Controllers;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Status;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use Amp\Websocket\Client as WebsocketClient;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Gateway;
use Amp\Websocket\Server\Websocket as WebsocketServer;
use TelegramApiServer\Client;
use TelegramApiServer\EventObservers\EventObserver;
use function Amp\call;

class EventsController implements ClientHandler
{
    private const PING_INTERVAL_MS = 10_000;

    public static function getRouterCallback(): WebsocketServer
    {
        $class = new static();
        return new WebsocketServer($class);
    }

    public function handleHandshake(Gateway $gateway, Request $request, Response $response): Promise
    {
        try {
            $session = $request->getAttribute(Router::class)['session'] ?? null;
            if ($session) {
                Client::getInstance()->getSession($session);
            } elseif (empty(Client::getInstance()->instances)) {
                throw new \RuntimeException('No sessions available');
            }
        }  catch (\Throwable $e){
            return $gateway->getErrorHandler()->handleError(Status::NOT_FOUND, $e->getMessage());
        }

        return new Success($response);
    }

    public function handleClient(Gateway $gateway, WebsocketClient $client, Request $request, Response $response): Promise
    {
        return call(static function() use($gateway, $client, $request) {
            $requestedSession = $request->getAttribute(Router::class)['session'] ?? null;
            yield from static::subscribeForUpdates($gateway, $client, $requestedSession);

            while ($message = yield $client->receive()) {
                // Messages received on the connection are ignored and discarded.
                // Messages must be received properly to maintain connection with client (ping-pong check).
            }
        });
    }

    private static function subscribeForUpdates(Gateway $gateway, WebsocketClient $client, ?string $requestedSession): \Generator
    {
        $clientId = $client->getId();

        yield EventObserver::startEventHandler($requestedSession);

        $pingLoop = Loop::repeat(self::PING_INTERVAL_MS, static fn () => yield $client->ping());

        $client->onClose(static function() use($clientId, $requestedSession, $pingLoop) {
            Loop::cancel($pingLoop);
            EventObserver::removeSubscriber($clientId);
            EventObserver::stopEventHandler($requestedSession);
        });

        EventObserver::addSubscriber($clientId, static function($update, ?string $session) use($gateway, $clientId, $requestedSession) {
            if ($requestedSession && $session !== $requestedSession) {
                return;
            }
            $update = [
                'jsonrpc' => '2.0',
                'result' => [
                    'session' => $session,
                    'update' => $update,
                ],
                'id' => null,
            ];

            $gateway->multicast(
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