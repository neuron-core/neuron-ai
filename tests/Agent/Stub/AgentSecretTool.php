<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class AgentSecretTool extends Tool
{
    protected string $name = 'secret';

    protected ?string $description = 'Secret tool';

    protected function properties(): array
    {
        return [
            new ToolProperty('input', PropertyType::STRING, 'Input', true),
        ];
    }

    public function __invoke(string $input): string
    {
        return "Secret: {$input}";
    }
}
