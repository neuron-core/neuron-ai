<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * Counts executions across clones so a test can prove a replayed run reused
 * the memoized tool step instead of running the tool again.
 */
class CountingTool extends Tool
{
    public static int $executions = 0;

    protected string $name = 'lookup';

    protected ?string $description = 'Look something up';

    public static function reset(): void
    {
        self::$executions = 0;
    }

    protected function properties(): array
    {
        return [
            new ToolProperty('query', PropertyType::STRING, 'What to look up', true),
        ];
    }

    public function __invoke(string $query): string
    {
        self::$executions++;

        return "Results for: {$query}";
    }
}
