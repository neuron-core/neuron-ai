<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Validation;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Validation\Rules\Count;
use NeuronAI\StructuredOutput\Validation\Rules\Json;
use NeuronAI\StructuredOutput\Validation\Validator;
use PHPUnit\Framework\TestCase;

class NullableCountedArrayTest extends TestCase
{
    public function test_null_optional_array_does_not_abort_validation(): void
    {
        $class = new class () {
            #[Count(max: 3)]
            public ?array $tags = null;
        };

        $this->assertSame([], Validator::validate(new $class()));
    }

    public function test_optional_array_missing_from_model_json_does_not_abort_validation(): void
    {
        $object = Deserializer::make()->fromJson('{"name":"x"}', CountedTagsOutput::class);

        $this->assertSame([], Validator::validate($object));
    }

    public function test_json_rule_on_non_string_value_reports_a_violation(): void
    {
        $violations = [];
        (new Json())->validate('payload', ['a' => 1], $violations);

        $this->assertSame(['payload must be a valid JSON string'], $violations);
    }
}

class CountedTagsOutput
{
    public string $name;

    #[Count(max: 3)]
    public ?array $tags = null;
}
