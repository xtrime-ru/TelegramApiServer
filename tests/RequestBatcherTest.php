<?php

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use PHPUnit\Framework\TestCase;
use TelegramApiServer\RequestBatcher;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

final class RequestBatcherTest extends TestCase
{
    public function testFullBatchesStartWithoutWaitingForInterval(): void
    {
        $batcher = new RequestBatcher(10, 60, 10);
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
        $batcher->stop();
    }

    public function testFiveBatchesRunUntilEveryRequestInOneBatchFinishes(): void
    {
        $batcher = new RequestBatcher(10, 60);
        $started = [];
        $requests = [];
        $gates = array_map(static fn () => new DeferredFuture, range(0, 99));
        foreach ($gates as $i => $gate) {
            $requests[] = async(static function () use ($batcher, $i, $gate, &$started): void {
                $batcher->execute(static function () use ($i, $gate, &$started): void {
                    $started[] = $i;
                    $gate->getFuture()->await();
                });
            });
        }
        delay(0.001);
        self::assertSame(range(0, 49), $started);
        for ($i = 0; $i < 9; ++$i) {
            $gates[$i]->complete();
        }
        delay(0.001);
        self::assertCount(50, $started);
        $gates[9]->complete();
        delay(0.001);
        self::assertSame(range(0, 59), $started);
        for ($i = 10; $i < 100; ++$i) {
            $gates[$i]->complete();
        }
        await($requests, new \Amp\TimeoutCancellation(1));
        self::assertSame(range(0, 99), $started);
        $batcher->stop();
    }

    public function testPartialBatchStartsOnDeadline(): void
    {
        $batcher = new RequestBatcher(10, 0.02);
        $start = hrtime(true);
        self::assertSame('result', $batcher->execute(static fn () => 'result'));
        self::assertGreaterThanOrEqual(0.018, (hrtime(true) - $start) / 1e9);
        $batcher->stop();
    }

    public function testFailedRequestFreesBatchSlotAndPropagatesError(): void
    {
        $batcher = new RequestBatcher(1, 60, 1);
        $gate = new DeferredFuture;
        $first = async(fn () => $batcher->execute(static function () use ($gate): void {
            $gate->getFuture()->await();
            throw new RuntimeException('failed');
        }));
        $second = async(fn () => $batcher->execute(static fn () => 'next'));
        delay(0.001);
        self::assertFalse($second->isComplete());
        $gate->complete();
        try {
            $first->await();
            self::fail('Expected request error');
        } catch (RuntimeException $error) {
            self::assertSame('failed', $error->getMessage());
        }
        self::assertSame('next', $second->await(new \Amp\TimeoutCancellation(1)));
        $batcher->stop();
    }

    public function testZeroIntervalDisablesQueueAndConcurrencyLimit(): void
    {
        $batcher = new RequestBatcher(10, 0.0, 1);
        $gate = new DeferredFuture;
        $started = [];
        $requests = [];
        for ($i = 0; $i < 100; ++$i) {
            $requests[] = async(static function () use ($batcher, $gate, $i, &$started): int {
                return $batcher->execute(static function () use ($gate, $i, &$started): int {
                    $started[] = $i;
                    $gate->getFuture()->await();
                    return $i;
                });
            });
        }
        delay(0.001);
        try {
            self::assertSame(range(0, 99), $started);
        } finally {
            $gate->complete();
        }
        self::assertSame(range(0, 99), await($requests, new \Amp\TimeoutCancellation(1)));
        $batcher->stop();
    }

    public function testSlowFirstBatchDoesNotBlockReuseOfAnotherSlot(): void
    {
        $batcher = new RequestBatcher(1, 60, 2);
        $gate = new DeferredFuture;
        $first = async(fn () => $batcher->execute(fn () => $gate->getFuture()->await()));
        $second = async(fn () => $batcher->execute(static fn () => 'second'));
        $third = async(fn () => $batcher->execute(static fn () => 'third'));
        try {
            self::assertSame('second', $second->await(new \Amp\TimeoutCancellation(1)));
            self::assertSame('third', $third->await(new \Amp\TimeoutCancellation(1)));
            self::assertFalse($first->isComplete());
        } finally {
            $gate->complete();
            $first->await();
            $batcher->stop();
        }
    }

