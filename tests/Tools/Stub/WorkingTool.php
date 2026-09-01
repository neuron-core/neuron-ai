<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

use NeuronAI\Tools\Tool;

/**
 * Working tool for error testing.
 */
class WorkingTool extends Tool
{
    protected string $name = 'working_tool';

    protected ?string $description = 'This tool works';

    public function __invoke(string $input): string
    {
        return "Success: {$input}";
    }
}
