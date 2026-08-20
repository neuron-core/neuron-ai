<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters\Events;

use NeuronAI\Exceptions\StreamAdapterException;

use function trim;

class StepFinishedStreamEvent implements StreamEventInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $name,
        public readonly array $metadata = [],
    ) {
        if (trim($name) === '') {
            throw new StreamAdapterException('A stream step name cannot be empty.');
        }
    }
}
