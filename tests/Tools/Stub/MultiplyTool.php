<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

use NeuronAI\Tools\Tool;

use function usleep;

/**
 * Multiply tool for parallel execution testing.
 */
class MultiplyTool extends Tool
{
    protected string $name = 'multiply';

    protected ?string $description = 'Multiply two numbers';

    public function __invoke(int $a, int $b): string
    {
        usleep(10000); // 10ms delay
        return (string) ($a * $b);
    }
}
