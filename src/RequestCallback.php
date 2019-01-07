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
        'rss' => [
            'type' => 'xml',
            'headers' => self::HEADERS['xml'],
        ],
        'json' => [
            'type' => 'json',
            'headers' => self::HEADERS['json'],
        ],
        'index'=>[
            'type' => 'html',
            'headers' => self::HEADERS['html'],
        ],
        'error' => [
            'type' => 'json',
            'headers' => self::HEADERS['json'],
        ]
    ];
    private $path = [];
    public $page = [
        'type' => '',
        'headers' => [],
        'success' => 0,
        'errors' => [],
        'code'  => 200,
        'response' => null,
    ];
    private $channels = [];

    /**
     * RequestCallback constructor.
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @param Parser $parser
     */
    public function __construct(\Swoole\Http\Request $request, \Swoole\Http\Response $response, Parser $parser)
    {
        $this->parser = $parser;

        $this->parsePost($request)
            ->resolvePage($request->server['request_uri'])
            ->resolveChannels($request->post)
            ->generateResponse();

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

        return $this;
    }

    /**
     * Формирует список каналов из get и post запросов
     *
     * @param $post
     * @return RequestCallback
     */
    private function resolveChannels($post): self
    {
        $this->channels = [];

        if (count($this->path) === 2) {
            $this->channels[] = [
                'peer' => $this->path[1]
            ];
        } elseif ($post && array_key_exists('getHistory', $post) && is_array($post['getHistory'])) {
            $this->channels = $post['getHistory'] ?? [];
        }

        return $this;
    }

    /**
     * Получает посты для формирования ответа
     *
     * @return RequestCallback
     */
    private function generateResponse(): self
    {
        if ($this->page['code'] !== 200) {
            $this->generateErrorResponse();
            return $this;
        }

        try {
            $this->page['response'] = $this->parser->getHistory($this->channels);
        } catch (\Exception $e) {
            $this->setPageCode(400);
            $this->page['errors'][] = $e->getMessage();
        }

        if ($this->page['code'] !== 200) {
            $this->generateErrorResponse();
        }

        return $this;
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
            switch ($this->page['type']) {
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