<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Validation;

use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use PHPUnit\Framework\TestCase;

class ArrayOfMultipleScalarTypesTest extends TestCase
{
    public function test_items_matching_any_listed_scalar_type_are_accepted(): void
    {
        $violations = [];
        (new ArrayOf(['string', 'integer']))->validate('ids', ['a', 1], $violations);

        $this->assertSame([], $violations);
    }

    public function test_items_matching_none_of_the_listed_types_are_rejected(): void
    {
        $violations = [];
        (new ArrayOf(['string', 'integer']))->validate('ids', ['a', 1.5], $violations);

        $this->assertSame(['ids must be an array of string, integer'], $violations);
    }
}
