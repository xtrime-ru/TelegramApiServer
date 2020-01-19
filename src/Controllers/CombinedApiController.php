<?php

namespace TelegramApiServer\Controllers;

use Amp\Promise;

class CombinedApiController extends AbstractApiController
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
        $madelineProto = $this->client->getCombinedInstance();
        return $this->callApiCommon($madelineProto);
    }

}