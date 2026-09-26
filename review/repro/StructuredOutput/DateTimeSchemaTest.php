<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use DateTimeImmutable;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\JsonSchema;
use PHPUnit\Framework\TestCase;

use function json_encode;

class DateTimeSchemaTest extends TestCase
{
    public function test_date_time_property_is_described_as_a_date_time_string(): void
    {
        $class = new class () {
            public DateTimeImmutable $publishedAt;
        };

        $schema = JsonSchema::make()->generate($class::class);

        $this->assertSame(['type' => 'string', 'format' => 'date-time'], $schema['properties']['publishedAt']);
    }

    public function test_date_time_schema_round_trips_through_the_deserializer(): void
    {
        $class = new class () {
            public DateTimeImmutable $publishedAt;
        };

        $obj = Deserializer::make()->fromJson('{"publishedAt": "2023-11-14T22:13:20+00:00"}', $class::class);

        $this->assertSame(1700000000, $obj->publishedAt->getTimestamp());
    }

    public function test_class_without_properties_encodes_properties_as_a_json_object(): void
    {
        $class = new class () {
        };

        $this->assertSame(
            '{"type":"object","properties":{},"additionalProperties":false}',
            json_encode(JsonSchema::make()->generate($class::class))
        );
    }
}
