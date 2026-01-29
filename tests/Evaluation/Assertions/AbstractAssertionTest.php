<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Evaluation\Assertions\AbstractAssertion;
use NeuronAI\Evaluation\AssertionResult;
use PHPUnit\Framework\TestCase;

class AbstractAssertionTest extends TestCase
{
    public function testGetNameReturnsShortClassName(): void
    {
        $assertion = new TestableAbstractAssertion();
        $this->assertEquals('TestableAbstractAssertion', $assertion->getName());
    }

    public function testImplementsAssertionInterface(): void
    {
        $assertion = new TestableAbstractAssertion();
        $this->assertInstanceOf(\NeuronAI\Evaluation\Contracts\AssertionInterface::class, $assertion);
    }

    public function testEvaluateMethodCanBeCalled(): void
    {
        $assertion = new TestableAbstractAssertion();
        $result = $assertion->evaluate('test input');

        $this->assertInstanceOf(AssertionResult::class, $result);
        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
        $this->assertEquals('test evaluation', $result->message);
    }
}

class TestableAbstractAssertion extends AbstractAssertion
{
    public function evaluate(mixed $actual): AssertionResult
    {
        return AssertionResult::pass(1.0, 'test evaluation');
    }
}
