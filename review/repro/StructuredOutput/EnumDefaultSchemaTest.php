<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\Tests\StructuredOutput\Stub\DummyEnum;
use NeuronAI\Tests\StructuredOutput\Stub\StringEnum;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

class EnumDefaultSchemaTest extends TestCase
{
    public function test_backed_enum_default_is_its_backing_value(): void
    {
        $class = new class () {
            public StringEnum $number = StringEnum::TWO;
        };

        $schema = JsonSchema::make()->generate($class::class);

        $this->assertSame('two', $schema['properties']['number']['default']);
    }

    public function test_pure_enum_default_is_its_case_name_and_schema_is_encodable(): void
    {
        $class = new class () {
            public DummyEnum $choice = DummyEnum::B;
        };

        $schema = JsonSchema::make()->generate($class::class);

        $this->assertSame('B', $schema['properties']['choice']['default']);
        $this->assertSame(
            '{"type":"object","properties":{"choice":{"default":"B","type":"string","enum":["A","B"]}},"additionalProperties":false}',
            json_encode($schema, JSON_THROW_ON_ERROR)
        );
    }
}
