<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Tests\Evaluation\Assertions\Stub\GreetingPrefixAssertion;
use NeuronAI\Tests\Evaluation\Assertions\Stub\TestableAbstractAssertion;
use PHPUnit\Framework\TestCase;

class AbstractAssertionTest extends TestCase
{
    public function test_get_name_returns_short_class_name(): void
    {
        $assertion = new TestableAbstractAssertion();
        $this->assertEquals('TestableAbstractAssertion', $assertion->getName());
    }

    public function test_get_name_is_the_concrete_subclass_not_the_framework_parent(): void
    {
        // The name is the default score label: an application assertion must not report as its parent
        $this->assertSame('GreetingPrefixAssertion', (new GreetingPrefixAssertion())->getName());
    }
}
