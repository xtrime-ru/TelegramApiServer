<?php

namespace TelegramApiServer\Controllers;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Promise;
use Amp\Success;
use Amp\Websocket\Server\ClientHandler;
use Amp\Websocket\Server\Websocket;
use TelegramApiServer\Client;
use TelegramApiServer\EventObservers\EventObserver;
use function Amp\call;

class EventsController implements ClientHandler
{
    private Client $client;
    private ?Websocket $endpoint;

    public static function getRouterCallback(Client $client): Websocket
    {
        $class = new static();
        $class->client = $client;
        return  new Websocket($class);
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
        try {
            $session = $request->getAttribute(Router::class)['session'] ?? null;
            if ($session) {
                $this->client->getInstance($session);
            }
        }  catch (\Throwable $e){
            $response->setStatus(400);
        }

        return new Success($response);
    }

    public function handleClient(\Amp\Websocket\Client $client, Request $request, Response $response): Promise
    {
        return call(function() use($client, $request) {
            $requestedSession = $request->getAttribute(Router::class)['session'] ?? null;
            $this->subscribeForUpdates($client, $requestedSession);

            while ($message = yield $client->receive()) {
                // Messages received on the connection are ignored and discarded.
                // Messages must be received properly to maintain connection with client (ping-pong check).
            }
        });
    }

    private function subscribeForUpdates(\Amp\Websocket\Client $client, ?string $requestedSession): void
    {
        $clientId = $client->getId();

        $client->onClose(static function() use($clientId) {
            EventObserver::removeSubscriber($clientId);
        });

        EventObserver::addSubscriber($clientId, function($update, ?string $session) use($clientId, $requestedSession) {
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