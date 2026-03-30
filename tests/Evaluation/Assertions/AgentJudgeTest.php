<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\TestCase;

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
    protected function createFakeAgentWithScore(float $score, string $reasoning, int $responseCount = 3): Agent
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

    public function testPassesWhenScoreAboveThreshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.85, 'The output meets the criteria.');
        $assertion = new AgentJudge($agent, 'Check if output is helpful', 0.7);

        $result = $assertion->evaluate('This is a helpful response.');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.85, $result->score);
        $this->assertEquals('The output meets the criteria.', $result->message);
    }

    public function testPassesWhenScoreEqualsThreshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.7, 'Exactly at threshold');
        $assertion = new AgentJudge($agent, 'Check quality', 0.7);

        $result = $assertion->evaluate('Some output');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.7, $result->score);
    }

    public function testFailsWhenScoreBelowThreshold(): void
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

    public function testFailsWithPerfectScoreBelowThreshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.0, 'Complete failure');
        $assertion = new AgentJudge($agent, 'Check accuracy', 0.5);

        $result = $assertion->evaluate('Wrong answer');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
    }

    public function testPassesWithPerfectScore(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Perfect response');
        $assertion = new AgentJudge($agent, 'Check completeness', 0.9);

        $result = $assertion->evaluate('Complete and accurate response');

        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->score);
    }

    public function testFailsWithNonStringInput(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Should not be called');
        $assertion = new AgentJudge($agent, 'Check format', 0.5);

        $result = $assertion->evaluate(['array', 'input']);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got array', $result->message);
    }

    public function testFailsWithIntegerInput(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Should not be called');
        $assertion = new AgentJudge($agent, 'Check value', 0.5);

        $result = $assertion->evaluate(123);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got integer', $result->message);
    }

    public function testFailsWithNullInput(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Should not be called');
        $assertion = new AgentJudge($agent, 'Check content', 0.5);

        $result = $assertion->evaluate(null);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertEquals('Expected actual value to be a string, got NULL', $result->message);
    }

    public function testIncludesReferenceInPrompt(): void
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
        $fakeProvider->assertSent(function ($record): bool {
            $messages = $record->messages;
            $lastMessage = $messages[count($messages) - 1];
            return str_contains((string) $lastMessage->getContent(), 'Expected (Reference):') &&
                   str_contains((string) $lastMessage->getContent(), 'Expected output example');
        });
    }

    public function testIncludesExamplesInPrompt(): void
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
            reference: null,
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
        $fakeProvider->assertSent(function ($record): bool {
            $content = $record->messages[0]->getContent();
            return str_contains($content, 'Examples of graded outputs:') &&
                   str_contains($content, 'What is PHP?');
        });
    }

    public function testBuildContextInResult(): void
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

    public function testGetName(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Test');
        $assertion = new AgentJudge($agent, 'Test criteria');

        $this->assertEquals('AgentJudge', $assertion->getName());
    }

    public function testDefaultThreshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.7, 'At default threshold');
        $assertion = new AgentJudge($agent, 'Check output');

        $result = $assertion->evaluate('Some output');

        // Default threshold is 0.7
        $this->assertTrue($result->passed);
    }

    public function testPromptContainsAllSections(): void
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

        $fakeProvider->assertSent(function ($record): bool {
            $content = $record->messages[0]->getContent();
            return str_contains($content, 'Criteria:') &&
                   str_contains($content, 'Expected (Reference):') &&
                   str_contains($content, 'Actual Output:') &&
                   str_contains($content, 'Examples of graded outputs:') &&
                   str_contains($content, 'Provide a score between 0.0 and 1.0');
        });
    }
}
