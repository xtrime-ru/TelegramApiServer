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

class ApiController extends AbstractApiController
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

        //GROUP REQUESTS IN BULKS
        static $futures = [];

        $futures[] = $future = async($this->callApiCommon(...), $madelineProto);
        delay($this->waitNextTick());

        if ($futures) {
            awaitAll($futures);
            Logger::getInstance()->notice("Executed bulk requests:" . count($futures));
            $futures = [];
        }

        return $future->await();
    }

    /**
     * Sync threads execution via time ticks
     * Need to enable madelineProto futures bulk execution
     * @param float $tick interval of execution in seconds.
     */
    protected function waitNextTick(float $tick = 0.5): float {
        $tickMs = $tick * 1000;
        $now = (int)(microtime(true) * 1000);
        $currentTick = intdiv((int)(microtime(true) * 1000), $tickMs);
        $nextTick = ($currentTick + 1);
        $nextTickTime = $nextTick * $tickMs;
        $wait = round(($nextTickTime - $now)/1000, 3);

        Logger::getInstance()->notice("Waiting $wait seconds before tick");

        return $wait;
    }

}