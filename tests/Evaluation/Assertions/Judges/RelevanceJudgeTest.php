<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Judges;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Evaluation\Assertions\Judges\RelevanceJudge;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

class RelevanceJudgeTest extends TestCase
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

    public function test_passes_when_output_is_relevant_to_question(): void
    {
        $agent = $this->createFakeAgentWithScore(0.95, 'Directly addresses the question asked.');
        // RelevanceJudge takes: AgentInterface $judge, string $question, float $threshold = 0.7, array $examples = []
        $assertion = new RelevanceJudge($agent, 'What is PHP?', 0.7);

        $result = $assertion->evaluate('PHP is a server-side scripting language designed for web development.');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.95, $result->score);
    }

    public function test_fails_when_output_is_off_topic(): void
    {
        $agent = $this->createFakeAgentWithScore(0.2, 'Response is about Python, not PHP.');
        $assertion = new RelevanceJudge($agent, 'What is PHP?', 0.7);

        $result = $assertion->evaluate('Python is a versatile programming language used for data science.');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.2, $result->score);
    }

    public function test_passes_with_partially_relevant_answer(): void
    {
        $agent = $this->createFakeAgentWithScore(0.75, 'Addresses the main topic but includes some tangential information.');
        $assertion = new RelevanceJudge($agent, 'Explain MVC architecture', 0.7);

        $result = $assertion->evaluate('MVC separates concerns into Models, Views, and Controllers. Laravel is a popular PHP framework.');

        $this->assertTrue($result->passed);
    }

    public function test_fails_with_completely_irrelevant_answer(): void
    {
        $agent = $this->createFakeAgentWithScore(0.1, 'No relation to the original question.');
        $assertion = new RelevanceJudge($agent, 'How do I connect to MySQL?', 0.7);

        $result = $assertion->evaluate('The weather today is sunny and warm.');

        $this->assertFalse($result->passed);
    }

    public function test_includes_question_in_prompt(): void
    {
        $fakeProvider = FakeAIProvider::make();
        for ($i = 0; $i < 3; $i++) {
            $fakeProvider->addResponses(new AssistantMessage(json_encode([
                'score' => 0.8,
                'reasoning' => 'Relevant',
            ], JSON_THROW_ON_ERROR)));
        }

        $agent = Agent::make()->setAiProvider($fakeProvider);
        $question = 'What are the benefits of unit testing?';
        $assertion = new RelevanceJudge($agent, $question, 0.7);

        $assertion->evaluate('Unit testing helps catch bugs early and improves code quality.');

        $fakeProvider->assertSent(function (RequestRecord $record) use ($question): bool {
            $content = $record->messages[0]->getContent();
            return str_contains($content, 'Original question:') &&
                   str_contains($content, $question);
        });
    }

    public function test_uses_relevance_criteria(): void
    {
        $fakeProvider = FakeAIProvider::make();
        for ($i = 0; $i < 3; $i++) {
            $fakeProvider->addResponses(new AssistantMessage(json_encode([
                'score' => 0.75,
                'reasoning' => 'Test',
            ], JSON_THROW_ON_ERROR)));
        }

        $agent = Agent::make()->setAiProvider($fakeProvider);
        $assertion = new RelevanceJudge($agent, 'Question?', 0.7);

        $assertion->evaluate('Answer');

        $fakeProvider->assertSent(function (RequestRecord $record): bool {
            $content = $record->messages[0]->getContent();
            return str_contains($content, 'directly addresses') &&
                   str_contains($content, 'tangents');
        });
    }

    public function test_supports_examples_for_calibration(): void
    {
        $agent = $this->createFakeAgentWithScore(0.85, 'Follows the pattern of relevant responses.');
        $assertion = new RelevanceJudge(
            judge: $agent,
            question: 'What is dependency injection?',
            threshold: 0.7,
            examples: [
                [
                    'input' => 'What is a class?',
                    'output' => 'A class is a blueprint for creating objects in OOP.',
                    'score' => 1.0,
                    'reasoning' => 'Directly answers what a class is',
                ],
            ]
        );

        $result = $assertion->evaluate('Dependency injection is a pattern where dependencies are provided externally.');

        $this->assertTrue($result->passed);
    }

    public function test_default_threshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.7, 'At default threshold');
        $assertion = new RelevanceJudge($agent, 'Question?');

        $result = $assertion->evaluate('Answer');

        $this->assertTrue($result->passed);
    }

    public function test_get_name(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Test');
        $assertion = new RelevanceJudge($agent, 'Question?');

        $this->assertEquals('RelevanceJudge', $assertion->getName());
    }

    public function test_fails_with_non_string_input(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Should not be called');
        $assertion = new RelevanceJudge($agent, 'Question?', 0.5);

        $this->expectException(InvalidArgumentException::class);

        $assertion->evaluate(123);
    }
}
