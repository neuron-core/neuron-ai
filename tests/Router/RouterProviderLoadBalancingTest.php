<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Router;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Router\RouterProvider;
use NeuronAI\Router\Rules\RoundRobinRule;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\TestCase;

use function range;

// Added integration coverage for per-provider load balancing flags so weighted
// utilization rates are enforced only when explicitly enabled.
class RouterProviderLoadBalancingTest extends TestCase
{
    public function test_router_provider_uses_weighted_load_balancing_when_configured(): void
    {
        $anthropic = $this->fakeProviderWithResponses('anthropic', 100);
        $openai = $this->fakeProviderWithResponses('openai', 100);

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic, useLoadBalancing: true, utilizationRate: 30)
            ->addProvider('openai', $openai, useLoadBalancing: true, utilizationRate: 70)
            ->setRule(new RoundRobinRule(['anthropic', 'openai']));

        foreach (range(1, 100) as $_) {
            $router->chat(UserMessage::make('hello'));
        }

        $this->assertSame(30, $anthropic->getCallCount());
        $this->assertSame(70, $openai->getCallCount());
    }

    public function test_router_provider_keeps_standard_round_robin_when_not_configured(): void
    {
        $providerA = $this->fakeProviderWithResponses('a', 20);
        $providerB = $this->fakeProviderWithResponses('b', 20);

        $router = RouterProvider::make()
            ->addProvider('a', $providerA)
            ->addProvider('b', $providerB)
            ->setRule(new RoundRobinRule(['a', 'b']));

        foreach (range(1, 10) as $_) {
            $router->chat(UserMessage::make('hi'));
        }

        $this->assertSame(5, $providerA->getCallCount());
        $this->assertSame(5, $providerB->getCallCount());
    }

    protected function fakeProviderWithResponses(string $label, int $count): FakeAIProvider
    {
        $messages = [];

        foreach (range(1, $count) as $index) {
            $messages[] = new AssistantMessage("{$label}-{$index}");
        }

        return new FakeAIProvider(...$messages);
    }
}
