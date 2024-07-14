<?php declare(strict_types=1);

namespace TelegramApiServer\Controllers;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketAcceptor;
use Amp\Websocket\Server\WebsocketClientGateway;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Psr\Log\LogLevel;
use Revolt\EventLoop;
use TelegramApiServer\EventObservers\LogObserver;
use TelegramApiServer\Logger;

final class LogController implements WebsocketClientHandler, WebsocketAcceptor
{
    private const PING_INTERVAL_MS = 10_000;
    private WebsocketClientGateway $gateway;
    private Rfc6455Acceptor $handshake;

    public function __construct()
    {
        $this->gateway = new WebsocketClientGateway();
        $this->handshake = new Rfc6455Acceptor();
    }

    public static function getRouterCallback(SocketHttpServer $server): Websocket
    {
        $class = new static();
        return new Websocket(
            httpServer: $server,
            logger: Logger::getInstance(),
            acceptor: $class,
            clientHandler: $class,
        );
    }

    public function handleHandshake(Request $request): Response
    {
        $level = $request->getAttribute(Router::class)['level'] ?? LogLevel::DEBUG;
        if (!isset(Logger::$levels[$level])) {
            $response = new Response(400);
        } else {
            $response = $this->handshake->handleHandshake($request);
        }
        return $response;
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $level = $request->getAttribute(Router::class)['level'] ?? LogLevel::DEBUG;
        $this->subscribeForUpdates($client, $level);
        $this->gateway->addClient($client);

        while ($message = $client->receive()) {
            notice('Recived log websocket message: ' . $message->buffer());
            // Messages received on the connection are ignored and discarded.
            // Messages must be received properly to maintain connection with client (ping-pong check).
        }
    }

    private function subscribeForUpdates(WebsocketClient $client, string $requestedLevel): void
    {
        $clientId = $client->getId();

        $pingLoop = EventLoop::repeat(self::PING_INTERVAL_MS, static fn () => $client->ping());

        $client->onClose(static function () use ($clientId, $pingLoop) {
            EventLoop::cancel($pingLoop);
            LogObserver::removeSubscriber($clientId);
        });

        LogObserver::addSubscriber($clientId, function (string $level, string $message, array $context = []) use ($clientId, $requestedLevel) {
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
