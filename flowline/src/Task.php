<?php

declare(strict_types=1);

namespace Flowline;

final class Task
{
    /**
     * @param string $id Unique task identifier (without app prefix)
     * @param list<Event> $triggers Events that activate this task
     * @param callable(Context): mixed $handler The task handler
     * @param string|null $name Optional display name (defaults to id)
     * @param array{attempts?: int} $retries Retry configuration
     */
    public function __construct(
        public readonly string $id,
        public readonly array $triggers,
        public readonly mixed $handler,
        public readonly ?string $name = null,
        public readonly array $retries = ['attempts' => 3],
    ) {}
}
