<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Judges;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Evaluation\Assertions\Judges\CorrectnessJudge;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

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

    public function test_passes_when_output_matches_expected_semantically(): void
    {
        $agent = $this->createFakeAgentWithScore(0.9, 'The outputs convey the same meaning.');
        $assertion = new CorrectnessJudge($agent, 'The capital of France is Paris.', 0.7);

        $result = $assertion->evaluate('Paris is the capital city of France.');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.9, $result->score);
    }

    public function test_fails_when_output_has_different_meaning(): void
    {
        $agent = $this->createFakeAgentWithScore(0.3, 'The output states incorrect information.');
        $assertion = new CorrectnessJudge($agent, 'The capital of France is Paris.', 0.7);

        $result = $assertion->evaluate('Lyon is the capital of France.');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.3, $result->score);
    }

    public function test_passes_with_paraphrased_content(): void
    {
        $agent = $this->createFakeAgentWithScore(0.85, 'Semantically equivalent despite different wording.');
        $assertion = new CorrectnessJudge($agent, 'The sky is blue during the day.', 0.7);

        $result = $assertion->evaluate('During daytime, the sky appears blue.');

        $this->assertTrue($result->passed);
    }

    public function test_fails_with_factual_errors(): void
    {
        $agent = $this->createFakeAgentWithScore(0.2, 'Contains factual error - wrong year.');
        $assertion = new CorrectnessJudge($agent, 'World War II ended in 1945.', 0.7);

        $result = $assertion->evaluate('World War II ended in 1946.');

        $this->assertFalse($result->passed);
    }

    public function test_includes_expected_output_in_prompt(): void
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

    public function test_uses_semantic_equivalence_criteria(): void
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

    public function test_supports_examples_for_calibration(): void
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

    public function test_default_threshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.7, 'At default threshold');
        $assertion = new CorrectnessJudge($agent, 'Expected');

        $result = $assertion->evaluate('Output');

        $this->assertTrue($result->passed);
    }

    public function test_get_name(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Test');
        $assertion = new CorrectnessJudge($agent, 'Expected');

        $this->assertEquals('CorrectnessJudge', $assertion->getName());
    }

    public function test_fails_with_non_string_input(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Should not be called');
        $assertion = new CorrectnessJudge($agent, 'Expected', 0.5);

        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }
}
