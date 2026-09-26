<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Judges;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\Assertions\Judges\CorrectnessJudge;
use NeuronAI\Evaluation\Assertions\Judges\FaithfulnessJudge;
use NeuronAI\Evaluation\Assertions\Judges\HelpfulnessJudge;
use NeuronAI\Evaluation\Assertions\Judges\RelevanceJudge;
use NeuronAI\Evaluation\Assertions\Judges\TaskCompletionJudge;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JudgeThresholdTest extends TestCase
{
    /**
     * Each factory builds the judge with the given threshold, or with its default when null.
     *
     * @return iterable<string, array{Closure(AgentInterface, ?float): AgentJudge}>
     */
    public static function judges(): iterable
    {
        yield 'correctness' => [static fn (AgentInterface $agent, ?float $threshold): AgentJudge => $threshold === null
            ? new CorrectnessJudge($agent, 'Paris')
            : new CorrectnessJudge($agent, 'Paris', $threshold)];
        yield 'faithfulness' => [static fn (AgentInterface $agent, ?float $threshold): AgentJudge => $threshold === null
            ? new FaithfulnessJudge($agent, 'Paris is the capital of France.')
            : new FaithfulnessJudge($agent, 'Paris is the capital of France.', $threshold)];
        yield 'helpfulness' => [static fn (AgentInterface $agent, ?float $threshold): AgentJudge => $threshold === null
            ? new HelpfulnessJudge($agent)
            : new HelpfulnessJudge($agent, $threshold)];
        yield 'relevance' => [static fn (AgentInterface $agent, ?float $threshold): AgentJudge => $threshold === null
            ? new RelevanceJudge($agent, 'What is the capital of France?')
            : new RelevanceJudge($agent, 'What is the capital of France?', $threshold)];
        yield 'task completion' => [static fn (AgentInterface $agent, ?float $threshold): AgentJudge => $threshold === null
            ? new TaskCompletionJudge($agent, 'Learn the capital of France')
            : new TaskCompletionJudge($agent, 'Learn the capital of France', $threshold)];
    }

    /**
     * @param Closure(AgentInterface, ?float): AgentJudge $make
     */
    #[DataProvider('judges')]
    public function test_custom_threshold_decides_the_verdict(Closure $make): void
    {
        // 0.8 would pass the default 0.7 threshold
        $result = $make($this->judgeScoring(0.8), 0.9)->evaluate('Paris.');

        $this->assertFalse($result->passed);
        $this->assertSame(0.9, $result->context['threshold']);
    }

    /**
     * @param Closure(AgentInterface, ?float): AgentJudge $make
     */
    #[DataProvider('judges')]
    public function test_default_threshold_is_inclusive_seven_tenths(Closure $make): void
    {
        $result = $make($this->judgeScoring(0.7), null)->evaluate('Paris.');

        $this->assertTrue($result->passed);
        $this->assertSame(0.7, $result->context['threshold']);
    }

    protected function judgeScoring(float $score): AgentInterface
    {
        return Agent::make()->setAiProvider(new FakeAIProvider(
            new AssistantMessage('{"score":' . $score . ',"reasoning":"Graded."}'),
        ));
    }
}
