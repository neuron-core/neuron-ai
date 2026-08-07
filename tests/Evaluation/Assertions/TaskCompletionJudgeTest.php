<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\Judges\TaskCompletionJudge;
use NeuronAI\Evaluation\Trajectory\Trajectory;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

class TaskCompletionJudgeTest extends TestCase
{
    protected FakeAIProvider $fakeProvider;

    protected function createFakeAgentWithScore(float $score, string $reasoning): AgentInterface
    {
        $this->fakeProvider = FakeAIProvider::make();

        for ($i = 0; $i < 3; $i++) {
            $this->fakeProvider->addResponses(new AssistantMessage(json_encode([
                'score' => $score,
                'reasoning' => $reasoning,
            ], JSON_THROW_ON_ERROR)));
        }

        return Agent::make()->setAiProvider($this->fakeProvider);
    }

    protected function refundTrajectory(): Trajectory
    {
        $tool = ToolCall::make('refund_order', description: 'The refund tool')
            ->setInputs(['order_id' => '123'])
            ->setCallId('call_1')
            ->setResult('refund issued');

        return Trajectory::fromMessages([
            new UserMessage('I want a refund for order 123'),
            new ToolCallMessage(null, [$tool]),
            new ToolResultMessage([$tool]),
            new AssistantMessage('Your refund has been issued.'),
        ]);
    }

    public function testPassesWhenGoalAccomplished(): void
    {
        $agent = $this->createFakeAgentWithScore(0.95, 'The refund was issued as requested.');
        $judge = new TaskCompletionJudge($agent, goal: 'Get a refund for order 123');

        $result = $judge->evaluate($this->refundTrajectory());

        $this->assertTrue($result->passed);
        $this->assertEquals(0.95, $result->score);
    }

    public function testFailsWhenGoalMissed(): void
    {
        $agent = $this->createFakeAgentWithScore(0.2, 'The agent never issued the refund.');
        $judge = new TaskCompletionJudge($agent, goal: 'Get a refund for order 123');

        $result = $judge->evaluate($this->refundTrajectory());

        $this->assertFalse($result->passed);
        $this->assertEquals(0.2, $result->score);
        $this->assertStringContainsString('never issued the refund', $result->message);
    }

    public function testPromptCarriesGoalAndTranscript(): void
    {
        $agent = $this->createFakeAgentWithScore(0.9, 'Accomplished.');
        $judge = new TaskCompletionJudge($agent, goal: 'Get a refund for order 123');

        $judge->evaluate($this->refundTrajectory());

        $this->fakeProvider->assertSent(function (RequestRecord $record): bool {
            $content = (string) $record->messages[0]->getContent();
            return str_contains($content, "User goal:\nGet a refund for order 123") &&
                   str_contains($content, 'User: I want a refund for order 123') &&
                   str_contains($content, 'Tool call: refund_order({"order_id":"123"})') &&
                   str_contains($content, 'Tool result (refund_order): refund issued') &&
                   str_contains($content, "accomplished the user's goal");
        });
    }

    public function testCustomThreshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.6, 'Partially accomplished.');

        $strict = new TaskCompletionJudge($agent, goal: 'Some goal', threshold: 0.8);
        $result = $strict->evaluate($this->refundTrajectory());

        $this->assertFalse($result->passed);
    }
}
