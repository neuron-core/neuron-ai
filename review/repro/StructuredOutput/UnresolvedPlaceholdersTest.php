<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Validation;

use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\Enum;
use NeuronAI\StructuredOutput\Validation\Validator;
use PHPUnit\Framework\TestCase;

class UnresolvedPlaceholdersTest extends TestCase
{
    public function test_enum_null_violation_lists_the_allowed_values(): void
    {
        $violations = [];
        (new Enum(values: ['one', 'two']))->validate('number', null, $violations);

        $this->assertSame(['number must be one of the following allowed values: one, two.'], $violations);
    }

    public function test_array_of_non_array_violation_lists_the_types(): void
    {
        $violations = [];
        (new ArrayOf('string'))->validate('tags', 'abc', $violations);

        $this->assertSame(['tags must be an array of string'], $violations);
    }

    public function test_validator_reports_resolved_messages_for_property_attributes(): void
    {
        $object = new class () {
            #[Enum(values: ['one', 'two'])]
            public ?string $number = null;

            #[ArrayOf('string')]
            public mixed $tags = 'abc';
        };

        $this->assertSame([
            'number must be one of the following allowed values: one, two.',
            'tags must be an array of string',
        ], Validator::validate($object));
    }
}
