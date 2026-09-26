<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const INF;
use const NAN;

class AgentJudgeThresholdTest extends TestCase
{
    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidThresholds(): iterable
    {
        yield 'above one' => [1.5];
        yield 'negative' => [-0.1];
        yield 'NaN' => [NAN];
        yield 'infinite' => [INF];
    }

    #[DataProvider('invalidThresholds')]
    public function test_invalid_threshold_is_rejected_like_the_classifier_judge_does(float $threshold): void
    {
        $agent = Agent::make()->setAiProvider(new FakeAIProvider());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Threshold must be finite and between zero and one.');

        new AgentJudge($agent, 'Check quality', $threshold);
    }

    public function test_boundary_thresholds_are_accepted(): void
    {
        $agent = Agent::make()->setAiProvider(new FakeAIProvider());

        $this->assertInstanceOf(AgentJudge::class, new AgentJudge($agent, 'Check quality', 0.0));
        $this->assertInstanceOf(AgentJudge::class, new AgentJudge($agent, 'Check quality', 1.0));
    }
}
