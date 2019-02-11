<?php

namespace TelegramSwooleClient;

class RequestCallback
{

    private $client;
    private const PAGES = ['index', 'api'];
    /** @var string */
    private $indexMessage;
    /** @var array  */
    private $ipWhiteList;
    private $path = [];
    public $page = [
        'headers' => [
            'Content-Type', 'application/json;charset=utf-8'
        ],
        'success' => 0,
        'errors' => [],
        'code'  => 200,
        'response' => null,
    ];
    private $parameters = [];
    private $api;


    /**
     * RequestCallback constructor.
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @param Client $client
     */
    public function __construct(\Swoole\Http\Request $request, \Swoole\Http\Response $response, Client $client)
    {
        $this->ipWhiteList = (array) Config::getInstance()->get('api.ip_whitelist', []);
        $this->indexMessage = (string) Config::getInstance()->get('api.index_message', 'Welcome to telegram client!');
        $this->client = $client;

        $this->parsePost($request)
            ->resolvePage($request->server['request_uri'])
            ->resolveRequest((array)$request->get, (array)$request->post)
            ->generateResponse($request);

        $result = $this->encodeResponse();

        $response->header(...$this->page['headers']);
        $response->status($this->page['code']);
        $response->end($result);
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
        } elseif (!in_array($this->path[0],self::PAGES, true)) {
            $this->setPageCode(404);
            $this->page['errors'][] = 'Incorrect path';
        }

        return $this;
    }

    /**
     * @param array $get
     * @param array $post
     * @return RequestCallback
     */
    private function resolveRequest(array $get, array $post):self {
        $this->parameters = array_values(array_merge($get, $post));
        $this->api = $this->path[1] ?? '';
        return $this;
    }

    /**
     * Получает посты для формирования ответа
     *
     * @param \Swoole\Http\Request $request
     * @return RequestCallback
     */
    private function generateResponse(\Swoole\Http\Request $request): self
    {
        if ($this->page['code'] !== 200) {
            return $this;
        }

        try {
            $this->page['response'] = $this->callApi($request);
        } catch (\Exception $e) {
            $this->setPageCode(400);
            $this->page['errors'][] = [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ];
        }

        return $this;
    }

    private function callApi(\Swoole\Http\Request $request){
        if (!in_array($request->server['remote_addr'], $this->ipWhiteList, true)) {
            throw new \Exception('API not available');
        }

        if (method_exists($this->client,$this->api)){
            return $this->client->{$this->api}(...$this->parameters);
        }

        //Проверяем нет ли в MadilineProto такого метода.
        $this->api = explode('.', $this->api);
        switch (count($this->api)){
            case 1:
                return $this->client->MadelineProto->{$this->api[0]}(...$this->parameters);
                break;
            case 2:
                return $this->client->MadelineProto->{$this->api[0]}->{$this->api[1]}(...$this->parameters);
                break;
            case 3:
                return $this->client->MadelineProto->{$this->api[0]}->{$this->api[1]}->{$this->api[3]}(...$this->parameters);
                break;
            default:
                throw new \Exception('Incorrect method format');
        }
    }


    /**
     * Кодирует ответ в нужный формат: json
     *
     * @return string
     */
    public function encodeResponse(): string
    {
        $data = [
            'success' => $this->page['success'],
            'errors' => $this->page['errors'],
            'response' => $this->page['response']
        ];
        if (!$data['errors']) {
	        $data['success'] = 1;
        }

        $result = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

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

    /**
     * Парсит json из post запроса в массив
     *
     * @param \Swoole\Http\Request $request
     * @return RequestCallback
     */
    private function parsePost(\Swoole\Http\Request $request): self
    {
        if (empty($request->post)) {
            return $this;
        }

        if (
            array_key_exists('content-type', $request->header) &&
            stripos($request->header['content-type'], 'application/json') !== false
        ) {
            $request->post = json_decode($request->rawcontent(), true);
        }

        return $this;
    }
}