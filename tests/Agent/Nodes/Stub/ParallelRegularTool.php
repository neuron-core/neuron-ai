<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes\Stub;

use NeuronAI\Tools\Tool;

class ParallelRegularTool extends Tool
{
    protected string $name = 'regular_tool';

    protected ?string $description = 'A regular tool';

    public function __invoke(): string
    {
        return 'result';
    }
}
