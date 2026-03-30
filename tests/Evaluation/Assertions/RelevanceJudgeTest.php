<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Evaluation\Assertions\Judges\RelevanceJudge;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

class RelevanceJudgeTest extends TestCase
{
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

    public function testPassesWhenOutputIsRelevantToQuestion(): void
    {
        $agent = $this->createFakeAgentWithScore(0.95, 'Directly addresses the question asked.');
        // RelevanceJudge takes: AgentInterface $judge, string $question, float $threshold = 0.7, array $examples = []
        $assertion = new RelevanceJudge($agent, 'What is PHP?', 0.7);

        $result = $assertion->evaluate('PHP is a server-side scripting language designed for web development.');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.95, $result->score);
    }

    public function testFailsWhenOutputIsOffTopic(): void
    {
        $agent = $this->createFakeAgentWithScore(0.2, 'Response is about Python, not PHP.');
        $assertion = new RelevanceJudge($agent, 'What is PHP?', 0.7);

        $result = $assertion->evaluate('Python is a versatile programming language used for data science.');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.2, $result->score);
    }

    public function testPassesWithPartiallyRelevantAnswer(): void
    {
        $agent = $this->createFakeAgentWithScore(0.75, 'Addresses the main topic but includes some tangential information.');
        $assertion = new RelevanceJudge($agent, 'Explain MVC architecture', 0.7);

        $result = $assertion->evaluate('MVC separates concerns into Models, Views, and Controllers. Laravel is a popular PHP framework.');

        $this->assertTrue($result->passed);
    }

    public function testFailsWithCompletelyIrrelevantAnswer(): void
    {
        $agent = $this->createFakeAgentWithScore(0.1, 'No relation to the original question.');
        $assertion = new RelevanceJudge($agent, 'How do I connect to MySQL?', 0.7);

        $result = $assertion->evaluate('The weather today is sunny and warm.');

        $this->assertFalse($result->passed);
    }

    public function testIncludesQuestionInPrompt(): void
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

        $fakeProvider->assertSent(function ($record) use ($question): bool {
            $content = $record->messages[0]->getContent();
            return str_contains($content, 'Original question:') &&
                   str_contains($content, $question);
        });
    }

    public function testUsesRelevanceCriteria(): void
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

        $fakeProvider->assertSent(function ($record): bool {
            $content = $record->messages[0]->getContent();
            return str_contains($content, 'directly addresses') &&
                   str_contains($content, 'tangents');
        });
    }

    public function testSupportsExamplesForCalibration(): void
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

    public function testDefaultThreshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.7, 'At default threshold');
        $assertion = new RelevanceJudge($agent, 'Question?');

        $result = $assertion->evaluate('Answer');

        $this->assertTrue($result->passed);
    }

    public function testGetName(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Test');
        $assertion = new RelevanceJudge($agent, 'Question?');

        $this->assertEquals('RelevanceJudge', $assertion->getName());
    }

    public function testFailsWithNonStringInput(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Should not be called');
        $assertion = new RelevanceJudge($agent, 'Question?', 0.5);

        $result = $assertion->evaluate(123);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected actual value to be a string', $result->message);
    }
}
