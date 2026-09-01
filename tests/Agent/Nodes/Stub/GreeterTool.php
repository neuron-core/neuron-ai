<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes\Stub;

use NeuronAI\Tools\Tool;

class GreeterTool extends Tool
{
    protected string $name = 'greeter';

    protected ?string $description = 'Greets a person';

    public function __invoke(): string
    {
        return "Hello, World!";
    }
}
