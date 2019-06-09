<?php

namespace TelegramApiServer;

use function Amp\call;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Promise;

class RequestCallback
{

    private $client;
    public const FATAL_MESSAGE = 'Fatal error. Exit.';
    private const PAGES = ['index', 'api'];
    /** @var string */
    private $indexMessage;
    /** @var array */
    private $ipWhiteList;
    private $path = [];
    public $page = [
        'headers' => [
            'Content-Type'=>'application/json;charset=utf-8',
        ],
        'success' => 0,
        'errors' => [],
        'code' => 200,
        'response' => null,
    ];
    private $parameters = [];
    private $api;


    /**
     * RequestCallback constructor.
     * @param Request $request
     * @param Response $response
     * @param Client $client
     * @throws \Throwable
     */
    public function __construct(Client $client, $request, $body)
    {
        $this->ipWhiteList = (array)Config::getInstance()->get('api.ip_whitelist', []);
        $this->indexMessage = (string)Config::getInstance()->get('api.index_message', '');
        $this->client = $client;

        $this
            ->resolvePage($request->getUri()->getPath())
            ->resolveRequest($request->getUri()->getQuery(), $body, $request->getHeader('Content-Type'))
            ->generateResponse($request)
        ;

    }


    /**
     * Определяет какую страницу запросили
     *
     * @param $uri
     * @return RequestCallback
     */
    private function resolvePage($uri): self
    {
        $this->path = array_values(array_filter(explode('/', $uri)));
        if (!$this->path || $this->path[0] !== 'api') {
            $this->page['response'] = $this->indexMessage;
        } elseif (!in_array($this->path[0], self::PAGES, true)) {
            $this->setPageCode(404);
            $this->page['errors'][] = 'Incorrect path';
        }

        return $this;
    }

    /**
     * @param string $query
     * @param string|array $body
     * @return RequestCallback
     * @throws \Throwable
     */
    private function resolveRequest(string $query, $body, $contentType)
    {
        parse_str($query, $get);

        switch ($contentType) {
            case 'application/json':
                $post = json_decode($body, 1);
                break;
            default:
                parse_str($body, $post);
        }

        $this->parameters = array_merge($post, $get);
        $this->parameters = array_values($this->parameters);

        $this->api = $this->path[1] ?? '';
        return $this;
    }

    /**
     * Получает посты для формирования ответа
     *
     * @param Request $request
     * @return RequestCallback
     */
    public function generateResponse(Request $request)
    {
        if ($this->page['code'] !== 200) {
            return $this;
        }
        if (!$this->api) {
            return $this;
        }

        try {
            if (!in_array($request->getClient()->getRemoteAddress(), $this->ipWhiteList, true)) {
                throw new \Exception('Requests from your IP is forbidden');
            }
        } catch (\Throwable $e) {
            $this->setError($e);
        }

        return $this;
    }

    public function callApi()
    {
        if (method_exists($this->client, $this->api)) {
            $result = $this->client->{$this->api}(...$this->parameters);
        } else {
            //Проверяем нет ли в MadilineProto такого метода.
            $this->api = explode('.', $this->api);
            switch (count($this->api)) {
                case 1:
                    $result = $this->client->MadelineProto->{$this->api[0]}(...$this->parameters);
                    break;
                case 2:
                    $result = $this->client->MadelineProto->{$this->api[0]}->{$this->api[1]}(...$this->parameters);
                    break;
                case 3:
                    $result = $this->client->MadelineProto->{$this->api[0]}->{$this->api[1]}->{$this->api[3]}(...$this->parameters);
                    break;
                default:
                    throw new \Exception('Incorrect method format');
            }
        }

        return $result;
    }

    /**
     * @param \Throwable $e
     * @return RequestCallback
     */
    public function setError(\Throwable $e): self
    {
        if ($e instanceof \Error) {
            //Это критическая ошибка соедниения. Необходим полный перезапуск.
            throw $e;
        }

        $this->setPageCode(400);
        $this->page['errors'][] = [
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
        ];

        return $this;
    }


    /**
     * Кодирует ответ в нужный формат: json
     *
     * @return string
     */
    public function getResponse()
    {
        $data = [
            'success' => $this->page['success'],
            'errors' => $this->page['errors'],
            'response' => $this->page['response'],
        ];
        if (!$data['errors']) {
            $data['success'] = 1;
        }

        $result = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $result;
    }

    /**
     * Устанавливает http код ответа (200, 400, 404 и тд.)
     *
     * @param int $code
     * @return RequestCallback
     */
    private function setPageCode(int $code): self
    {
        $this->page['code'] = $this->page['code'] === 200 ? $code : $this->page['code'];
        return $this;
    }
}