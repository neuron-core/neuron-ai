<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Stubs\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class TestToolClassOnlyParentConstructor extends \NeuronAI\Tools\Tool
{
    public function __construct(protected string $key)
    {
        parent::__construct(
            'test_tool',
            'test tool',
            [
                new ToolProperty(
                    'url',
                    PropertyType::STRING,
                    'The URL to read.',
                    true
                ),
                new ToolProperty(
                    'param',
                    PropertyType::STRING,
                    'the param'
                ),
            ]
        );
    }

    public function getKey(): string
    {
        return $this->key;
    }
}
