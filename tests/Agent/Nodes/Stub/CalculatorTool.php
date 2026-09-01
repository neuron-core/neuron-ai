<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes\Stub;

use NeuronAI\Tools\Tool;

class CalculatorTool extends Tool
{
    protected string $name = 'calculator';

    protected ?string $description = 'Performs calculations';

    public function __invoke(): int
    {
        return 8; // 5 + 3
    }
}
