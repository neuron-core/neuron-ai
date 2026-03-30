<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Evaluation\Assertions\Judges\HelpfulnessJudge;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

class HelpfulnessJudgeTest extends TestCase
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

    public function testPassesWhenOutputIsHelpful(): void
    {
        $agent = $this->createFakeAgentWithScore(0.9, 'Provides clear, actionable steps.');
        // HelpfulnessJudge takes: AgentInterface $judge, float $threshold = 0.7, array $examples = []
        $assertion = new HelpfulnessJudge($agent, 0.7);

        $result = $assertion->evaluate('Run: curl -sS https://getcomposer.org/installer | php');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.9, $result->score);
    }

    public function testFailsWhenOutputIsNotActionable(): void
    {
        $agent = $this->createFakeAgentWithScore(0.3, 'Response is vague and not actionable.');
        $assertion = new HelpfulnessJudge($agent, 0.7);

        $result = $assertion->evaluate('You should try to debug it.');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.3, $result->score);
    }

    public function testPassesWithCompleteAnswer(): void
    {
        $agent = $this->createFakeAgentWithScore(0.95, 'Provides complete answer with examples.');
        $assertion = new HelpfulnessJudge($agent, 0.7);

        $result = $assertion->evaluate('Dependency injection is a design pattern where dependencies are passed to objects rather than created internally. For example: class UserService { public function __construct(private Logger $logger) {} }');

        $this->assertTrue($result->passed);
    }

    public function testFailsWithEvasiveAnswer(): void
    {
        $agent = $this->createFakeAgentWithScore(0.2, 'Does not actually answer the question.');
        $assertion = new HelpfulnessJudge($agent, 0.7);

        $result = $assertion->evaluate('Pricing depends on various factors.');

        $this->assertFalse($result->passed);
    }

    public function testUsesHelpfulnessCriteria(): void
    {
        $fakeProvider = FakeAIProvider::make();
        for ($i = 0; $i < 3; $i++) {
            $fakeProvider->addResponses(new AssistantMessage(json_encode([
                'score' => 0.75,
                'reasoning' => 'Test',
            ], JSON_THROW_ON_ERROR)));
        }

        $agent = Agent::make()->setAiProvider($fakeProvider);
        $assertion = new HelpfulnessJudge($agent, 0.7);

        $assertion->evaluate('Answer');

        $fakeProvider->assertSent(function (RequestRecord $record): bool {
            $content = $record->messages[0]->getContent();
            return str_contains($content, 'actionable') &&
                   str_contains($content, 'practical');
        });
    }

    public function testSupportsExamplesForCalibration(): void
    {
        $agent = $this->createFakeAgentWithScore(0.85, 'Similar to high-scoring examples.');
        $assertion = new HelpfulnessJudge(
            judge: $agent,
            threshold: 0.7,
            examples: [
                [
                    'input' => 'How to check if string is empty?',
                    'output' => 'Use empty($str) function which returns true for empty strings.',
                    'score' => 0.9,
                    'reasoning' => 'Direct answer with function name and usage',
                ],
            ]
        );

        $result = $assertion->evaluate('Use filter_var($email, FILTER_VALIDATE_EMAIL) to validate emails.');

        $this->assertTrue($result->passed);
    }

    public function testDefaultThreshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.7, 'At default threshold');
        $assertion = new HelpfulnessJudge($agent);

        $result = $assertion->evaluate('Answer');

        $this->assertTrue($result->passed);
    }

    public function testGetName(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Test');
        $assertion = new HelpfulnessJudge($agent);

        $this->assertEquals('HelpfulnessJudge', $assertion->getName());
    }

    public function testFailsWithNonStringInput(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Should not be called');
        $assertion = new HelpfulnessJudge($agent, 0.5);

        $result = $assertion->evaluate(null);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected actual value to be a string', $result->message);
    }
}
