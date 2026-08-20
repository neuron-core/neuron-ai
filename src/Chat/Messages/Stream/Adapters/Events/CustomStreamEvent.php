<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters\Events;

use NeuronAI\Exceptions\StreamAdapterException;

use function trim;

class CustomStreamEvent implements StreamEventInterface
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $value,
    ) {
        if (trim($name) === '') {
            throw new StreamAdapterException('A custom stream event name cannot be empty.');
        }
    }
}
