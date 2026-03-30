<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Evaluation\Assertions\Judges\CorrectnessJudge;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

class CorrectnessJudgeTest extends TestCase
{
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

    public function testPassesWhenOutputMatchesExpectedSemantically(): void
    {
        $agent = $this->createFakeAgentWithScore(0.9, 'The outputs convey the same meaning.');
        $assertion = new CorrectnessJudge($agent, 'The capital of France is Paris.', 0.7);

        $result = $assertion->evaluate('Paris is the capital city of France.');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.9, $result->score);
    }

    public function testFailsWhenOutputHasDifferentMeaning(): void
    {
        $agent = $this->createFakeAgentWithScore(0.3, 'The output states incorrect information.');
        $assertion = new CorrectnessJudge($agent, 'The capital of France is Paris.', 0.7);

        $result = $assertion->evaluate('Lyon is the capital of France.');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.3, $result->score);
    }

    public function testPassesWithParaphrasedContent(): void
    {
        $agent = $this->createFakeAgentWithScore(0.85, 'Semantically equivalent despite different wording.');
        $assertion = new CorrectnessJudge($agent, 'The sky is blue during the day.', 0.7);

        $result = $assertion->evaluate('During daytime, the sky appears blue.');

        $this->assertTrue($result->passed);
    }

    public function testFailsWithFactualErrors(): void
    {
        $agent = $this->createFakeAgentWithScore(0.2, 'Contains factual error - wrong year.');
        $assertion = new CorrectnessJudge($agent, 'World War II ended in 1945.', 0.7);

        $result = $assertion->evaluate('World War II ended in 1946.');

        $this->assertFalse($result->passed);
    }

    public function testIncludesExpectedOutputInPrompt(): void
    {
        $fakeProvider = FakeAIProvider::make();
        for ($i = 0; $i < 3; $i++) {
            $fakeProvider->addResponses(new AssistantMessage(json_encode([
                'score' => 0.8,
                'reasoning' => 'Close enough',
            ], JSON_THROW_ON_ERROR)));
        }

        $agent = Agent::make()->setAiProvider($fakeProvider);
        $assertion = new CorrectnessJudge($agent, 'Expected answer', 0.7);

        $assertion->evaluate('Actual answer');

        $fakeProvider->assertSent(function (RequestRecord $record): bool {
            $content = $record->messages[0]->getContent();
            return str_contains($content, 'Expected (Reference):') &&
                   str_contains($content, 'Expected answer');
        });
    }

    public function testUsesSemanticEquivalenceCriteria(): void
    {
        $fakeProvider = FakeAIProvider::make();
        for ($i = 0; $i < 3; $i++) {
            $fakeProvider->addResponses(new AssistantMessage(json_encode([
                'score' => 0.75,
                'reasoning' => 'Test',
            ], JSON_THROW_ON_ERROR)));
        }

        $agent = Agent::make()->setAiProvider($fakeProvider);
        $assertion = new CorrectnessJudge($agent, 'Reference', 0.7);

        $assertion->evaluate('Output');

        $fakeProvider->assertSent(function (RequestRecord $record): bool {
            $content = $record->messages[0]->getContent();
            return str_contains($content, 'semantic equivalence') &&
                   str_contains($content, 'factual errors');
        });
    }

    public function testSupportsExamplesForCalibration(): void
    {
        $agent = $this->createFakeAgentWithScore(0.9, 'Matches the pattern of high-scoring examples.');
        $assertion = new CorrectnessJudge(
            judge: $agent,
            expected: 'The answer is 42.',
            threshold: 0.7,
            examples: [
                [
                    'input' => 'What is 6 * 7?',
                    'output' => 'The result is 42.',
                    'score' => 1.0,
                    'reasoning' => 'Correct answer with slightly different wording',
                ],
            ]
        );

        $result = $assertion->evaluate('42 is the answer.');

        $this->assertTrue($result->passed);
    }

    public function testDefaultThreshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.7, 'At default threshold');
        $assertion = new CorrectnessJudge($agent, 'Expected');

        $result = $assertion->evaluate('Output');

        $this->assertTrue($result->passed);
    }

    public function testGetName(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Test');
        $assertion = new CorrectnessJudge($agent, 'Expected');

        $this->assertEquals('CorrectnessJudge', $assertion->getName());
    }

    public function testFailsWithNonStringInput(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Should not be called');
        $assertion = new CorrectnessJudge($agent, 'Expected', 0.5);

        $result = $assertion->evaluate(123);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected actual value to be a string', $result->message);
    }
}
