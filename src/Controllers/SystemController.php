<?php

namespace TelegramApiServer\Controllers;

use Amp\Promise;

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
     * @return mixed|Promise
     * @throws \Exception
     */
    protected function callApi()
    {
        $madelineProtoExtensions = new $this->extensionClass($this->client);
        $result = $madelineProtoExtensions->{$this->api[0]}(...$this->parameters);
        return $result;
    }

}