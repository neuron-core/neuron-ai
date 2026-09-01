<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

use NeuronAI\Tools\Tool;

use function usleep;

/**
 * Add tool for parallel execution testing.
 */
class AddTool extends Tool
{
    protected string $name = 'add';

    protected ?string $description = 'Add two numbers';

    public function __invoke(int $x, int $y): string
    {
        usleep(10000); // 10ms delay
        return (string) ($x + $y);
    }
}
