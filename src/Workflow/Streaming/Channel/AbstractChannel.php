<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use InvalidArgumentException;
use JsonException;
use LengthException;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use Random\RandomException;
use Throwable;

use function base64_encode;
use function bin2hex;
use function count;
use function implode;
use function intdiv;
use function json_encode;
use function min;
use function random_bytes;
use function strlen;
use function strtr;
use function substr;
use function str_repeat;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;

/**
 * Owns the channel envelope, fragmentation and segment-local delivery policy.
 * Transports encode envelopes and batches, then deliver within their measured byte limits.
 */
abstract class AbstractChannel implements StreamingChannelInterface
{
    /** @var list<string> */
    protected array $pending = [];

    protected ?string $streamId = null;

    protected int $sequence = 0;

    protected bool $stopped = false;

    abstract protected function deliver(string $batch): void;

    protected function batchSize(): int
    {
        return 1;
    }

    /** Maximum encoded delivery bytes, including the batch wrapper. */
    protected function budget(): ?int
    {
        return null;
    }

    /** Maximum envelope bytes before transport encoding. */
    protected function eventBudget(): ?int
    {
        return null;
    }

    protected function encode(string $type, string $envelope): string
    {
        return $envelope;
    }

    /** @param list<string> $events Already encoded for this transport. */
    protected function batch(array $events): string
    {
        return implode('', $events);
    }

    /** @param list<string> $events */
    protected function batchBytes(array $events): int
    {
        return strlen($this->batch($events));
    }

    /**
     * @throws JsonException
     */
    final public function send(ProtocolEvent $event): void
    {
        if (!$this->stopped) {
            $this->enqueueEvent($event);
        }
    }

    /**
     * @throws Throwable
     */
    final public function interrupted(WorkflowState $state): void
    {
        $this->finish(new ProtocolEvent('stream.interrupted', ['workflowId' => $state->getWorkflowId()]));
    }

    /**
     * @throws Throwable
     */
    final public function completed(WorkflowState $state, string $workflowId): void
    {
        $this->finish(new ProtocolEvent('stream.completed', ['workflowId' => $workflowId]));
    }

    /**
     * @throws Throwable
     */
    final public function failed(Throwable $exception, string $workflowId): void
    {
        $this->finish(new ProtocolEvent('stream.failed', ['workflowId' => $workflowId]));
    }

    /**
     * @throws Throwable
     */
    final protected function finish(ProtocolEvent $event): void
    {
        $failure = null;
        try {
            $this->flush();
        } catch (Throwable $e) {
            $failure = $e;
        }

        // A failed data batch must not prevent the terminal notification attempt.
        try {
            $this->enqueueEvent($event);
            $this->flush();
        } catch (Throwable $e) {
            $failure ??= $e;
        } finally {
            $this->pending = [];
            $this->streamId = null;
            $this->sequence = 0;
            $this->stopped = false;
        }

        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }

    /**
     * @throws RandomException
     * @throws JsonException
     */
    final protected function enqueueEvent(ProtocolEvent $event): void
    {
        if ($this->batchSize() < 1 || ($this->budget() !== null && $this->budget() < 1)
            || ($this->eventBudget() !== null && $this->eventBudget() < 1)) {
            throw new InvalidArgumentException('Channel batch size and byte limits must be positive.');
        }

        $this->streamId ??= bin2hex(random_bytes(16));
        $sequence = $this->sequence++;
        $data = json_encode($event->data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        foreach ($this->fragments($event->type, $data, $sequence) as $encoded) {
            $this->enqueue($encoded);
        }
    }

    /**
     * @throws JsonException
     */
    final protected function envelope(string $type, string $data, int $sequence): string
    {
        $header = json_encode([
            'streamId' => $this->streamId,
            'sequence' => $sequence,
            'type' => $type,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        return substr($header, 0, -1) . ',"data":' . $data . '}';
    }

    /**
     * @return iterable<string>
     * @throws JsonException
     */
    final protected function fragments(string $type, string $data, int $sequence): iterable
    {
        $envelope = $this->envelope($type, $data, $sequence);
        if ($this->eventBudget() === null || strlen($envelope) <= $this->eventBudget()) {
            $encoded = $this->encode($type, $envelope);
            if ($this->fits($envelope, $encoded)) {
                yield $encoded;
                return;
            }
        }
        unset($envelope, $encoded);

        $length = strlen($data);
        $maxFragments = intdiv($length + 2, 3);
        $probe = $this->fragment($type, $sequence, $maxFragments, $maxFragments, '');
        $capacity = min(
            $this->eventBudget() === null ? PHP_INT_MAX : $this->eventBudget() - strlen($probe),
            $this->budget() === null ? PHP_INT_MAX : $this->budget() - $this->batchBytes([$this->encode('stream.fragment', $probe)]),
        );
        // Find a capacity that also accounts for transport expansion (such as encryption).
        $groups = 0;
        $upper = min($maxFragments, intdiv($capacity, 4));
        $lower = 1;
        while ($lower <= $upper) {
            $candidate = intdiv($lower + $upper, 2);
            $probe = $this->fragment($type, $sequence, $maxFragments, $maxFragments, str_repeat('A', $candidate * 4));
            if ($this->fits($probe, $this->encode('stream.fragment', $probe))) {
                $groups = $candidate;
                $lower = $candidate + 1;
            } else {
                $upper = $candidate - 1;
            }
        }
        if ($groups === 0) {
            throw new LengthException('Channel byte limits cannot fit a fragment envelope and its data.');
        }

        // Whole three-byte groups let independently encoded slices concatenate.
        $sliceBytes = $groups * 3;
        $total = intdiv($length + $sliceBytes - 1, $sliceBytes);
        for ($index = 0, $offset = 0; $offset < $length; ++$index, $offset += $sliceBytes) {
            $part = strtr(base64_encode(substr($data, $offset, $sliceBytes)), '+/', '-_');
            $envelope = $this->fragment($type, $sequence, $index, $total, $part);
            $encoded = $this->encode('stream.fragment', $envelope);
            if (!$this->fits($envelope, $encoded)) {
                throw new LengthException('Transport encoding exceeds the channel fragment byte limits.');
            }
            yield $encoded;
        }
    }

    /**
     * @throws JsonException
     */
    final protected function fragment(string $type, int $sequence, int $index, int $total, string $part): string
    {
        return $this->envelope('stream.fragment', json_encode([
            'event' => $type,
            'index' => $index,
            'total' => $total,
            'part' => $part,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $sequence);
    }

    final protected function fits(string $envelope, string $encoded): bool
    {
        return ($this->eventBudget() === null || strlen($envelope) <= $this->eventBudget())
            && ($this->budget() === null || $this->batchBytes([$encoded]) <= $this->budget());
    }

    /**
     * @throws Throwable
     */
    final protected function enqueue(string $encoded): void
    {
        if ($this->pending !== [] && $this->budget() !== null
            && $this->batchBytes([...$this->pending, $encoded]) > $this->budget()) {
            $this->flush();
        }

        $this->pending[] = $encoded;
        if (count($this->pending) >= $this->batchSize()) {
            $this->flush();
        }
    }

    /**
     * @throws Throwable
     */
    final protected function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        [$events, $this->pending] = [$this->pending, []];
        try {
            $this->deliver($this->batch($events));
        } catch (Throwable $e) {
            $this->stopped = true;
            throw $e;
        }
    }
}
