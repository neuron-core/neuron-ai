<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Conversation;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Conversation\UserSimulator;
use NeuronAI\Evaluation\EvaluationException;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

class UserSimulatorTest extends TestCase
{
    protected function makeSimulator(FakeAIProvider $provider): UserSimulator
    {
        $simulator = UserSimulator::make()
            ->withPersona('An impatient customer')
            ->withGoal('Get a refund for order 123');
        $simulator->setAiProvider($provider);

        return $simulator;
    }

    protected function simulatorResponse(bool $stop, ?string $message = null, ?string $reason = null): AssistantMessage
    {
        return new AssistantMessage(json_encode([
            'stop' => $stop,
            'message' => $message,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));
    }

    protected function emptyTrajectory(): Trajectory
    {
        return Trajectory::fromMessages([]);
    }

    public function test_next_turn_returns_the_generated_user_message(): void
    {
        $provider = new FakeAIProvider(
            $this->simulatorResponse(stop: false, message: 'Hi, I need a refund for order 123.'),
        );

        $message = $this->makeSimulator($provider)->nextTurn($this->emptyTrajectory());

        $this->assertInstanceOf(UserMessage::class, $message);
        $this->assertSame('Hi, I need a refund for order 123.', $message->getContent());
    }

    public function test_next_turn_returns_null_on_stop(): void
    {
        $provider = new FakeAIProvider(
            $this->simulatorResponse(stop: true, reason: 'Goal satisfied — the refund was issued.'),
        );

        $message = $this->makeSimulator($provider)->nextTurn($this->emptyTrajectory());

        $this->assertNull($message);
    }

    public function test_next_turn_without_goal_throws(): void
    {
        $simulator = UserSimulator::make()->withPersona('Anyone');
        $simulator->setAiProvider(new FakeAIProvider());

        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage('no goal');

        $simulator->nextTurn($this->emptyTrajectory());
    }

    public function test_prompt_carries_persona_goal_and_transcript(): void
    {
        $provider = new FakeAIProvider(
            $this->simulatorResponse(stop: false, message: 'And what about the shipping costs?'),
        );

        $trajectory = Trajectory::fromMessages([
            new UserMessage('I want a refund for order 123'),
            new AssistantMessage('I have issued the refund.'),
        ]);

        $this->makeSimulator($provider)->nextTurn($trajectory);

        $provider->assertSent(function (RequestRecord $record): bool {
            $content = (string) $record->messages[0]->getContent();
            return str_contains($content, 'An impatient customer') &&
                   str_contains($content, 'Get a refund for order 123') &&
                   str_contains($content, 'User: I want a refund for order 123') &&
                   str_contains($content, 'Assistant: I have issued the refund.');
        });
    }

    public function test_first_turn_prompt_says_conversation_not_started(): void
    {
        $provider = new FakeAIProvider(
            $this->simulatorResponse(stop: false, message: 'Hello!'),
        );

        $this->makeSimulator($provider)->nextTurn($this->emptyTrajectory());

        $provider->assertSent(fn (RequestRecord $record): bool => str_contains(
            (string) $record->messages[0]->getContent(),
            'the conversation has not started yet'
        ));
    }

    public function test_each_step_is_stateless(): void
    {
        $provider = new FakeAIProvider(
            $this->simulatorResponse(stop: false, message: 'First message'),
            $this->simulatorResponse(stop: false, message: 'Second message'),
        );

        $simulator = $this->makeSimulator($provider);
        $simulator->nextTurn($this->emptyTrajectory());
        $simulator->nextTurn($this->emptyTrajectory());

        // The simulator's own history is flushed per step: every request
        // carries exactly one user message (the self-contained prompt).
        $this->assertCount(2, $provider->getRecorded());
        foreach ($provider->getRecorded() as $record) {
            $this->assertCount(1, $record->messages);
        }
    }
}
