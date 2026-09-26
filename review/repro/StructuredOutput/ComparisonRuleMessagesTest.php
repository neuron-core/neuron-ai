<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Validation;

use NeuronAI\StructuredOutput\Validation\Rules\EqualTo;
use NeuronAI\StructuredOutput\Validation\Rules\GreaterThan;
use NeuronAI\StructuredOutput\Validation\Rules\GreaterThanEqual;
use NeuronAI\StructuredOutput\Validation\Rules\LowerThan;
use NeuronAI\StructuredOutput\Validation\Rules\LowerThanEqual;
use NeuronAI\StructuredOutput\Validation\Rules\NotEqualTo;
use NeuronAI\StructuredOutput\Validation\Rules\OutOfRange;
use NeuronAI\StructuredOutput\Validation\ValidationRuleInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Violations are sent back to the model as the correction prompt, so each one
 * must name the field and the expected bound.
 */
class ComparisonRuleMessagesTest extends TestCase
{
    /**
     * @return array<string, array{ValidationRuleInterface, mixed, string}>
     */
    public static function violationProvider(): array
    {
        return [
            'EqualTo' => [new EqualTo(30), 31, 'age must be equal to 30'],
            'NotEqualTo' => [new NotEqualTo(30), 30, 'age must not be equal to 30'],
            'GreaterThan' => [new GreaterThan(30), 30, 'age must be greater than 30'],
            'GreaterThanEqual' => [new GreaterThanEqual(30), 29, 'age must be greater than or equal to 30'],
            'LowerThan' => [new LowerThan(30), 30, 'age must be lower than 30'],
            'LowerThanEqual' => [new LowerThanEqual(30), 31, 'age must be lower than or equal to 30'],
            'OutOfRange above max' => [new OutOfRange(1, 10), 11, 'age must be less than or equal to 10'],
            'OutOfRange below min' => [new OutOfRange(1, 10), 0, 'age must be greater than or equal to 1'],
        ];
    }

    #[DataProvider('violationProvider')]
    public function test_violation_names_the_field_and_the_bound(ValidationRuleInterface $rule, mixed $value, string $expected): void
    {
        $violations = [];
        $rule->validate('age', $value, $violations);

        $this->assertSame([$expected], $violations);
    }
}
