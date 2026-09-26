<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use NeuronAI\Workflow\Streaming\Channel\AbstractChannel;

use function str_replace;

/**
 * A transport whose encoding grows with the content, like an escaping wire
 * format: every occurrence of $expanded costs extra bytes.
 */
final class ExpandingChannel extends AbstractChannel
{
    /** @var list<string> */
    public array $delivered = [];

    public function __construct(
        protected int $budget,
        protected string $expanded,
        protected string $replacement,
    ) {
    }

    protected function budget(): int
    {
        return $this->budget;
    }

    protected function encode(string $type, string $envelope): string
    {
        return str_replace($this->expanded, $this->replacement, $envelope);
    }

    protected function deliver(string $batch): void
    {
        $this->delivered[] = $batch;
    }
}
