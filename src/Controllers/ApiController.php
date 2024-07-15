<?php declare(strict_types=1);

namespace TelegramApiServer\Controllers;

use Amp\DeferredFuture;
use Amp\Future;
use Exception;
use Revolt\EventLoop;
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

    private static ?Future $w = null;
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

        if (!self::$w) {
            $f = new DeferredFuture;
            self::$w = $f->getFuture();
            EventLoop::delay(0.001, static function () use ($f): void {
                self::$w = null;
                $f->complete();
            });
        }
        self::$w->await();

        return $this->callApiCommon($madelineProto);
    }
}
