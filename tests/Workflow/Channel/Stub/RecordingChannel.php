<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use NeuronAI\Workflow\Streaming\Channel\AbstractChannel;
use RuntimeException;

use function implode;
use function json_decode;
use function strlen;

final class RecordingChannel extends AbstractChannel
{
    /** @var list<list<array<string, mixed>>> */
    public array $deliveries = [];

    /** @var list<int> */
    public array $bytes = [];

    public bool $failNextDelivery = false;

    public bool $alwaysFail = false;

    public int $attempts = 0;

    public function __construct(
        protected int $batchSize = 1,
        protected ?int $budget = null,
        protected ?int $eventBudget = null,
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

    protected function eventBudget(): ?int
    {
        return $this->eventBudget;
    }

    protected function batch(array $events): string
    {
        return '[' . implode(',', $events) . ']';
    }

    protected function deliver(string $batch): void
    {
        ++$this->attempts;
        if ($this->failNextDelivery || $this->alwaysFail) {
            $this->failNextDelivery = false;
            throw new RuntimeException('transport down');
        }

        $this->deliveries[] = json_decode($batch, true);
        $this->bytes[] = strlen($batch);
    }
}
