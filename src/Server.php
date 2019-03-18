<?php

namespace TelegramSwooleClient;

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

        $http_server = new \swoole_http_server(
            $this->config['server']['address'],
            $this->config['server']['port'],
            SWOOLE_BASE
        );

        $http_server->set($this->config['options']);

        $http_server->on('request', function (\Swoole\Http\Request $request, \Swoole\Http\Response $response) use ($client, $http_server) {
            //На каждый запрос должны создаваться новые экземпляры классов парсера и коллбеков,
            //иначе их данные будут в области видимости всех запросов.

            //Телеграм клиент инициализируется 1 раз и используется во всех запросах.
            new RequestCallback($request, $response, $client, $http_server);
        });
        $http_server->start();

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

}