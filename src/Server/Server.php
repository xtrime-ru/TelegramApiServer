<?php

namespace TelegramApiServer\Server;

use Amp;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\ConnectionLimitingServerSocketFactory;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Amp\Sync\LocalSemaphore;
use TelegramApiServer\Client;
use TelegramApiServer\Config;
use TelegramApiServer\Logger;
use function sprintf;
use const SIGINT;
use const SIGTERM;

class Server
{
    /**
     * Server constructor.
     *
     * @param array $options
     * @param array|null $sessionFiles
     */
    public function __construct(array $options, ?array $sessionFiles)
    {
        $server = new SocketHttpServer(
            logger: Logger::getInstance(),
            serverSocketFactory: new ConnectionLimitingServerSocketFactory(new LocalSemaphore(1000)),
            clientFactory: new Amp\Http\Server\Driver\SocketClientFactory(
                logger: Logger::getInstance(),
            ),
            httpDriverFactory: new DefaultHttpDriverFactory(
                logger: Logger::getInstance(),
                streamTimeout: 600,
                connectionTimeout: 60,
                bodySizeLimit: 5 * (1024 ** 3), //5Gb
            )
        );

        $config = self::getConfig($options);
        $server->expose(new InternetAddress($config['address'], $config['port']));
        Client::getInstance()->connect($sessionFiles);
        $errorHandler = new DefaultErrorHandler();
        $server->start((new Router($server, $errorHandler))->getRouter(), $errorHandler);
        self::registerShutdown($server);

    }


    /**
     * Stop the server gracefully when SIGINT is received.
     * This is technically optional, but it is best to call Server::stop().
     *
     *
     */
    private static function registerShutdown(SocketHttpServer $server)
    {
        if (defined('SIGINT')) {
            // Await SIGINT or SIGTERM to be received.
            $signal = Amp\trapSignal([SIGINT, SIGTERM]);
            info(sprintf("Received signal %d, stopping HTTP server", $signal));
            $server->stop();
        }
    }

    /**
     * Установить конфигурацию для http-сервера
     *
     * @param array $config
     * @return array
     */
    private function getConfig(array $config = []): array
    {
        $config = array_filter($config);

        $config = array_merge(
            Config::getInstance()->get('server', []),
            $config
        );

        return $config;
    }

}