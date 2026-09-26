<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use InvalidArgumentException;
use NeuronAI\Evaluation\Assertions\StringContainsAll;
use NeuronAI\Evaluation\Assertions\StringContainsAny;
use PHPUnit\Framework\TestCase;

class StringContainsNonStringKeywordTest extends TestCase
{
    public function test_contains_all_does_not_pass_without_checking_a_non_string_keyword(): void
    {
        try {
            $result = (new StringContainsAll(['order', 123]))->evaluate('order confirmed');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertFalse($result->passed, 'Keyword 123 is absent from the output, yet the assertion passed');
    }

    public function test_contains_any_does_not_report_a_present_keyword_as_missing(): void
    {
        try {
            $result = (new StringContainsAny([123]))->evaluate('order 123');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertTrue($result->passed, 'Output contains 123, yet the failure says: ' . $result->message);
    }
}
