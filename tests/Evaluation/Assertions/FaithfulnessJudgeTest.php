<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Evaluation\Assertions\Judges\FaithfulnessJudge;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

class FaithfulnessJudgeTest extends TestCase
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

    public function testPassesWhenOutputIsGroundedInContext(): void
    {
        $context = 'The Eiffel Tower is located in Paris, France. It was completed in 1889.';
        $agent = $this->createFakeAgentWithScore(0.95, 'All claims are supported by the context.');
        $assertion = new FaithfulnessJudge($agent, $context, 0.7);

        $result = $assertion->evaluate('The Eiffel Tower in Paris was built in 1889.');

        $this->assertTrue($result->passed);
        $this->assertEquals(0.95, $result->score);
    }

    public function testFailsWhenOutputContainsHallucination(): void
    {
        $context = 'The Eiffel Tower is located in Paris, France.';
        $agent = $this->createFakeAgentWithScore(0.3, 'The output mentions height which is not in the context.');
        $assertion = new FaithfulnessJudge($agent, $context, 0.7);

        $result = $assertion->evaluate('The Eiffel Tower in Paris is 324 meters tall.');

        $this->assertFalse($result->passed);
        $this->assertEquals(0.3, $result->score);
    }

    public function testPassesWithOnlyContextInformation(): void
    {
        $context = 'PHP was created by Rasmus Lerdorf in 1994.';
        $agent = $this->createFakeAgentWithScore(1.0, 'Only uses information from the context.');
        $assertion = new FaithfulnessJudge($agent, $context, 0.7);

        $result = $assertion->evaluate('Rasmus Lerdorf created PHP in 1994.');

        $this->assertTrue($result->passed);
    }

    public function testFailsWhenAddingExternalInformation(): void
    {
        $context = 'Neuron AI is a PHP framework for building AI agents.';
        $agent = $this->createFakeAgentWithScore(0.4, 'Adds information about competitors not in context.');
        $assertion = new FaithfulnessJudge($agent, $context, 0.7);

        $result = $assertion->evaluate('Neuron AI is a PHP framework. It is better than LangChain.');

        $this->assertFalse($result->passed);
    }

    public function testIncludesContextInPrompt(): void
    {
        $fakeProvider = FakeAIProvider::make();
        for ($i = 0; $i < 3; $i++) {
            $fakeProvider->addResponses(new AssistantMessage(json_encode([
                'score' => 0.8,
                'reasoning' => 'Faithful',
            ], JSON_THROW_ON_ERROR)));
        }

        $agent = Agent::make()->setAiProvider($fakeProvider);
        $context = 'The product costs $99.';
        $assertion = new FaithfulnessJudge($agent, $context, 0.7);

        $assertion->evaluate('The price is $99.');

        $fakeProvider->assertSent(function ($record) use ($context): bool {
            $content = $record->messages[0]->getContent();
            return str_contains($content, 'Context:') &&
                   str_contains($content, $context);
        });
    }

    public function testUsesFaithfulnessCriteria(): void
    {
        $fakeProvider = FakeAIProvider::make();
        for ($i = 0; $i < 3; $i++) {
            $fakeProvider->addResponses(new AssistantMessage(json_encode([
                'score' => 0.75,
                'reasoning' => 'Test',
            ], JSON_THROW_ON_ERROR)));
        }

        $agent = Agent::make()->setAiProvider($fakeProvider);
        $assertion = new FaithfulnessJudge($agent, 'Some context', 0.7);

        $assertion->evaluate('Output');

        $fakeProvider->assertSent(function ($record): bool {
            $content = $record->messages[0]->getContent();
            return str_contains($content, 'factually grounded') &&
                   str_contains($content, 'hallucinations');
        });
    }

    public function testSupportsExamplesForCalibration(): void
    {
        $context = 'The meeting is at 3pm.';
        $agent = $this->createFakeAgentWithScore(0.9, 'Follows the pattern of faithful responses.');
        $assertion = new FaithfulnessJudge(
            judge: $agent,
            context: $context,
            threshold: 0.7,
            examples: [
                [
                    'input' => 'When is the meeting?',
                    'output' => 'The meeting is scheduled for 3pm.',
                    'score' => 1.0,
                    'reasoning' => 'Only uses information from context',
                ],
            ]
        );

        $result = $assertion->evaluate('At 3pm is when the meeting happens.');

        $this->assertTrue($result->passed);
    }

    public function testDefaultThreshold(): void
    {
        $agent = $this->createFakeAgentWithScore(0.7, 'At default threshold');
        $assertion = new FaithfulnessJudge($agent, 'Context');

        $result = $assertion->evaluate('Output');

        $this->assertTrue($result->passed);
    }

    public function testGetName(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Test');
        $assertion = new FaithfulnessJudge($agent, 'Context');

        $this->assertEquals('FaithfulnessJudge', $assertion->getName());
    }

    public function testFailsWithNonStringInput(): void
    {
        $agent = $this->createFakeAgentWithScore(1.0, 'Should not be called');
        $assertion = new FaithfulnessJudge($agent, 'Context', 0.5);

        $result = $assertion->evaluate(['array']);

        $this->assertFalse($result->passed);
        $this->assertEquals(0.0, $result->score);
        $this->assertStringContainsString('Expected actual value to be a string', $result->message);
    }
}
