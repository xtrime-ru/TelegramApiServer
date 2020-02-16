<?php

namespace TelegramApiServer\Controllers;

use Amp\ByteStream\ResourceInputStream;
use Amp\Http\Server\FormParser\BufferingParser;
use Amp\Http\Server\FormParser\File;
use Amp\Http\Server\FormParser\Form;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Promise;
use danog\MadelineProto\API;
use danog\MadelineProto\CombinedAPI;
use danog\MadelineProto\TON\API as TonAPI;
use TelegramApiServer\Client;
use TelegramApiServer\MadelineProtoExtensions\ApiExtensions;
use TelegramApiServer\MadelineProtoExtensions\SystemApiExtensions;

abstract class AbstractApiController
{
    public const JSON_HEADER = ['Content-Type'=>'application/json;charset=utf-8'];

    protected Client $client;
    protected Request $request;
    protected ?File $file = null;
    protected $extensionClass;


    public array $page = [
        'headers' => self::JSON_HEADER,
        'success' => false,
        'errors' => [],
        'code' => 200,
        'response' => null,
    ];
    protected array $parameters = [];
    protected array $api;

    abstract protected function resolvePath(array $path);
    abstract protected function callApi();

    public static function getRouterCallback(Client $client, $extensionClass): CallableRequestHandler
    {
        return new CallableRequestHandler(
            static function (Request $request) use($client, $extensionClass) {
                $requestCallback = new static($client, $request, $extensionClass);
                $response = yield from $requestCallback->process();

                if ($response instanceof Response) {
                    return $response;
                }
                return new Response(
                    $requestCallback->page['code'],
                    $requestCallback->page['headers'],
                    $response
                );
            }
        );
    }

    public function __construct(Client $client, Request $request, $extensionClass = null)
    {
        $this->client = $client;
        $this->request = $request;
        $this->extensionClass = $extensionClass;
    }

    /**
     * @param Request $request
     * @return ResourceInputStream|string
     * @throws \Throwable
     */
    public function process()
    {
        $this->resolvePath($this->request->getAttribute(Router::class));
        yield from $this->resolveRequest();
        yield from $this->generateResponse();

        return $this->getResponse();
    }

    /**
     * Получаем параметры из GET и POST
     *
     * @return AbstractApiController
     */
    private function resolveRequest()
    {
        $query = $this->request->getUri()->getQuery();
        $contentType = $this->request->getHeader('Content-Type');

        parse_str($query, $get);

        switch (true) {
            case $contentType === 'application/x-www-form-urlencoded':
            case mb_strpos($contentType, 'multipart/form-data') !== false:
                /** @var Form $form */
                $form = yield (new BufferingParser())->parseForm($this->request);
                $post = $form->getValues();
                $fileName = array_key_first($form->getFiles());
                if ($fileName) {
                    $this->file = $form->getFile($fileName);
                }
                break;
            case $contentType === 'application/json':
                $body = yield $this->request->getBody()->buffer();
                $post = json_decode($body, 1);
                break;
            default:
                $body = yield $this->request->getBody()->buffer();
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
     * @return void|\Generator
     * @throws \Throwable
     */
    private function generateResponse()
    {
        if ($this->page['code'] !== 200) {
            return;
        }
        if (!$this->api) {
            return;
        }

        try {
            $this->page['response'] = $this->callApi();

            if ($this->page['response'] instanceof Promise) {
                $this->page['response'] = yield $this->page['response'];
            }

        } catch (\Throwable $e) {
            error($e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $this->setError($e);
        }

    }

    /**
     * @param CombinedAPI|API|TonAPI $madelineProto
     *
     * @return mixed
     */
    protected function callApiCommon($madelineProto)
    {
        $pathCount = count($this->api);
        if ($pathCount === 1 && $this->extensionClass && is_callable([$this->extensionClass,$this->api[0]])) {
            /** @var ApiExtensions|SystemApiExtensions $madelineProtoExtensions */
            $madelineProtoExtensions = new $this->extensionClass($madelineProto, $this->request, $this->file);
            $result = $madelineProtoExtensions->{$this->api[0]}(...$this->parameters);
        } else {
            //Проверяем нет ли в MadilineProto такого метода.
            switch ($pathCount) {
                case 1:
                    $result = $madelineProto->{$this->api[0]}(...$this->parameters);
                    break;
                case 2:
                    $result = $madelineProto->{$this->api[0]}->{$this->api[1]}(...$this->parameters);
                    break;
                case 3:
                    $result = $madelineProto->{$this->api[0]}->{$this->api[1]}->{$this->api[2]}(...$this->parameters);
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
     * @return AbstractApiController
     * @throws \Throwable
     */
    private function setError(\Throwable $e): self
    {
        $errorCode = $e->getCode();
        if ($errorCode >= 400 && $errorCode < 500) {
            $this->setPageCode($errorCode);
        } else {
            $this->setPageCode(400);
        }

        $this->page['errors'][] = [
            'code' => $errorCode,
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
        if ($this->page['response'] instanceof Response) {
            return $this->page['response'];
        }

        if (!is_array($this->page['response'])) {
            $this->page['response'] = null;
        }

        $data = [
            'success' => $this->page['success'],
            'errors' => $this->page['errors'],
            'response' => $this->page['response'],
        ];
        if (!$data['errors']) {
            $data['success'] = true;
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
     * @return AbstractApiController
     */
    private function setPageCode(int $code): self
    {
        $this->page['code'] = $this->page['code'] === 200 ? $code : $this->page['code'];
        return $this;
    }
}