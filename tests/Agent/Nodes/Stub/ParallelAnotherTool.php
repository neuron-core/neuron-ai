<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes\Stub;

use NeuronAI\Tools\Tool;

class ParallelAnotherTool extends Tool
{
    protected string $name = 'another_tool';

    protected ?string $description = 'Another tool';

    public function __invoke(): string
    {
        return 'result';
    }
}
