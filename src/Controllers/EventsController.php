<?php declare(strict_types=1);

namespace TelegramApiServer\Controllers;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket as WebsocketServer;
use Amp\Websocket\Server\WebsocketAcceptor;
use Amp\Websocket\Server\WebsocketClientGateway;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Revolt\EventLoop;
use RuntimeException;
use TelegramApiServer\Client;
use TelegramApiServer\EventObservers\EventObserver;
use TelegramApiServer\Logger;
use Throwable;

final class EventsController implements WebsocketClientHandler, WebsocketAcceptor
{
    private const PING_INTERVAL_MS = 10_000;
    private WebsocketClientGateway $gateway;
    private Rfc6455Acceptor $handshake;

    public function __construct()
    {
        $this->gateway = new WebsocketClientGateway();
        $this->handshake = new Rfc6455Acceptor();
    }

    public static function getRouterCallback(SocketHttpServer $server): WebsocketServer
    {
        $class = new static();

        return new WebsocketServer(
            httpServer: $server,
            logger: Logger::getInstance(),
            acceptor: $class,
            clientHandler: $class,
        );
    }

    public function handleHandshake(Request $request): Response
    {
        try {
            $session = $request->getAttribute(Router::class)['session'] ?? null;
            if ($session) {
                Client::getInstance()->getSession($session);
            } elseif (empty(Client::getInstance()->instances)) {
                throw new RuntimeException('No sessions available');
            }
            $response = $this->handshake->handleHandshake($request);
        } catch (Throwable $e) {
            $response = new Response();
            $response->setStatus(HttpStatus::NOT_FOUND);
            $response->setBody($e->getMessage());
        }

        return $response;
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $requestedSession = $request->getAttribute(Router::class)['session'] ?? null;
        $this->subscribeForUpdates($client, $requestedSession);
        $this->gateway->addClient($client);

        while ($message = $client->receive()) {
            notice('Recieved websocket message: ' . $message->buffer());
            // Messages received on the connection are ignored and discarded.
            // Messages must be received properly to maintain connection with client (ping-pong check).
        }
    }

    private function subscribeForUpdates(WebsocketClient $client, ?string $requestedSession): void
    {
        $clientId = $client->getId();

        EventObserver::startEventHandler($requestedSession);

        $pingLoop = EventLoop::repeat(self::PING_INTERVAL_MS, static fn () => $client->ping());

        $client->onClose(static function () use ($clientId, $requestedSession, $pingLoop) {
            EventLoop::cancel($pingLoop);
            EventObserver::removeSubscriber($clientId);
            EventObserver::stopEventHandler($requestedSession);
        });

        EventObserver::addSubscriber($clientId, function ($update, ?string $session) use ($clientId, $requestedSession) {
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

            $this->gateway->multicastText(
                \json_encode(
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
