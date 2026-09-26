<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Conversation\UserSimulator;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\TestCase;

class StructuredOutputRequiredFieldsTest extends TestCase
{
    public function test_judge_answer_missing_reasoning_is_retried_and_corrected(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('{"score":0.9}'),
            new AssistantMessage('{"score":0.9,"reasoning":"Accurate and complete"}'),
        );
        $agent = Agent::make()->setAiProvider($provider);

        $result = (new AgentJudge($agent, 'Check quality'))->evaluate('Some output');

        $this->assertSame('Accurate and complete', $result->message);
        $provider->assertCallCount(2);
    }

    public function test_simulator_answer_missing_stop_is_retried_and_corrected(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('{"message":"Hello"}'),
            new AssistantMessage('{"stop":false,"message":"I want a refund"}'),
        );
        $simulator = UserSimulator::make()->withGoal('Get a refund');
        $simulator->setAiProvider($provider);

        $turn = $simulator->nextTurn(Trajectory::fromMessages([]));

        $this->assertSame('I want a refund', $turn?->getContent());
        $provider->assertCallCount(2);
    }

    public function test_simulator_answer_without_the_optional_message_ends_the_conversation(): void
    {
        $simulator = UserSimulator::make()->withGoal('Get a refund');
        $simulator->setAiProvider(new FakeAIProvider(new AssistantMessage('{"stop":false}')));

        $this->assertNull($simulator->nextTurn(Trajectory::fromMessages([])));
    }
}
