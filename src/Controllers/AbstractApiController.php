<?php

namespace TelegramApiServer\Controllers;

use Amp\ByteStream\ReadableBuffer;
use Amp\Future;
use Amp\Http\HttpStatus;
use Amp\Http\Server\FormParser\StreamedField;
use Amp\Http\Server\FormParser\StreamingFormParser;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use danog\MadelineProto\API;
use danog\MadelineProto\BotApiFileId;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\ParseMode;
use danog\MadelineProto\RemoteUrl;
use JsonException;
use PhpParser\Node\Scalar\MagicConst\File;
use TelegramApiServer\Client;
use TelegramApiServer\Exceptions\NoticeException;
use TelegramApiServer\Logger;
use TelegramApiServer\MadelineProtoExtensions\ApiExtensions;
use TelegramApiServer\MadelineProtoExtensions\SystemApiExtensions;
use Throwable;
use UnexpectedValueException;

abstract class AbstractApiController
{
    public const JSON_HEADER = ['Content-Type' => 'application/json;charset=utf-8'];

    abstract protected function callApi(Request $request);

    public static function getRouterCallback(): ClosureRequestHandler
    {
        $cb = new static();
        return new ClosureRequestHandler(static function (Request $request) use ($cb) {
            try {
                $response = $cb->callApi($request);
                if ($response instanceof Future) {
                    $response = $response->await();
                }
                if ($response instanceof Response) {
                    return $response;
                }
                return new Response(
                    HttpStatus::OK,
                    self::JSON_HEADER,
                    \json_encode(
                        [
                            'success' => true,
                            'response' => $response,
                            'errors' => []
                        ],
                        JSON_THROW_ON_ERROR |
                        JSON_INVALID_UTF8_SUBSTITUTE |
                        JSON_PARTIAL_OUTPUT_ON_ERROR |
                        JSON_PRETTY_PRINT |
                        JSON_UNESCAPED_SLASHES |
                        JSON_UNESCAPED_UNICODE
                    )."\n"
                );
            } catch (\Throwable $e) {
                if (!$e instanceof NoticeException) {
                    error($e->getMessage(), Logger::getExceptionAsArray($e));
                } else {
                    notice($e->getMessage());
                }
                $code = $e->getCode();
                return new Response(
                    $code >= 100 && $code <= 599 ? $code : 400,
                    self::JSON_HEADER,
                    \json_encode(
                        [
                            'success' => false,
                            'response' => null,
                            'errors' => [Logger::getExceptionAsArray($e)]
                        ],
                        JSON_THROW_ON_ERROR |
                        JSON_INVALID_UTF8_SUBSTITUTE |
                        JSON_PARTIAL_OUTPUT_ON_ERROR |
                        JSON_PRETTY_PRINT |
                        JSON_UNESCAPED_SLASHES |
                        JSON_UNESCAPED_UNICODE
                    )."\n"
                );
            }
        });
    }

    /**
     * Получаем параметры из GET и POST.
     *
     */
    protected function resolveRequest(Request $request): array
    {
        $query = $request->getUri()->getQuery();
        $contentType = (string) $request->getHeader('Content-Type');

        \parse_str($query, $params);

        switch (true) {
            case $contentType === 'application/x-www-form-urlencoded':
            case \str_contains($contentType, 'multipart/form-data'):
                $form = (new StreamingFormParser())->parseForm($request);

                foreach ($form as $field) {
                    if ($field->isFile()) {
                        if ($field->getName() === 'file') {
                            $params[$field->getName()] = $field;
                            $params['fileName'] = $field->getFilename();
                            $params['mimeType'] = $field->getMimeType();
                            break;
                        }
                        $params[$field->getName()] = new ReadableBuffer($field->buffer());
                    } else {
                        $params[$field->getName()] = $field->buffer();
                    }
                }
                break;
            case $contentType === 'application/json':
                $body = $request->getBody()->buffer();
                $params += (array)\json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                break;
            default:
                \parse_str($request->getBody()->buffer(), $post);
                $params += $post;
        }
        if (isset($params['data']) && is_array($params['data'])) {
            $params += $params['data'];
            unset($params['data']);
        }
        $params = self::resolveMadelineMediaObjects($params);

        if (isset($params['parseMode'])) {
            $params['parseMode'] = ParseMode::from($params['parseMode']);
        }

        foreach (['file', 'thumb'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = new ReadableBuffer($data[$key]);
            }
        }

        $params['request'] = $request;
        return $params;

    }
    private static function resolveMadelineMediaObjects(mixed $input): mixed
    {
        if (!\is_array($input)) {
            return $input;
        }

        foreach ($input as $k => $v) {
            $input[$k] = self::resolveMadelineMediaObjects($v);
        }

        if (\array_key_exists('_', $input)) {
            return self::getMadelineMediaObject($input);
        }

        return $input;
    }

    private static function getMadelineMediaObject(mixed $input): mixed
    {
        $types = [
            'LocalFile' => LocalFile::class,
            'RemoteUrl' => RemoteUrl::class,
            'BotApiFieldId' => BotApiFileId::class,
            'BotApiFileId' => BotApiFileId::class,
        ];
        if (\is_array($input) && !empty($input['_']) && array_key_exists($input['_'], $types)) {
            $class = $types[$input['_']];
            unset($input['_']);
            return new $class(...$input);
        }

        return $input;
    }
}