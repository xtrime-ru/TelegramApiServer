<?php

namespace TelegramApiServer\Controllers;

use Amp\Future;
use Amp\Http\Server\FormParser\StreamedField;
use Amp\Http\Server\FormParser\StreamingParser;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use danog\MadelineProto\API;
use JsonException;
use TelegramApiServer\Client;
use TelegramApiServer\Exceptions\NoticeException;
use TelegramApiServer\Logger;
use TelegramApiServer\MadelineProtoExtensions\ApiExtensions;
use TelegramApiServer\MadelineProtoExtensions\SystemApiExtensions;
use Throwable;
use UnexpectedValueException;
use function mb_strpos;

abstract class AbstractApiController
{
    public const JSON_HEADER = ['Content-Type' => 'application/json;charset=utf-8'];

    protected Request $request;
    protected ?StreamedField $file = null;
    protected string $extensionClass;


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

    public static function getRouterCallback(string $extensionClass): ClosureRequestHandler
    {
        return new ClosureRequestHandler(
            static function (Request $request) use ($extensionClass) {
                $requestCallback = new static($request, $extensionClass);
                $response = $requestCallback->process();

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

    public function __construct(Request $request, string $extensionClass)
    {
        $this->request = $request;
        $this->extensionClass = $extensionClass;
    }

    /**
     * @return Response|string
     * @throws Throwable
     */
    public function process()
    {
        $this->resolvePath($this->request->getAttribute(Router::class));
        $this->resolveRequest();
        $this->generateResponse();

        return $this->getResponse();
    }

    /**
     * Получаем параметры из GET и POST
     *
     */
    private function resolveRequest(): void
    {
        $query = $this->request->getUri()->getQuery();
        $contentType = (string)$this->request->getHeader('Content-Type');

        parse_str($query, $get);

        switch (true) {
            case $contentType === 'application/x-www-form-urlencoded':
            case mb_strpos($contentType, 'multipart/form-data') !== false:
                $form = (new StreamingParser())->parseForm($this->request);
                $post = [];

                while ($form->continue()) {
                    /** @var StreamedField $field */
                    $field = $form->getValue();
                    if ($field->isFile()) {
                        $this->file = $field;
                        //We need to break loop without getting file
                        //All other post field will be omitted, hope we dont need them :)
                        break;
                    } else {
                        $post[$field->getName()] = $field->buffer();
                    }
                }
                break;
            case $contentType === 'application/json':
                $body = $this->request->getBody()->buffer();
                $post = json_decode($body, 1);
                break;
            default:
                $body = $this->request->getBody()->buffer();
                parse_str($body, $post);
        }

        $this->parameters = array_merge((array)$post, $get);
        $this->parameters = array_values($this->parameters);

    }

    /**
     * Получает посты для формирования ответа
     *
     */
    private function generateResponse(): void
    {
        if ($this->page['code'] !== 200) {
            return;
        }
        if (!$this->api) {
            return;
        }

        try {
            $this->page['response'] = $this->callApi();

            if ($this->page['response'] instanceof Future) {
                $this->page['response'] = $this->page['response']->await();
            }

        } catch (Throwable $e) {
            if (!$e instanceof NoticeException) {
                error($e->getMessage(), Logger::getExceptionAsArray($e));
            } else {
                notice($e->getMessage());
            }
            $this->setError($e);
        }

    }

    protected function callApiCommon(API $madelineProto)
    {
        $pathCount = count($this->api);
        if ($pathCount === 1 && method_exists($this->extensionClass, $this->api[0])) {
            /** @var ApiExtensions|SystemApiExtensions $madelineProtoExtensions */
            $madelineProtoExtensions = new $this->extensionClass($madelineProto, $this->request, $this->file);
            $result = $madelineProtoExtensions->{$this->api[0]}(...$this->parameters);
        } else {
            if ($this->api[0] === 'API') {
                $madelineProto = Client::getWrapper($madelineProto)->getAPI();
                array_shift($this->api);
                $pathCount = count($this->api);
            }
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
                    throw new UnexpectedValueException('Incorrect method format');
            }
        }

        return $result;
    }

    /**
     * @param Throwable $e
     *
     * @return AbstractApiController
     * @throws Throwable
     */
    private function setError(Throwable $e): self
    {
        $errorCode = $e->getCode();
        if ($errorCode >= 400 && $errorCode < 500) {
            $this->setPageCode($errorCode);
        } else {
            $this->setPageCode(400);
        }

        $this->page['errors'][] = Logger::getExceptionAsArray($e);

        return $this;
    }

    /**
     * Кодирует ответ в нужный формат: json
     *
     * @return Response|string
     * @throws JsonException
     */
    private function getResponse(): string|Response
    {
        if ($this->page['response'] instanceof Response) {
            return $this->page['response'];
        }

        if (!is_array($this->page['response']) && !is_scalar($this->page['response'])) {
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
            JSON_PARTIAL_OUTPUT_ON_ERROR |
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