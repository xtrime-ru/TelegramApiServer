<?php

namespace TelegramApiServer;

use Amp\DeferredFuture;
use InvalidArgumentException;
use Revolt\EventLoop;

/** Starts a batch when its size or deadline is reached. */
final class RequestBatcher
{
    private array $pending = [];
    private ?string $timer = null;

    public function __construct(private readonly int $size, private readonly float $interval)
    {
        if ($size < 1 || !is_finite($interval) || $interval < 0) {
            throw new InvalidArgumentException('Bulk size must be positive; interval finite and nonnegative');
        }
    }

    public function execute(callable $request): mixed
    {
        $deferred = new DeferredFuture;
        $this->pending[] = $deferred;
        if (count($this->pending) >= $this->size || $this->interval === 0.0) {
            $this->seal();
        } elseif ($this->timer === null) {
            $this->timer = EventLoop::delay($this->interval, $this->seal(...));
        }
        $deferred->getFuture()->await();
        return $request();
    }

    private function seal(): void
    {
        if ($this->timer !== null) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
        $batch = $this->pending;
        $this->pending = [];
        EventLoop::queue(static function () use ($batch): void {
            foreach ($batch as $deferred) {
                $deferred->complete();
            }
        });
    }
}
