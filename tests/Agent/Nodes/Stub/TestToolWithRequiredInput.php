<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes\Stub;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class TestToolWithRequiredInput extends Tool
{
    protected string $name = 'test_tool';

    protected ?string $description = 'A test tool';

    protected function properties(): array
    {
        return [
            new ToolProperty('required_input', PropertyType::STRING, 'A required input', true),
        ];
    }

    public function __invoke(string $required_input): string
    {
        return "Processed: {$required_input}";
    }
}
