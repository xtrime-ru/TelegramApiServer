<?php

namespace TelegramApiServer\Controllers;

use Amp\ByteStream\ResourceInputStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Promise;
use TelegramApiServer\Client;
use TelegramApiServer\Config;
use TelegramApiServer\ClientCustomMethods;

class ApiController
{
    private Client $client;
    private array $ipWhiteList;
    public array $page = [
        'headers' => [
            'Content-Type'=>'application/json;charset=utf-8',
        ],
        'success' => 0,
        'errors' => [],
        'code' => 200,
        'response' => null,
    ];
    private array $parameters = [];
    private array $api;
    private ?string $session = '';

    public static function getRouterCallback($client): CallableRequestHandler
    {
        return new CallableRequestHandler(
                static function (Request $request) use($client) {
                    $requestCallback = new static($client);
                    $response = yield from $requestCallback->process($request);

                    return new Response(
                        $requestCallback->page['code'],
                        $requestCallback->page['headers'],
                        $response
                    );
                }
        );
    }

    /**
     * RequestCallback constructor.
     * @param Client $client
     * @throws \Throwable
     */
    public function __construct(Client $client)
    {
        $this->ipWhiteList = (array) Config::getInstance()->get('api.ip_whitelist', []);
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
            ->resolvePath($request->getAttribute(Router::class))
            ->resolveRequest($request->getUri()->getQuery(), $body, $request->getHeader('Content-Type'))
            ->generateResponse($request)
        ;

        return $this->getResponse();
    }

    /**
     * Получаем параметры из uri
     *
     * @param array $path
     *
     * @return ApiController
     */
    private function resolvePath(array $path): self
    {
        $this->session = $path['session'] ?? null;
        $this->api = explode('.', $path['method'] ?? '');

        return $this;
    }

    /**
     * Получаем параметры из GET и POST
     *
     * @param string $query
     * @param string|null $body
     * @param string|null $contentType
     *
     * @return ApiController
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

        return $this;
    }

    /**
     * Получает посты для формирования ответа
     *
     * @param Request $request
     *
     * @return ApiController
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
            if (!in_array($request->getClient()->getRemoteAddress()->getHost(), $this->ipWhiteList, true)) {
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
        $pathSize = count($this->api);
        if ($pathSize === 1 && is_callable([ClientCustomMethods::class,$this->api[0]])) {
            $customMethods = new ClientCustomMethods($this->client->getInstance($this->session));
            $result = $customMethods->{$this->api[0]}(...$this->parameters);
        } else {
            //Проверяем нет ли в MadilineProto такого метода.
            switch ($pathSize) {
                case 1:
                    $result = $this->client->getInstance($this->session)->{$this->api[0]}(...$this->parameters);
                    break;
                case 2:
                    $result = $this->client->getInstance($this->session)->{$this->api[0]}->{$this->api[1]}(...$this->parameters);
                    break;
                case 3:
                    $result = $this->client->getInstance($this->session)->{$this->api[0]}->{$this->api[1]}->{$this->api[2]}(...$this->parameters);
                    break;
                default:
                    throw new \UnexpectedValueException('Incorrect method format');
            }
        }

        return $result;
    }

    /**
     * @param \Throwable $e
     *
     * @return ApiController
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

        $result = json_encode(
            $data,
            JSON_THROW_ON_ERROR |
            JSON_INVALID_UTF8_SUBSTITUTE |
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );

        return $result . "\n";
    }

    /**
     * Устанавливает http код ответа (200, 400, 404 и тд.)
     *
     * @param int $code
     *
     * @return ApiController
     */
    private function setPageCode(int $code): self
    {
        $this->page['code'] = $this->page['code'] === 200 ? $code : $this->page['code'];
        return $this;
    }
}