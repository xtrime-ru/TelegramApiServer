<?php

namespace TelegramApiServer;

use Amp\ByteStream\ResourceInputStream;
use Amp\Http\Server\Request;
use Amp\Promise;

class RequestCallback
{

    private $client;
    private const PAGES = ['index', 'api'];
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
     * @param Client $client
     * @throws \Throwable
     */
    public function __construct(Client $client)
    {
        $this->ipWhiteList = (array)Config::getInstance()->get('api.ip_whitelist', []);
        $this->client = $client;

    }

    /**
     * @param Request $request
     * @return ResourceInputStream|string
     * @throws \Throwable
     */
    public function process(Request $request)
    {
        $body = '';
        while ($chunk = yield $request->getBody()->read()) {
            $body .= $chunk;
        }

        yield from $this
            ->resolvePage($request->getUri()->getPath())
            ->resolveRequest($request->getUri()->getQuery(), $body, $request->getHeader('Content-Type'))
            ->generateResponse($request)
        ;

        return $this->getResponse();
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
        if (!in_array($this->path[0], self::PAGES, true)) {
            $this->setPageCode(404);
            $this->page['errors'][] = 'Incorrect path';
        }

        return $this;
    }

    /**
     * @param string $query
     * @param string|null $body
     * @param string|null $contentType
     * @return RequestCallback
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

        $this->parameters = array_merge((array) $post, $get);
        $this->parameters = array_values($this->parameters);

        $this->api = $this->path[1] ?? '';
        return $this;
    }

    /**
     * Получает посты для формирования ответа
     *
     * @param Request $request
     * @return RequestCallback
     * @throws \Throwable
     */
    private function generateResponse(Request $request)
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
            $this->page['response'] = $this->callApi();

            if ($this->page['response'] instanceof Promise) {
                $this->page['response'] = yield $this->page['response'];
            }

        } catch (\Throwable $e) {
            $this->setError($e);
        }

        return $this;
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    private function callApi()
    {
        if (method_exists($this->client, $this->api)){
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
                    $result = $this->client->MadelineProto->{$this->api[0]}->{$this->api[1]}->{$this->api[2]}(...$this->parameters);
                    break;
                default:
                    throw new \UnexpectedValueException('Incorrect method format');
            }
        }

        return $result;
    }

    /**
     * @param \Throwable $e
     * @return RequestCallback
     * @throws \Throwable
     */
    private function setError(\Throwable $e): self
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
     * @return string|ResourceInputStream
     * @throws \Throwable
     */
    private function getResponse()
    {
        if (!is_array($this->page['response'])) {
            $this->page['response'] = null;
        }
        if (isset($this->page['response']['stream'])) {
            $this->page['headers'] = $this->page['response']['headers'];
            return new ResourceInputStream($this->page['response']['stream']);
        }

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