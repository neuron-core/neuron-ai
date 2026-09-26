<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Validation;

use NeuronAI\StructuredOutput\Validation\Rules\GreaterThan;
use NeuronAI\StructuredOutput\Validation\Rules\GreaterThanEqual;
use NeuronAI\StructuredOutput\Validation\Rules\LowerThanEqual;
use NeuronAI\StructuredOutput\Validation\Validator;
use PHPUnit\Framework\TestCase;

class GreaterThanEqualNullValueTest extends TestCase
{
    public function test_missing_value_is_rejected(): void
    {
        $violations = [];
        (new GreaterThanEqual(0))->validate('quantity', null, $violations);

        $this->assertCount(1, $violations);
    }

    public function test_uninitialized_property_is_rejected_like_the_other_comparison_rules(): void
    {
        $order = new class () {
            #[GreaterThanEqual(0)]
            public int $quantity;

            #[GreaterThan(-1)]
            public int $stock;

            #[LowerThanEqual(10)]
            public int $discount;
        };

        $this->assertCount(3, Validator::validate($order));
    }
}
