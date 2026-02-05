<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Stubs\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class TestToolClassWithParentConstructor extends Tool
{
    public function __construct(protected string $key)
    {
        parent::__construct('test_tool', 'test tool');
    }

    public function properties(): array
    {
        return [
            new ToolProperty(
                'url',
                PropertyType::STRING,
                'The URL to read.',
                true
            ),
        ];
    }

    public function getKey(): string
    {
        return $this->key;
    }
}
