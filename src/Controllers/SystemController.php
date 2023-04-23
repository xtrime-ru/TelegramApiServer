<?php

namespace TelegramApiServer\Controllers;

use Exception;
use TelegramApiServer\Client;

class SystemController extends AbstractApiController
{
    /**
     * Получаем параметры из uri
     *
     * @param array $path
     *
     */
    protected function resolvePath(array $path): void
    {
        $this->api = explode('.', $path['method'] ?? '');
    }

    /**
     * @return mixed
     * @throws Exception
     */
    protected function callApi()
    {
        $madelineProtoExtensions = new $this->extensionClass(Client::getInstance());
        $result = $madelineProtoExtensions->{$this->api[0]}(...$this->parameters);
        return $result;
    }

}