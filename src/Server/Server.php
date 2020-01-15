<?php

namespace TelegramApiServer\Server;

use Amp;
use TelegramApiServer\Client;
use TelegramApiServer\Config;
use TelegramApiServer\Logger;

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
                (new Router($client))->getRouter(),
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

    /**
     * Stop the server gracefully when SIGINT is received.
     * This is technically optional, but it is best to call Server::stop().
     *
     * @param Amp\Http\Server\Server $server
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
