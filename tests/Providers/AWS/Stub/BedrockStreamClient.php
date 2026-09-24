<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\AWS\Stub;

use Aws\Api\Parser\EventParsingIterator;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Result;

/**
 * Replays recorded ConverseStream events without an AWS connection.
 */
class BedrockStreamClient extends BedrockRuntimeClient
{
    /**
     * @param array<int, array<string, mixed>> $recorded
     */
    public function __construct(protected array $recorded)
    {
    }

    /**
     * @param array<string, mixed> $args
     */
    public function converseStream(array $args = []): Result
    {
        return new Result(['stream' => new class ($this->recorded) extends EventParsingIterator {
            protected int $position = 0;

            /**
             * @param array<int, array<string, mixed>> $events
             */
            public function __construct(protected array $events)
            {
            }

            public function current(): mixed
            {
                return $this->events[$this->position];
            }

            public function key(): int
            {
                return $this->position;
            }

            public function next(): void
            {
                $this->position++;
            }

            public function rewind(): void
            {
                $this->position = 0;
            }

            public function valid(): bool
            {
                return isset($this->events[$this->position]);
            }
        }]);
    }
}
