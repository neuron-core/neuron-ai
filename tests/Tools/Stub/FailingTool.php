<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

use NeuronAI\Tools\Tool;
use RuntimeException;

/**
 * Failing tool for error testing.
 */
class FailingTool extends Tool
{
    protected string $name = 'failing_tool';

    protected ?string $description = 'This tool will fail';

    public function __invoke(string $input): string
    {
        throw new RuntimeException('Tool execution failed');
    }
}
