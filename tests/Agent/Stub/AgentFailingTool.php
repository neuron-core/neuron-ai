<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use RuntimeException;

class AgentFailingTool extends Tool
{
    protected string $name = 'failing_tool';

    protected ?string $description = 'A tool that fails';

    protected function properties(): array
    {
        return [
            new ToolProperty('input', PropertyType::STRING, 'Input', true),
        ];
    }

    public function __invoke(string $input): string
    {
        throw new RuntimeException('Tool failed!');
    }
}
