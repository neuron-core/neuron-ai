<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Amp\Future;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

class AsyncAgentTest extends TestCase
{
    public function testAsyncClientPattern(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Hello!')
        );

        $agent = Agent::make()->setAiProvider($provider);

        $handler = $agent->chat(new UserMessage('Say hello in one word'));
        $future = async(fn () => $handler->run());

        /** @var AgentState $result */
        $result = $future->await();

        $this->assertInstanceOf(WorkflowState::class, $result);
        $this->assertInstanceOf(AssistantMessage::class, $result->getChatHistory()->getLastMessage());
        $provider->assertCallCount(1);
    }

    public function testConcurrentAgentExecution(): void
    {
        $provider1 = new FakeAIProvider(new AssistantMessage('1, 2, 3'));
        $provider2 = new FakeAIProvider(new AssistantMessage('Red, Green, Blue'));
        $provider3 = new FakeAIProvider(new AssistantMessage('Cat, Dog, Bird'));

        $agent1 = Agent::make()->setAiProvider($provider1)->setInstructions('Count to 3');
        $agent2 = Agent::make()->setAiProvider($provider2)->setInstructions('Name 3 colors');
        $agent3 = Agent::make()->setAiProvider($provider3)->setInstructions('Name 3 animals');

        $future1 = async(fn () => $agent1->chat(new UserMessage('Go'))->run());
        $future2 = async(fn () => $agent2->chat(new UserMessage('Go'))->run());
        $future3 = async(fn () => $agent3->chat(new UserMessage('Go'))->run());

        [$result1, $result2, $result3] = Future\await([$future1, $future2, $future3]);

        $this->assertInstanceOf(AgentState::class, $result1);
        $this->assertInstanceOf(AgentState::class, $result2);
        $this->assertInstanceOf(AgentState::class, $result3);

        $provider1->assertCallCount(1);
        $provider2->assertCallCount(1);
        $provider3->assertCallCount(1);
    }

    public function testMixedAsyncOperations(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Hello!')
        );

        $agent = Agent::make()->setAiProvider($provider);

        $agentFuture = async(fn () => $agent->chat(new UserMessage('Hello'))->run());

        $delayFuture = async(function (): string {
            delay(0.1);
            return 'delay_completed';
        });

        /** @var AgentState $agentResult */
        [$agentResult, $delayResult] = Future\await([$agentFuture, $delayFuture]);

        $this->assertInstanceOf(AgentState::class, $agentResult);
        $this->assertEquals('delay_completed', $delayResult);
        $this->assertInstanceOf(AssistantMessage::class, $agentResult->getChatHistory()->getLastMessage());
        $provider->assertCallCount(1);
    }
}
