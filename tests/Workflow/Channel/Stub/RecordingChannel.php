<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use NeuronAI\Workflow\Streaming\Channel\AbstractChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use RuntimeException;

/**
 * A channel with a configurable batch and budget that records every delivery.
 */
final class RecordingChannel extends AbstractChannel
{
    /** @var array<int, ProtocolEvent[]> */
    public array $deliveries = [];

    public bool $failNextDelivery = false;

    public function __construct(
        protected int $batchSize = 1,
        protected ?int $budget = null,
    ) {
    }

    protected function batchSize(): int
    {
        return $this->batchSize;
    }

    protected function budget(): ?int
    {
        return $this->budget;
    }

    protected function deliver(array $events): void
    {
        if ($this->failNextDelivery) {
            $this->failNextDelivery = false;
            throw new RuntimeException('transport down');
        }

        $this->deliveries[] = $events;
    }

    /** The default wire cost, exposed for assertions. */
    public function sizeOf(ProtocolEvent $event): int
    {
        return $this->size($event);
    }
}
