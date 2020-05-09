<?php

namespace TelegramApiServer\Controllers;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Success;
use Amp\Websocket\Client as WebsocketClient;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Endpoint;
use Amp\Websocket\Server\Websocket as WebsocketServer;
use TelegramApiServer\Client;
use TelegramApiServer\EventObservers\EventObserver;
use function Amp\call;

class EventsController implements ClientHandler
{

    public static function getRouterCallback(): WebsocketServer
    {
        $class = new static();
        return  new WebsocketServer($class);
    }

    public function handleHandshake(Endpoint $endpoint, Request $request, Response $response): Promise
    {
        try {
            $session = $request->getAttribute(Router::class)['session'] ?? null;
            if ($session) {
                Client::getInstance()->getSession($session);
            }
        }  catch (\Throwable $e){
            return $endpoint->getErrorHandler()->handleError(Status::NOT_FOUND, $e->getMessage());
        }

        return new Success($response);
    }

    public function handleClient(Endpoint $endpoint, WebsocketClient $client, Request $request, Response $response): Promise
    {
        return call(static function() use($endpoint, $client, $request) {
            $requestedSession = $request->getAttribute(Router::class)['session'] ?? null;
            yield from static::subscribeForUpdates($endpoint, $client, $requestedSession);

            while ($message = yield $client->receive()) {
                // Messages received on the connection are ignored and discarded.
                // Messages must be received properly to maintain connection with client (ping-pong check).
            }
        });
    }

    private static function subscribeForUpdates(Endpoint $endpoint, WebsocketClient $client, ?string $requestedSession): \Generator
    {
        $clientId = $client->getId();

        yield EventObserver::startEventHandler($requestedSession);

        $client->onClose(static function() use($clientId, $requestedSession) {
            EventObserver::removeSubscriber($clientId);
            EventObserver::stopEventHandler($requestedSession);
        });

        EventObserver::addSubscriber($clientId, static function($update, ?string $session) use($endpoint, $clientId, $requestedSession) {
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