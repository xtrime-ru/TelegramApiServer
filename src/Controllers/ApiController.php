<?php

namespace TelegramApiServer\Controllers;

use Amp\Sync\LocalKeyedMutex;
use Amp\Sync\LocalMutex;
use Amp\Sync\StaticKeyMutex;
use Amp\Sync\SyncException;
use Exception;
use Revolt\EventLoop;
use TelegramApiServer\Client;
use TelegramApiServer\Config;
use TelegramApiServer\Logger;
use function Amp\async;
use function Amp\delay;
use function Amp\Future\awaitAll;

final class ApiController extends AbstractApiController
{

    private ?string $session = '';

    /**
     * Получаем параметры из uri
     *
     * @param array $path
     *
     */
    protected function resolvePath(array $path): void
    {
        $this->session = $path['session'] ?? null;
        $this->api = explode('.', $path['method'] ?? '');
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