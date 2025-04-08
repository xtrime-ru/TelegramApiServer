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
use InvalidArgumentException;
use JsonException;
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
                        $params[$field->getName()] = $field;
                        if ($field->getName() === 'file') {
                            $params['fileName'] = $field->getFilename();
                            $params['mimeType'] = $field->getMimeType();
                        }
                    } else {
                        $params[$field->getName()] = $field->buffer();
                    }
                }
                break;
            case $contentType === 'application/json':
                $body = $request->getBody()->buffer();
                $params += \json_decode($body, true);
                break;
            default:
                \parse_str($request->getBody()->buffer(), $post);
                $params += $post;
        }
        if (isset($params['data']) && is_array($params['data'])) {
            $params += $params['data'];
            unset($params['data']);
        }
        if (isset($data['parseMode'])) {
            $data['parseMode'] = ParseMode::from($data['parseMode']);
        }

        foreach (['file', 'thumb'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = self::getMadelineMediaObject($data[$key]);
            }
        }

        $params['request'] = $request;
        return $params;

    }
    private static function getMadelineMediaObject(string |array | StreamedField | null $input): RemoteUrl|BotApiFileId|ReadableBuffer|null
    {
        if (is_array($input) && !empty($input['_'])) {
            return match ($input['_']) {
                'LocalFile' => new LocalFile(...$input),
                'RemoteUrl' => new RemoteUrl(...$input),
                'BotApiFieldId' => new BotApiFileId(...$input),
                default => throw new InvalidArgumentException("Unknown type: {$input['_']}"),
            };
        }
        if (is_string($input)) {
            return new ReadableBuffer($input);
        }
        return $input;
    }
}