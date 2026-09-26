<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Deserializer;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\Tests\StructuredOutput\Stub\DummyEnum;
use PHPUnit\Framework\TestCase;

class PureEnumRoundTripTest extends TestCase
{
    public function test_value_allowed_by_the_schema_is_deserialized(): void
    {
        $class = new class () {
            public DummyEnum $choice;
        };

        $allowed = (new JsonSchema())->generate($class::class)['properties']['choice']['enum'];
        $this->assertSame(['A', 'B'], $allowed);

        $obj = Deserializer::make()->fromJson('{"choice": "A"}', $class::class);

        $this->assertSame(DummyEnum::A, $obj->choice);
    }

    public function test_case_name_outside_the_schema_is_rejected(): void
    {
        $class = new class () {
            public DummyEnum $choice;
        };

        $this->expectException(DeserializerException::class);

        Deserializer::make()->fromJson('{"choice": "C"}', $class::class);
    }
}
