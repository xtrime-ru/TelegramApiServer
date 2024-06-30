<?php declare(strict_types=1);

namespace TelegramApiServer\Controllers;

use Exception;
use TelegramApiServer\Client;

final class SystemController extends AbstractApiController
{
    /**
     * Получаем параметры из uri.
     *
     *
     */
    protected function resolvePath(array $path): void
    {
        $this->api = \explode('.', $path['method'] ?? '');
    }

    /**
     * @throws Exception
     */
    protected function callApi()
    {
        $madelineProtoExtensions = new $this->extensionClass(Client::getInstance());
        $result = $madelineProtoExtensions->{$this->api[0]}(...$this->parameters);
        return $result;
    }

}
