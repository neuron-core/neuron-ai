<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Tests\Evaluation\Assertions\Stub\TestableAbstractAssertion;
use NeuronAI\Evaluation\AssertionResult;
use PHPUnit\Framework\TestCase;

class AbstractAssertionTest extends TestCase
{
    public function test_get_name_returns_short_class_name(): void
    {
        $assertion = new TestableAbstractAssertion();
        $this->assertEquals('TestableAbstractAssertion', $assertion->getName());
    }

    public function test_implements_assertion_interface(): void
    {
        $assertion = new TestableAbstractAssertion();
        $this->assertInstanceOf(\NeuronAI\Evaluation\Contracts\AssertionInterface::class, $assertion);
    }

    public function test_evaluate_method_can_be_called(): void
    {
        $assertion = new TestableAbstractAssertion();
        $result = $assertion->evaluate('test input');

        $this->assertInstanceOf(AssertionResult::class, $result);
        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('test evaluation', $result->message);
    }
}
