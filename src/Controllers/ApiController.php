<?php declare(strict_types=1);

namespace TelegramApiServer\Controllers;

use Amp\DeferredFuture;
use Exception;
use TelegramApiServer\Client;
use TelegramApiServer\Config;
use TelegramApiServer\Logger;
use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;
use function Amp\Future\awaitAll;

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

        //GROUP REQUESTS IN BULKS
        /** @var ?DeferredFuture $lock */
        static $lock = null;

        if (!$lock) {
            try {
                $lock = new DeferredFuture();
                delay($this->waitNextTick());
                $lock->complete();
            } finally {
                $lock = null;
            }
        } else {
            $lock->getFuture()->await();
        }

        return $this->callApiCommon($madelineProto);
    }

    /**
     * Sync threads execution via time ticks
     * Need to enable madelineProto futures bulk execution
     * @param float $tick interval of execution in seconds.
     */
    protected function waitNextTick(float $tick = 0.5): float {
        $tickMs = (int)($tick * 1000);
        $now = (int)(microtime(true) * 1000);
        $currentTick = intdiv((int)(microtime(true) * 1000), $tickMs);
        $nextTick = ($currentTick + 1);
        $nextTickTime = $nextTick * $tickMs;
        $wait = round(($nextTickTime - $now)/1000, 3);

        Logger::getInstance()->notice("Waiting $wait seconds before tick");

        return $wait;
    }
}
