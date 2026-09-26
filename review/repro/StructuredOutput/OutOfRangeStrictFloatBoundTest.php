<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Validation;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Validation\Rules\OutOfRange;
use NeuronAI\StructuredOutput\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OutOfRangeStrictFloatBoundTest extends TestCase
{
    public static function excludedBounds(): array
    {
        return [
            'float value on int min' => [new OutOfRange(1, 10, strict: true), 1.0, 'must be strictly greater than 1'],
            'float value on int max' => [new OutOfRange(1, 10, strict: true), 10.0, 'must be strictly less than 10'],
            'int value on float min' => [new OutOfRange(0.0, 1.0, strict: true), 0, 'must be strictly greater than 0'],
            'int value on float max' => [new OutOfRange(0.0, 1.0, strict: true), 1, 'must be strictly less than 1'],
        ];
    }

    #[DataProvider('excludedBounds')]
    public function test_strict_range_rejects_a_value_numerically_equal_to_a_bound(OutOfRange $rule, int|float $value, string $message): void
    {
        $violations = [];
        $rule->validate('score', $value, $violations);

        $this->assertSame([$message], $violations);
    }

    public function test_deserialized_float_property_on_excluded_int_bound_fails_validation(): void
    {
        $output = (new Deserializer())->fromJson('{"score": 0}', StrictScore::class);

        $this->assertSame(0.0, $output->score);
        $this->assertSame(['must be strictly greater than 0'], Validator::validate($output));
    }
}

class StrictScore
{
    #[OutOfRange(0, 100, strict: true)]
    public float $score;
}
