<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages\Stream\Adapters\Stub;

class IndexingProgress
{
    public function __construct(
        public readonly string $jobId,
        public readonly int $processed,
    ) {
    }
}
