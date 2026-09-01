<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Memory\Stub;

use NeuronAI\Tools\Tool;

class MemoryLookupTool extends Tool
{
    protected string $name = 'memory_lookup';

    public function __invoke(): string
    {
        return 'Lookup result';
    }
}
