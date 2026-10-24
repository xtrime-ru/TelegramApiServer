<?php

namespace TelegramApiServer;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\DisposedException;
use Amp\Pipeline\Pipeline;
use Amp\Pipeline\Queue;
use InvalidArgumentException;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;

use function Amp\async;
use function Amp\Future\await;
use function Amp\Future\awaitFirst;

/** Starts batches on size or deadline, with a limit on batches still executing. */
final class RequestBatcher
{
    private Queue $input;
    private ?Future $worker = null;

    public function __construct(
        private readonly int $size,
        private readonly float $interval,
        int $concurrency = 5,
    ) {
        if ($size < 1 || $concurrency < 1 || !is_finite($interval) || $interval < 0) {
            throw new InvalidArgumentException('Bulk size and concurrency must be positive; interval finite and nonnegative');
        }
        if ($this->interval === 0.0) {
            return;
        }
        $this->input = new Queue;
        $input = $this->input->iterate();
        $this->worker = async(function () use ($input, $concurrency): void {
            try {
                Pipeline::fromIterable($this->group($input))
                    ->concurrent($concurrency)
                    ->unordered()
                    ->forEach($this->send(...));
            } finally {
                $input->dispose();
            }
        });
        $this->worker->catch(static function (Throwable $error): void {
            Logger::getInstance()->error('Request queue failed', ['exception' => $error]);
        });
    }

    /** Grouping stage: emit on size, deadline, or end of input. */
    private function group(ConcurrentIterator $input): \Generator
    {
        while ($input->continue()) {
            $batch = [$input->getValue()];
            if ($this->size > 1) {
                $timeout = new DeferredCancellation;
                $timer = EventLoop::delay($this->interval, static fn () => $timeout->cancel());
                try {
                    while (count($batch) < $this->size && $input->continue($timeout->getCancellation())) {
                        $batch[] = $input->getValue();
                    }
                } catch (CancelledException) {
                    // The partial batch is ready when its deadline expires.
                } finally {
                    EventLoop::cancel($timer);
                }
            }
            yield $batch;
        }
    }

    /** Processing stage: the pipeline holds one slot until the whole batch finishes. */
    private function send(array $batch): void
    {
        $requests = [];
        foreach ($batch as [$request, $deferred]) {
            $requests[] = async($request)
                ->map($deferred->complete(...))
                ->catch($deferred->error(...));
        }
        await($requests);
    }

    public function execute(callable $request, ?Cancellation $cancellation = null): mixed
    {
        $cancellation?->throwIfRequested();
        if ($this->interval === 0.0) {
            return $request();
        }
        if ($this->input->isComplete() || $this->input->isDisposed()) {
            throw new RuntimeException('Request queue is stopped', 503);
        }
        $deferred = new DeferredFuture;
        try {
            $this->input->push([static function () use ($request, $cancellation): mixed {
                $cancellation?->throwIfRequested();
                return $request();
            }, $deferred]);
        } catch (DisposedException $error) {
            $this->worker->await(); // Propagate a consumer failure to producers awaiting consumption.
            throw new RuntimeException('Request queue is stopped', 503, $error);
        }
        awaitFirst([$deferred->getFuture(), $this->worker], $cancellation);
        return $deferred->getFuture()->await();
    }

    /** Closing the input flushes the last batch; awaiting the consumer drains all accepted work. */
    public function stop(): void
    {
        if ($this->worker === null) {
            return;
        }
        if (!$this->input->isComplete()) {
            $this->input->complete();
        }
        $this->worker->await();
    }
}
