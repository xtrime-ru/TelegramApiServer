<?php

namespace TelegramSwooleClient;
//TODO: rss output
class RequestCallback
{

    private $parser;
    private const HEADERS = [
        'json' => ['Content-Type', 'application/json;charset=utf-8'],
        'xml' => ['Content-Type', 'application/rss+xml;charset=utf-8'],
        'html' => ['Content-Type', 'text/html; charset=utf-8'],
    ];
    private const PAGES = [
        'api' => [
            'format' => 'json',
            'headers' => self::HEADERS['json'],
        ],
        'rss' => [
            'format' => 'xml',
            'headers' => self::HEADERS['xml'],
        ],
        'json' => [
            'format' => 'json',
            'headers' => self::HEADERS['json'],
        ],
        'index'=>[
            'format' => 'html',
            'headers' => self::HEADERS['html'],
        ],
        'error' => [
            'format' => 'json',
            'headers' => self::HEADERS['json'],
        ],
    ];
    private $path = [];
    public $page = [
        'type' => '',
        'format' => '',
        'headers' => [],
        'success' => 0,
        'errors' => [],
        'code'  => 200,
        'response' => null,
    ];
    private $parameters = [];
    private $api;
    private $ipWhiteList = [];

    /**
     * RequestCallback constructor.
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @param Parser $parser
     */
    public function __construct(\Swoole\Http\Request $request, \Swoole\Http\Response $response, Parser $parser)
    {
        $this->ipWhiteList = Config::getInstance()->getConfig('api.ip_whitelist', []);
        $this->parser = $parser;

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
        if (!$this->path) {
            $this->path = ['index'];
            $this->page['response'] = 'Welcome to i-c-a.su parser for telegram. <br>' .
                "If you have questions contact me via telegram: <a href='tg://resolve?domain=xtrime'>@xtrime</a>";
        } elseif (!array_key_exists($this->path[0],self::PAGES)) {
            $this->path = ['error'];
            $this->setPageCode(404);
            $this->page['errors'][] = 'Incorrect path';
        }
        $this->page = array_merge($this->page, self::PAGES[$this->path[0]]);
        $this->page['type'] = $this->path[0];

        return $this;
    }

    /**
     * @param array $get
     * @param array $post
     * @return RequestCallback
     */
    private function resolveRequest(array $get, array $post):self {
        switch ($this->page['type']) {
            case 'api':
                return $this->getApi($get, $post);
                break;
            case 'json':
            case 'xml':
                return $this->getChannels($post);
                break;
        }
        
        return $this;
    }

    /**
     * @param array $get
     * @param array $post
     * @return $this
     */
    private function getApi(array $get, array $post) {
        $this->parameters = array_values(array_merge($get, $post));
        $this->api = $this->path[1] ?? '';
        return $this;
    }

    /**
     * Формирует список каналов из get и post запросов
     *
     * @param array $post
     * @return RequestCallback
     */
    private function getChannels(array $post): self
    {
        $this->parameters = [];

        if (count($this->path) === 2) {
            $this->parameters[] = [
                'peer' => $this->path[1]
            ];
        } elseif ($post && array_key_exists('getHistory', $post) && is_array($post['getHistory'])) {
            $this->parameters = $post['getHistory'] ?? [];
        }

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
            $this->generateErrorResponse();
            return $this;
        }

        try {
            switch ($this->page['type']) {
                case 'api':
                    $this->page['response'] = $this->callApi($request);
                    break;
                case 'json':
                case 'xml':
                    $this->page['response'] = $this->parser->getHistory($this->parameters);
                    break;
            }

        } catch (\Exception $e) {
            $this->setPageCode(400);
            $this->page['errors'][] = $e->getMessage();
        }

        if ($this->page['code'] !== 200) {
            $this->generateErrorResponse();
        }

        return $this;
    }

    private function callApi(\Swoole\Http\Request $request){
        if (!in_array($request->server['remote_addr'], $this->ipWhiteList, true)) {
            throw new \Exception('API not available');
        }

        if (method_exists($this->parser->client,$this->api)){
            return $this->parser->client->{$this->api}(...$this->parameters);
        }

        //Проверяем нет ли в madiline proto такого метода.
        $this->api = explode('.', $this->api);
        switch (count($this->api)){
            case 1:
                return $this->parser->client->MadelineProto->{$this->api[0]}(...$this->parameters);
                break;
            case 2:
                return $this->parser->client->MadelineProto->{$this->api[0]}->{$this->api[1]}(...$this->parameters);
                break;
            case 3:
                return $this->parser->client->MadelineProto->{$this->api[0]}->{$this->api[1]}->{$this->api[3]}(...$this->parameters);
                break;
            default:
                throw new \Exception('Incorect api format');
        }
    }

    private function generateErrorResponse(): self
    {
        $this->page = array_merge($this->page,self::PAGES['error']);
        return $this;
    }

    /**
     * Кодирует ответ в нужный формат: json, rss, html
     *
     * @return string
     */
    public function encodeResponse(): string
    {
        $result = '';
        $data = [
            'success' =>$this->page['success'],
            'errors' =>$this->page['errors'],
            'response' => $this->page['response']
        ];
        try {
            switch ($this->page['format']) {
                case 'json':
                    $result = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    break;
                case 'xml':
                    throw new \Exception('RSS not supported yet. Coming soon.');
                    break;
                case 'html':
                    if ($this->page['code'] !== 200) {
                        $result = 'ERRORS: <br>' .
                            '<pre>' .
                            json_encode($this->page['errors'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
                            '</pre>';
                    } else {
                        $result = $this->page['response'];
                    }
                    break;
            }
        } catch (\Exception $e) {
            $this->setPageCode(400);
            $this->page['errors'][] = $e->getMessage();
            $result = 'Errors: ' . implode(', ', $this->page['errors']);
        }

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
        if (!$request->post) {
            return $this;
        }

        if (
            array_key_exists('content-type', $request->header) &&
            stripos($request->header['content-type'], 'application/json') !== false
        ) {
            $request->post = json_decode($request->rawcontent(), true);
        } elseif (array_key_exists('getHistory', $request->post) && is_string($request->post['getHistory'])) {
            $request->post['getHistory'] = json_decode($request->post['getHistory'],true);
        }

        return $this;
    }
}