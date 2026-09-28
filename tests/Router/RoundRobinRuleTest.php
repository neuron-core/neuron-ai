<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Router;

use InvalidArgumentException;
use NeuronAI\Router\Rules\RoundRobinRule;
use PHPUnit\Framework\TestCase;

// Added coverage for weighted load balancing to guarantee deterministic
// percentage-based routing while preserving classic round-robin behavior.
class RoundRobinRuleTest extends TestCase
{
    public function test_round_robin_cycles_through_providers(): void
    {
        $rule = new RoundRobinRule(['a', 'b']);

        $this->assertSame('a', $rule->resolveProvider('chat', [], []));
        $this->assertSame('b', $rule->resolveProvider('chat', [], []));
        $this->assertSame('a', $rule->resolveProvider('chat', [], []));
    }

    public function test_weighted_round_robin_respects_percentages(): void
    {
        $rule = new RoundRobinRule(
            providers: ['anthropic', 'openai'],
            useLoadBalancing: true,
            providerWeights: ['anthropic' => 30, 'openai' => 70],
        );

        $counts = [
            'anthropic' => 0,
            'openai' => 0,
        ];

        for ($i = 0; $i < 100; $i++) {
            $counts[$rule->resolveProvider('chat', [], [])]++;
        }

        $this->assertSame(30, $counts['anthropic']);
        $this->assertSame(70, $counts['openai']);
    }

    public function test_weighted_round_robin_throws_when_weights_do_not_sum_to_100(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must sum to 100');

        new RoundRobinRule(
            providers: ['anthropic', 'openai'],
            useLoadBalancing: true,
            providerWeights: ['anthropic' => 40, 'openai' => 40],
        );
    }
}
