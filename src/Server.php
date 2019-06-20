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
                Socket\listen("{$this->config['server']['address']}:{$this->config['server']['port']}"),
            ];

            $server = new Amp\Http\Server\Server($sockets, new CallableRequestHandler(function (Request $request) use($client) {
                //На каждый запрос должны создаваться новые экземпляры классов парсера и коллбеков,
                //иначе их данные будут в области видимости всех запросов.

                //Телеграм клиент инициализируется 1 раз и используется во всех запросах.

                $body = yield $request->getBody()->read();

                $requestCallback = new RequestCallback($client, $request, $body);

                try {
                    if ($requestCallback->page['response'] instanceof Promise) {
                        $requestCallback->page['response'] = yield $requestCallback->page['response'];
                    }
                } catch (\Throwable $e) {
                    $requestCallback->setError($e);
                }

                return new Response(
                    $requestCallback->page['code'],
                    $requestCallback->page['headers'],
                    $requestCallback->getResponse()
                );


            }), new Logger(LogLevel::DEBUG, 'php://stdout'));

            yield $server->start();

            // Stop the server gracefully when SIGINT is received.
            // This is technically optional, but it is best to call Server::stop().
            Amp\Loop::onSignal(SIGINT, static function (string $watcherId) use ($server) {
                Amp\Loop::cancel($watcherId);
                yield $server->stop();
                exit;
            });
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
        $config = [
            'server' => array_filter($config)
        ];

        foreach (['server', 'options'] as $key) {
            $this->config[$key] = array_merge(
                Config::getInstance()->get("swoole.{$key}", []),
                $config[$key] ?? []
            );
        }

        return $this;
    }

    public function resolvePromise(&$promise) {
        if ($promise instanceof Promise) {
            return yield $promise;
        }

        return yield;
    }

}