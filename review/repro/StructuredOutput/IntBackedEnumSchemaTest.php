<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\Tests\StructuredOutput\Stub\IntEnum;
use NeuronAI\Tests\StructuredOutput\Stub\StringEnum;
use PHPUnit\Framework\TestCase;

class IntBackedEnumSchemaTest extends TestCase
{
    public function test_int_backed_enum_is_described_as_integer(): void
    {
        $class = new class () {
            public IntEnum $level;
        };

        $schema = JsonSchema::make()->generate($class::class);

        $this->assertSame(['type' => 'integer', 'enum' => [1, 2, 3]], $schema['properties']['level']);
    }

    public function test_nullable_int_backed_enum_is_described_as_nullable_integer(): void
    {
        $class = new class () {
            public ?IntEnum $level = null;
        };

        $schema = JsonSchema::make()->generate($class::class);

        $this->assertSame(['integer', 'null'], $schema['properties']['level']['type']);
    }

    public function test_string_backed_enum_is_still_described_as_string(): void
    {
        $class = new class () {
            public StringEnum $status;
        };

        $schema = JsonSchema::make()->generate($class::class);

        $this->assertSame('string', $schema['properties']['status']['type']);
    }
}
