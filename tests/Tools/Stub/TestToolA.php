<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

use NeuronAI\Tools\Tool;

use function usleep;

/**
 * Simple test tool that can be serialized for parallel execution.
 */
class TestToolA extends Tool
{
    protected string $name = 'tool_a';

    protected ?string $description = 'Tool A';

    public function __invoke(string $input): string
    {
        usleep(10000); // 10ms delay
        return "Tool A received: {$input}";
    }
}
