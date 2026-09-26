<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ToolPropertyFactory;
use PHPUnit\Framework\TestCase;

class ArrayMaxItemsZeroTest extends TestCase
{
    public function test_max_items_zero_is_kept_in_the_schema(): void
    {
        $this->assertSame(
            ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 0],
            (new ArrayProperty('none', maxItems: 0))->getJsonSchema()
        );
    }

    public function test_max_items_zero_survives_a_schema_round_trip(): void
    {
        $properties = ToolPropertyFactory::fromSchema(['properties' => ['none' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 0]]]);

        $this->assertSame(0, $properties[0]->getJsonSchema()['maxItems'] ?? null);
    }
}
