<?php declare(strict_types=1);

namespace TelegramApiServer\Controllers;

use Exception;
use TelegramApiServer\Client;
use TelegramApiServer\Config;

final class ApiController extends AbstractApiController
{

    private ?string $session = '';

    /**
     * Получаем параметры из uri.
     *
     *
     */
    protected function resolvePath(array $path): void
    {
        $this->session = $path['session'] ?? null;
        $this->api = \explode('.', $path['method'] ?? '');
    }

    /**
     * @throws Exception
     */
    protected function callApi(): mixed
    {
        $madelineProto = Client::getInstance()->getSession($this->session);
        $tick = Config::getInstance()->get('api.bulk_interval');

        if (!$tick) {
            return $this->callApiCommon($madelineProto);
        }

        return $this->callApiCommon($madelineProto);
    }
}
