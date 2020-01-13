<?php

namespace TelegramApiServer;

use Amp;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Psr\Log\LogLevel;
use TelegramApiServer\Controllers\ApiController;
use TelegramApiServer\Controllers\EventsController;

class Server
{
    /**
     * Server constructor.
     * @param Client $client
     * @param array $options
     */
    public function __construct(Client $client, array $options)
    {
        Amp\Loop::run(function () use ($client, $options) {
            $server = new Amp\Http\Server\Server(
                $this->getServerAddresses(static::getConfig($options)),
                static::getRouter($client),
                Logger::getInstance(),
                (new Amp\Http\Server\Options())
                    ->withCompression()
                    ->withBodySizeLimit(30*1024*1024)
            );

            yield $server->start();

            static::registerShutdown($server);
        });
    }

    private static function getServerAddresses(array $config): array
    {
        return [
            Amp\Socket\Server::listen("{$config['address']}:{$config['port']}"),
        ];
    }

    private static function getRouter(Client $client): Amp\Http\Server\Router
    {
        $router = new Amp\Http\Server\Router();
        foreach (['GET', 'POST'] as $method) {
            $router->addRoute($method, '/api/{session}/{method}', ApiController::getRouterCallback($client));
            $router->addRoute($method, '/api/{method}', ApiController::getRouterCallback($client));

            $router->addRoute($method, '/events[/{session}]', EventsController::getRouterCallback($client));
        }

        $router->setFallback(new CallableRequestHandler(static function (Request $request) {
            return new Response(
                Amp\Http\Status::NOT_FOUND,
                [ 'Content-Type'=>'application/json;charset=utf-8'],
                json_encode(
                    [
                        'success' => 0,
                        'errors' => [
                            [
                                'code' => 404,
                                'message' => 'Path not found',
                            ]
                        ]
                    ],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                ) . "\n"
            );
        }));

        return $router;
    }

    /**
     * Stop the server gracefully when SIGINT is received.
     * This is technically optional, but it is best to call Server::stop().
     *
     * @throws Amp\Loop\UnsupportedFeatureException
     */
    private static function registerShutdown(Amp\Http\Server\Server $server)
    {

        if (defined('SIGINT')) {
            Amp\Loop::onSignal(SIGINT, static function (string $watcherId) use ($server) {
                Amp\Loop::cancel($watcherId);
                yield $server->stop();
            });
        }
    }

    /**
     * Установить конфигурацию для http-сервера
     *
     * @param array $config
     * @return array
     */
    private static function getConfig(array $config = []): array
    {
        $config =  array_filter($config);

        $config = array_merge(
            Config::getInstance()->get('server', []),
            $config
        );

        return $config;
    }

}