<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use JsonException;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

use function base64_encode;
use function count;
use function json_encode;
use function str_split;
use function strlen;
use function strtr;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * The wire contract every channel shares, so a browser consumes any transport
 * with the same code: protocol events named by their type, the lifecycle as
 * stream.interrupted / stream.completed / stream.failed with the workflowId
 * only, and an event over budget() as stream.fragment slices the client
 * concatenates and parses. Events are buffered up to batchSize() per
 * deliver() call within budget() bytes and flushed at the end of the segment;
 * the buffer is cleared before deliver() runs, so a failure loses that batch
 * only and the Workflow reports it as a ChannelError.
 */
abstract class AbstractChannel implements StreamingChannelInterface
{
    /** Room for index and total to grow beyond the single digits of the measured empty fragment. */
    protected const COUNTER_DIGITS = 12;

    /** @var ProtocolEvent[] */
    protected array $pending = [];

    protected int $pendingBytes = 0;

    /**
     * @param ProtocolEvent[] $events In stream order, never empty.
     */
    abstract protected function deliver(array $events): void;

    /**
     * Events one deliver() call may carry.
     */
    protected function batchSize(): int
    {
        return 1;
    }

    /**
     * Bytes one deliver() call may carry on this transport; null means no ceiling.
     */
    protected function budget(): ?int
    {
        return null;
    }

    /**
     * Wire cost of one event inside a delivery, its share of the transport
     * envelope included. Measured only when a budget is set.
     *
     * @throws JsonException
     */
    protected function size(ProtocolEvent $event): int
    {
        return strlen(json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * @throws JsonException
     */
    public function send(ProtocolEvent $event): void
    {
        foreach ($this->fragments($event) as $piece) {
            $this->enqueue($piece);
        }
    }

    /**
     * @throws JsonException
     */
    public function interrupted(WorkflowState $state): void
    {
        $this->enqueue(new ProtocolEvent('stream.interrupted', ['workflowId' => $state->getWorkflowId()]));
        $this->flush();
    }

    /**
     * @throws JsonException
     */
    public function completed(WorkflowState $state, string $workflowId): void
    {
        $this->enqueue(new ProtocolEvent('stream.completed', ['workflowId' => $workflowId]));
        $this->flush();
    }

    /**
     * @throws JsonException
     */
    public function failed(Throwable $exception, string $workflowId): void
    {
        $this->enqueue(new ProtocolEvent('stream.failed', ['workflowId' => $workflowId]));
        $this->flush();
    }

    /**
     * The event itself when it fits a delivery alone, otherwise its fragments,
     * sized from a probe measured by size() so each one fits alone too.
     *
     * @return iterable<ProtocolEvent>
     * @throws JsonException
     */
    protected function fragments(ProtocolEvent $event): iterable
    {
        $budget = $this->budget();
        if ($budget === null || $this->size($event) <= $budget) {
            yield $event;
            return;
        }

        // Unicode stays escaped so a slice decodes with atob(), which only
        // carries ASCII; invalid UTF-8 becomes U+FFFD as on the SSE path.
        $encoded = json_encode($event->data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $sliceBytes = $budget - $this->size($this->fragment($event->type, 0, 0, '')) - self::COUNTER_DIGITS;

        // URL-safe base64 holds no character a JSON encoder escapes, so a
        // slice costs exactly its length on any transport.
        $parts = str_split(strtr(base64_encode($encoded), '+/', '-_'), $sliceBytes);
        foreach ($parts as $index => $part) {
            yield $this->fragment($event->type, $index, count($parts), $part);
        }
    }

    protected function fragment(string $event, int $index, int $total, string $part): ProtocolEvent
    {
        return new ProtocolEvent('stream.fragment', ['event' => $event, 'index' => $index, 'total' => $total, 'part' => $part]);
    }

    /**
     * One rule for every event, fragments included: what would not fit the
     * delivery goes after a flush, and a full batch leaves at once.
     *
     * @throws JsonException
     */
    protected function enqueue(ProtocolEvent $event): void
    {
        $budget = $this->budget();
        $bytes = $budget === null ? 0 : $this->size($event);

        if ($this->pending !== [] && $budget !== null && $this->pendingBytes + $bytes > $budget) {
            $this->flush();
        }

        $this->pending[] = $event;
        $this->pendingBytes += $bytes;

        if (count($this->pending) >= $this->batchSize()) {
            $this->flush();
        }
    }

    protected function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        [$events, $this->pending, $this->pendingBytes] = [$this->pending, [], 0];
        $this->deliver($events);
    }
}
