<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters\Events;

use NeuronAI\Exceptions\StreamAdapterException;

use function trim;

class ActivityStreamEvent implements StreamEventInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $data,
    ) {
        if (trim($id) === '') {
            throw new StreamAdapterException('A stream activity ID cannot be empty.');
        }

        if (trim($type) === '') {
            throw new StreamAdapterException('A stream activity type cannot be empty.');
        }
    }
}
