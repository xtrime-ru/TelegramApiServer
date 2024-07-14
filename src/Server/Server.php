<?php declare(strict_types=1);

namespace TelegramApiServer\Server;

use Amp;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\ConnectionLimitingServerSocketFactory;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Amp\Sync\LocalSemaphore;
use Revolt\EventLoop;
use TelegramApiServer\Client;
use TelegramApiServer\Config;
use TelegramApiServer\Logger;
use const SIGINT;
use const SIGTERM;

final class Server
{
    /**
     * Server constructor.
     *
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
        if (\defined('SIGINT')) {
            // Await SIGINT or SIGTERM to be received.
            $signal = Amp\trapSignal([SIGINT, SIGTERM]);
            info(\sprintf("Received signal %d, stopping HTTP server", $signal));
            $server->stop();
        } else {
            EventLoop::run();
            info("Stopping http server");
            $server->stop();
        }
        Logger::finalize();
    }

    /**
     * Установить конфигурацию для http-сервера.
     *
     */
    private function getConfig(array $config = []): array
    {
        $config = \array_filter($config);

        $config = \array_merge(
            Config::getInstance()->get('server', []),
            $config
        );

        return $config;
    }

    public static function getClientIp(Request $request): string
    {
        $realIpHeader = Config::getInstance()->get('server.real_ip_header');
        if ($realIpHeader) {
            $remote = $request->getHeader($realIpHeader);
            if (!$remote) {
                goto DIRECT;
            }
            $tmp = \explode(',', $remote);
            $remote = \trim(\end($tmp));
        } else {
            DIRECT:
            $remote = $request->getClient()->getRemoteAddress()->toString();
            $hostArray = \explode(':', $remote);
            if (\count($hostArray) >= 2) {
                $port = (int) \array_pop($hostArray);
                if ($port > 0 && $port <= 65353) {
                    $remote = \implode(':', $hostArray);
                }
            }

        }

        return $remote;
    }

}
