<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\Trajectory\Trajectory;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

use function count;
use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

class AgentJudgeTest extends TestCase
{
    /**
     * Create a fake agent with predetermined judge score.
     * Adds multiple responses to handle potential retries.
     */
    protected function createFakeAgentWithScore(float $score, string $reasoning, int $responseCount = 3): AgentInterface
    {
        $fakeProvider = FakeAIProvider::make();

        $response = new AssistantMessage(json_encode([
            'score' => $score,
            'reasoning' => $reasoning,
        ], JSON_THROW_ON_ERROR));

        for ($i = 0; $i < $responseCount; $i++) {
            $fakeProvider->addResponses($response);
        }

        return Agent::make()->setAiProvider($fakeProvider);
    }

    public function test_passes_when_score_above_threshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.85, 'The output meets the criteria.');
        $assertion = new AgentJudge($agent, 'Check if output is helpful', 0.7);

        $result = $assertion->evaluate('This is a helpful response.');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.85, $result->score);
        $this->assertEquals('The output meets the criteria.', $result->message);
    }

    public function test_passes_when_score_equals_threshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.7, 'Exactly at threshold');
        $assertion = new AgentJudge($agent, 'Check quality', 0.7);

        $result = $assertion->evaluate('Some output');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.7, $result->score);
    }

    public function test_fails_when_score_below_threshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.5, 'Output does not meet criteria');
        $assertion = new AgentJudge($agent, 'Check quality', 0.7);

        $result = $assertion->evaluate('Poor quality output');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.5, $result->score);
        $this->assertStringContainsString('0.5', $result->message);
        $this->assertStringContainsString('0.7', $result->message);
        $this->assertStringContainsString('Output does not meet criteria', $result->message);
    }

    public function test_fails_with_perfect_score_below_threshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.0, 'Complete failure');
        $assertion = new AgentJudge($agent, 'Check accuracy', 0.5);

        $result = $assertion->evaluate('Wrong answer');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
    }

    public function test_passes_with_perfect_score(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Perfect response');
        $assertion = new AgentJudge($agent, 'Check completeness', 0.9);

        $result = $assertion->evaluate('Complete and accurate response');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function test_fails_with_non_string_input(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Should not be called');
        $assertion = new AgentJudge($agent, 'Check format', 0.5);

        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(['array', 'input']);
    }

    public function test_fails_with_integer_input(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Should not be called');
        $assertion = new AgentJudge($agent, 'Check value', 0.5);

        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }

    public function test_fails_with_null_input(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Should not be called');
        $assertion = new AgentJudge($agent, 'Check content', 0.5);

        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(null);
    }

    public function test_includes_reference_in_prompt(): void
    {
        $fakeProvider = FakeAIProvider::make();
        for ($i = 0; $i < 3; $i++) {
            $fakeProvider->addResponses(new AssistantMessage(json_encode([
                'score' => 0.9,
                'reasoning' => 'Good match',
            ], JSON_THROW_ON_ERROR)));
        }

        $agent = Agent::make()->setAiProvider($fakeProvider);
        $assertion = new AgentJudge(
            judge: $agent,
            criteria: 'Check accuracy',
            threshold: 0.7,
            reference: 'Expected output example'
        );

        $result = $assertion->evaluate('Actual output');

        $this->assertTrue($result->passed);

        // Verify the reference was included in the prompt
        $fakeProvider->assertSent(function (RequestRecord $record): bool {
            $messages = $record->messages;
            $lastMessage = $messages[count($messages) - 1];
            return str_contains((string) $lastMessage->getContent(), 'Expected (Reference):') &&
                   str_contains((string) $lastMessage->getContent(), 'Expected output example');
        });
    }

    public function test_includes_examples_in_prompt(): void
    {
        $fakeProvider = FakeAIProvider::make();
        for ($i = 0; $i < 3; $i++) {
            $fakeProvider->addResponses(new AssistantMessage(json_encode([
                'score' => 0.8,
                'reasoning' => 'Decent output',
            ], JSON_THROW_ON_ERROR)));
        }

        $agent = Agent::make()->setAiProvider($fakeProvider);
        $assertion = new AgentJudge(
            judge: $agent,
            criteria: 'Evaluate tone',
            threshold: 0.6,
            examples: [
                [
                    'input' => 'What is PHP?',
                    'output' => 'PHP is a scripting language.',
                    'score' => 0.9,
                    'reasoning' => 'Accurate and concise',
                ],
            ]
        );

        $result = $assertion->evaluate('PHP is great!');

        $this->assertTrue($result->passed);

        // Verify the examples were included in the prompt
        $fakeProvider->assertSent(function (RequestRecord $record): bool {
            $content = $record->messages[0]->getContent();
            return str_contains($content, 'Examples of graded outputs:') &&
                   str_contains($content, 'What is PHP?');
        });
    }

    public function test_build_context_in_result(): void
    {
        $agent = $this->createFakeAgentWithScore(0.75, 'Passable');
        $assertion = new AgentJudge(
            judge: $agent,
            criteria: 'Custom criteria',
            threshold: 0.7,
            reference: 'Reference text'
        );

        $result = $assertion->evaluate('Test output');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.7, $result->context['threshold']);
        $this->assertEquals('Custom criteria', $result->context['criteria']);
        $this->assertEquals('Reference text', $result->context['reference']);
    }

    public function test_get_name(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Test');
        $assertion = new AgentJudge($agent, 'Test criteria');

        $this->assertEquals('AgentJudge', $assertion->getName());
    }

    public function test_default_threshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.7, 'At default threshold');
        $assertion = new AgentJudge($agent, 'Check output');

        $result = $assertion->evaluate('Some output');

        // Default threshold is 0.7
        $this->assertTrue($result->passed);
    }

    public function test_prompt_contains_all_sections(): void
    {
        $fakeProvider = FakeAIProvider::make();
        for ($i = 0; $i < 3; $i++) {
            $fakeProvider->addResponses(new AssistantMessage(json_encode([
                'score' => 0.8,
                'reasoning' => 'Good',
            ], JSON_THROW_ON_ERROR)));
        }

        $agent = Agent::make()->setAiProvider($fakeProvider);
        $assertion = new AgentJudge(
            judge: $agent,
            criteria: 'Evaluate completeness',
            threshold: 0.7,
            reference: 'Reference output',
            examples: [
                [
                    'input' => 'Q1',
                    'output' => 'A1',
                    'score' => 0.9,
                    'reasoning' => 'Good',
                ],
            ]
        );

        $assertion->evaluate('Test output');

        $fakeProvider->assertSent(function (RequestRecord $record): bool {
            $content = $record->messages[0]->getContent();
            return str_contains($content, 'Criteria:') &&
                   str_contains($content, 'Expected (Reference):') &&
                   str_contains($content, 'Actual Output:') &&
                   str_contains($content, 'Examples of graded outputs:') &&
                   str_contains($content, 'Provide a score between 0.0 and 1.0');
        });
    }

    public function test_evaluates_trajectory_by_rendering_its_transcript(): void
    {
        $fakeProvider = FakeAIProvider::make();
        for ($i = 0; $i < 3; $i++) {
            $fakeProvider->addResponses(new AssistantMessage(json_encode([
                'score' => 0.9,
                'reasoning' => 'The conversation handled the rejection gracefully.',
            ], JSON_THROW_ON_ERROR)));
        }
        $agent = Agent::make()->setAiProvider($fakeProvider);
        $assertion = new AgentJudge($agent, 'Judge the conversation', 0.7);

        $tool = ToolCall::make('refund_order', description: 'The refund tool')
            ->setInputs(['order_id' => '123'])
            ->setCallId('call_1');
        $tool->setApprovalState(ApprovalState::Rejected, 'too expensive');
        $tool->setResult('rejected by the user');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Refund order 123'),
            new ToolCallMessage(null, [$tool]),
            new ToolResultMessage([$tool]),
            new AssistantMessage('I cannot process the refund.'),
        ]);

        $result = $assertion->evaluate($trajectory);

        $this->assertTrue($result->passed);
        $this->assertEquals(0.9, $result->score);
        $fakeProvider->assertSent(function (RequestRecord $record): bool {
            $content = (string) $record->messages[0]->getContent();
            return str_contains($content, 'User: Refund order 123') &&
                   str_contains($content, 'Tool call: refund_order({"order_id":"123"})') &&
                   str_contains($content, '[rejected: too expensive]') &&
                   str_contains($content, 'Assistant: I cannot process the refund.');
        });
    }
}
