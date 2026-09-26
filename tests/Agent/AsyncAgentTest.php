<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Amp\Future;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;
use function array_map;

class AsyncAgentTest extends TestCase
{
    public function test_async_client_pattern(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Hello!')
        );

        $agent = Agent::make()->setAiProvider($provider);

        $future = async(fn (): AgentState => $agent->chat(new UserMessage('Say hello in one word')));

        $result = $future->await();

        $this->assertInstanceOf(AgentState::class, $result);
        $this->assertSame('Hello!', $result->getMessage()?->getContent());
        $this->assertSame(
            ['Say hello in one word', 'Hello!'],
            array_map(static fn (Message $message): ?string => $message->getContent(), $agent->getChatHistory()->getMessages())
        );
        $provider->assertCallCount(1);
    }

    public function test_concurrent_agents_keep_their_own_configuration_and_conversation(): void
    {
        $provider1 = new FakeAIProvider(new AssistantMessage('1, 2, 3'));
        $provider2 = new FakeAIProvider(new AssistantMessage('Red, Green, Blue'));
        $provider3 = new FakeAIProvider(new AssistantMessage('Cat, Dog, Bird'));

        $agent1 = Agent::make()->setAiProvider($provider1)->setInstructions('Count to 3');
        $agent2 = Agent::make()->setAiProvider($provider2)->setInstructions('Name 3 colors');
        $agent3 = Agent::make()->setAiProvider($provider3)->setInstructions('Name 3 animals');

        $future1 = async(fn (): AgentState => $agent1->chat(new UserMessage('Go 1')));
        $future2 = async(fn (): AgentState => $agent2->chat(new UserMessage('Go 2')));
        $future3 = async(fn (): AgentState => $agent3->chat(new UserMessage('Go 3')));

        [$result1, $result2, $result3] = Future\await([$future1, $future2, $future3]);

        $this->assertSame('1, 2, 3', $result1->getMessage()?->getContent());
        $this->assertSame('Red, Green, Blue', $result2->getMessage()?->getContent());
        $this->assertSame('Cat, Dog, Bird', $result3->getMessage()?->getContent());

        foreach ([[$provider1, 'Count to 3', 'Go 1'], [$provider2, 'Name 3 colors', 'Go 2'], [$provider3, 'Name 3 animals', 'Go 3']] as [$provider, $instructions, $question]) {
            $provider->assertCallCount(1);
            $record = $provider->getRecorded()[0];
            $this->assertSame($instructions, $record->systemPrompt?->getContent());
            $this->assertSame([$question], array_map(static fn (Message $message): ?string => $message->getContent(), $record->messages));
        }

        $this->assertNotSame($agent1->getThreadId(), $agent2->getThreadId());
        $this->assertNotSame($agent2->getThreadId(), $agent3->getThreadId());
    }

    public function test_mixed_async_operations(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Hello!')
        );

        $agent = Agent::make()->setAiProvider($provider);

        $agentFuture = async(fn (): AgentState => $agent->chat(new UserMessage('Hello')));
        $delayFuture = async(function (): string {
            delay(0.001);
            return 'delay_completed';
        });

        /** @var AgentState $agentResult */
        [$agentResult, $delayResult] = Future\await([$agentFuture, $delayFuture]);

        $this->assertSame('Hello!', $agentResult->getMessage()?->getContent());
        $this->assertSame('delay_completed', $delayResult);
        $this->assertInstanceOf(AssistantMessage::class, $agent->getChatHistory()->getLastMessage());
        $provider->assertCallCount(1);
    }
}