    public function testStopFlushesPartialBatchAndDrainsAcceptedRequests(): void
    {
        $batcher = new RequestBatcher(2, 60, 1);
        $gate = new DeferredFuture;
        $started = [];
        $requests = [];
        for ($i = 0; $i < 3; ++$i) {
            $requests[] = async(static function () use ($batcher, $gate, $i, &$started): int {
                return $batcher->execute(static function () use ($gate, $i, &$started): int {
                    $started[] = $i;
                    $gate->getFuture()->await();
                    return $i;
                });
            });
        }
        delay(0.001);
        $stop = async($batcher->stop(...));
        delay(0.001);
        self::assertSame([0, 1], $started);
        self::assertFalse($stop->isComplete());
        try {
            $batcher->execute(static fn () => self::fail('New work must be rejected'));
            self::fail('Expected stopped queue error');
        } catch (RuntimeException $error) {
            self::assertSame(503, $error->getCode());
        } finally {
            $gate->complete();
        }
        $stop->await(new \Amp\TimeoutCancellation(1));
        self::assertSame([0, 1, 2], await($requests));
        self::assertSame([0, 1, 2], $started);
        $batcher->stop();
    }

    public function testUnusedQueueCanBeStoppedRepeatedly(): void
    {
        $batcher = new RequestBatcher(10, 60);
        $batcher->stop();
        $batcher->stop();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(503);
        $batcher->execute(static fn () => null);
    }

    public function testRequestsAfterPartialBatchDeadlineAreNotLost(): void
    {
        $batcher = new RequestBatcher(10, 0.01);
        self::assertSame('first', $batcher->execute(static fn () => 'first'));
        self::assertSame('second', $batcher->execute(static fn () => 'second'));
        $batcher->stop();
    }

    public function testCancellationBeforeBatchIsReadySkipsOnlyCancelledRequest(): void
    {
        $batcher = new RequestBatcher(2, 60);
        $cancellation = new DeferredCancellation;
        $called = false;
        $cancelled = async(static function () use ($batcher, $cancellation, &$called): void {
            $batcher->execute(static function () use (&$called): void {
                $called = true;
            }, $cancellation->getCancellation());
        });
        delay(0.001);
        $cancellation->cancel();
        $other = async(fn () => $batcher->execute(static fn () => 'other'));
        try {
            $cancelled->await(new \Amp\TimeoutCancellation(1));
            self::fail('Expected cancellation');
        } catch (CancelledException) {
            self::assertFalse($called);
        }
        self::assertSame('other', $other->await(new \Amp\TimeoutCancellation(1)));
        $batcher->stop();
        self::assertFalse($called);
    }

    public function testCancellationWhileWaitingForBatchSlotSkipsRequest(): void
    {
        $batcher = new RequestBatcher(1, 60, 1);
        $gate = new DeferredFuture;
        $cancellation = new DeferredCancellation;
        $called = false;
        $first = async(fn () => $batcher->execute(fn () => $gate->getFuture()->await()));
        $cancelled = async(static function () use ($batcher, $cancellation, &$called): void {
            $batcher->execute(static function () use (&$called): void {
                $called = true;
            }, $cancellation->getCancellation());
        });
        delay(0.001);
        $cancellation->cancel();
        $gate->complete();
        $first->await();
        try {
            $cancelled->await(new \Amp\TimeoutCancellation(1));
            self::fail('Expected cancellation');
        } catch (CancelledException) {
            self::assertFalse($called);
        }
        $batcher->stop();
        self::assertFalse($called);
    }
}
