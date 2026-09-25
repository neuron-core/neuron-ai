<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming;

use Closure;
use Generator;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Executor\ExecutionEventDispatcher;
use NeuronAI\Workflow\Observability\ChannelError;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * The output of one execution segment. The adapter turns what nodes stream
 * into protocol events and the channel delivers them; without an adapter the
 * streamed objects pass through unchanged and the channel receives only the
 * segment lifecycle. Both are built for the segment and live as long as it.
 *
 * Everything the segment yields comes from here, so the output numbers the
 * stream: the delegating generators pass the keys on untouched.
 *
 * @internal
 */
final class SegmentOutput
{
    protected int $position = 0;

    public function __construct(
        protected ?StreamAdapterInterface $adapter,
        protected ?StreamingChannelInterface $channel,
        protected ExecutionEventDispatcher $events,
        protected string $workflowId,
    ) {
    }

    /** @return Generator<int, ProtocolEvent> */
    public function start(): Generator
    {
        if ($this->adapter instanceof StreamAdapterInterface) {
            yield from $this->deliver($this->adapter->start());
        }
    }

    /** @return Generator<int, object> */
    public function emit(object $item): Generator
    {
        if ($this->adapter instanceof StreamAdapterInterface) {
            yield from $this->deliver($this->adapter->transform($item));
        } else {
            yield $this->position++ => $item;
        }
    }

    /** @return Generator<int, object> */
    public function interrupted(WorkflowState $state): Generator
    {
        if ($this->adapter instanceof StreamAdapterInterface) {
            yield from $this->deliver($this->adapter->interrupt($state->getInterruptRequest()));
        } else {
            yield $this->position++ => InterruptEvent::fromRequest($state->getInterruptRequest());
        }

        $this->notify(fn (StreamingChannelInterface $channel) => $channel->interrupted(clone $state));
    }

    /** @return Generator<int, ProtocolEvent> */
    public function completed(WorkflowState $state): Generator
    {
        if ($this->adapter instanceof StreamAdapterInterface) {
            yield from $this->deliver($this->adapter->end());
        }

        $this->notify(fn (StreamingChannelInterface $channel) => $channel->completed($state, $this->workflowId));
    }

    /** @return Generator<int, ProtocolEvent> */
    public function failed(Throwable $error): Generator
    {
        if ($this->adapter instanceof StreamAdapterInterface) {
            yield from $this->deliver($this->adapter->error($error));
        }

        $this->notify(fn (StreamingChannelInterface $channel) => $channel->failed($error, $this->workflowId));
    }

    /**
     * @param iterable<ProtocolEvent> $events
     * @return Generator<int, ProtocolEvent>
     */
    protected function deliver(iterable $events): Generator
    {
        foreach ($events as $event) {
            $this->notify(fn (StreamingChannelInterface $channel) => $channel->send($event));
            yield $this->position++ => $event;
        }
    }

    /**
     * A transport failure is reported, never raised: the channel is an
     * observer of the segment, not part of it.
     *
     * @param Closure(StreamingChannelInterface): void $delivery
     */
    protected function notify(Closure $delivery): void
    {
        if (!$this->channel instanceof StreamingChannelInterface) {
            return;
        }

        try {
            $delivery($this->channel);
        } catch (Throwable $e) {
            $this->events->report(new ChannelError($e));
        }
    }
}
