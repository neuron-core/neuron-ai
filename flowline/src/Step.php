<?php

declare(strict_types=1);

namespace Flowline;

/**
 * Provides durable step operations inside task handlers.
 *
 * Each method checks for memoized results from previous executions.
 * When no memoized data exists, the step either executes immediately (run)
 * or reports the operation to the platform (sleep, waitForEvent),
 * then yields control back via StepPendingException.
 */
final class Step
{
    /** @var list<array{id: string, op: string, displayName?: string, data?: mixed, error?: array{name: string, message: string}, opts?: array}> */
    private array $ops = [];

    /**
     * @param array<string, array{data?: mixed, error?: array{name: string, message: string}}> $memoized Step results from previous executions
     */
    public function __construct(
        private readonly array $memoized = [],
    ) {}

    /**
     * Execute a durable step. Returns memoized data on replay, executes the
     * callable on first encounter, then yields control to the platform.
     */
    public function run(string $id, callable $fn): mixed
    {
        $opId = $this->hashId($id);

        if (array_key_exists($opId, $this->memoized)) {
            $entry = $this->memoized[$opId];
            if (isset($entry['error'])) {
                throw new \RuntimeException(
                    $entry['error']['message'],
                    0,
                );
            }
            return $entry['data'];
        }

        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->ops[] = [
                'id' => $opId,
                'op' => 'StepError',
                'displayName' => $id,
                'error' => ['name' => get_class($e), 'message' => $e->getMessage()],
            ];
            throw new StepPendingException();
        }

        $this->ops[] = [
            'id' => $opId,
            'op' => 'StepRun',
            'displayName' => $id,
            'data' => $result,
        ];
        throw new StepPendingException();
    }

    /**
     * Sleep for a duration. On replay (memoized), returns immediately.
     *
     * @param string $id Unique step identifier
     * @param string $duration Duration string (e.g. "5m", "2h", "30s")
     */
    public function sleep(string $id, string $duration): void
    {
        $opId = $this->hashId($id);

        if (array_key_exists($opId, $this->memoized)) {
            return;
        }

        $this->ops[] = [
            'id' => $opId,
            'op' => 'Sleep',
            'displayName' => $id,
            'opts' => ['duration' => $duration],
        ];
        throw new StepPendingException();
    }

    /**
     * Pause execution until a matching event is received or timeout elapses.
     * On replay (memoized), returns the matched event data immediately.
     */
    public function waitForEvent(string $id, string $event, string $timeout): mixed
    {
        $opId = $this->hashId($id);

        if (array_key_exists($opId, $this->memoized)) {
            return $this->memoized[$opId]['data'];
        }

        $this->ops[] = [
            'id' => $opId,
            'op' => 'WaitForEvent',
            'displayName' => $id,
            'opts' => ['event' => $event, 'timeout' => $timeout],
        ];
        throw new StepPendingException();
    }

    /**
     * @return list<array{id: string, op: string, displayName?: string, data?: mixed, error?: array{name: string, message: string}, opts?: array}>
     */
    public function getOps(): array
    {
        return $this->ops;
    }

    private function hashId(string $id): string
    {
        return sha1($id);
    }
}
