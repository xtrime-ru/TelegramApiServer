<?php

use PHPUnit\Framework\TestCase;
use TelegramApiServer\RequestBatcher;

use function Amp\async;
use function Amp\Future\await;

final class RequestBatcherTest extends TestCase
{
    public function testFullBatchesStartWithoutWaitingForInterval(): void
    {
        $batcher = new RequestBatcher(10, 60);
        $started = [];
        $requests = [];
        for ($i = 0; $i < 100; ++$i) {
            $requests[] = async(static function () use ($batcher, $i, &$started): void {
                $batcher->execute(static function () use ($i, &$started): void {
                    $started[] = $i;
                });
            });
        }
        await($requests, new \Amp\TimeoutCancellation(1));

        self::assertSame(range(0, 99), $started);
    }

    public function testPartialBatchStartsOnDeadline(): void
    {
        $batcher = new RequestBatcher(10, 0.02);
        $start = hrtime(true);
        self::assertSame('result', $batcher->execute(static fn () => 'result'));
        self::assertGreaterThanOrEqual(0.018, (hrtime(true) - $start) / 1e9);
    }

    public function testZeroIntervalStartsPartialBatchImmediately(): void
    {
        $batcher = new RequestBatcher(10, 0.0);
        self::assertSame('immediate', $batcher->execute(static fn () => 'immediate'));
    }
}
