<?php

namespace TelegramApiServer;

use Amp;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Promise;
use Amp\Socket;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Psr\Log\LogLevel;

class Server
{
    private $config = [];

    /**
     * Server constructor.
     * @param Client $client
     * @param array $options
     */
    public function __construct(Client $client, array $options)
    {
        $this->setConfig($options);

        Amp\Loop::run(function () use ($client) {
            $sockets = [
                Socket\listen("{$this->config['address']}:{$this->config['port']}"),
            ];

            $server = new Amp\Http\Server\Server(
                $sockets,
                new CallableRequestHandler(function (Request $request) use($client) {
                    //На каждый запрос должны создаваться новые экземпляры классов парсера и коллбеков,
                    //иначе их данные будут в области видимости всех запросов.

                    //Телеграм клиент инициализируется 1 раз и используется во всех запросах.

                    $requestCallback = new RequestCallback($client);
                    $response = yield from $requestCallback->process($request);

                    return new Response(
                        $requestCallback->page['code'],
                        $requestCallback->page['headers'],
                        $response
                    );

                }),
                new Logger(LogLevel::DEBUG),
                (new Amp\Http\Server\Options())
                    ->withCompression()
                    ->withBodySizeLimit(30*1024*1024)
            );

            yield $server->start();

            // Stop the server gracefully when SIGINT is received.
            // This is technically optional, but it is best to call Server::stop().
            if (defined(SIGINT)) {
                Amp\Loop::onSignal(SIGINT, static function (string $watcherId) use ($server) {
                    Amp\Loop::cancel($watcherId);
                    yield $server->stop();
                    exit;
                });
            }
        });

    }

    /**
     * Установить конфигурацию для http-сервера
     *
     * @param array $config
     * @return Server
     */
    private function setConfig(array $config = []): self
    {
        $config =  array_filter($config);

        $this->config = array_merge(
            Config::getInstance()->get("server", []),
            $config
        );

        return $this;
    }

    public function resolvePromise(&$promise) {
        if ($promise instanceof Promise) {
            return yield $promise;
        }

        return yield;
    }

}