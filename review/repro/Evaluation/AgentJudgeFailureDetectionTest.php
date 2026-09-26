<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\JudgeScoreOutput;
use NeuronAI\Evaluation\RuleExecutor;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\TestCase;

class AgentJudgeFailureDetectionTest extends TestCase
{
    public function test_a_failed_agent_judge_is_reported_as_an_ai_judge_failure_with_its_score(): void
    {
        $judge = Agent::make()->setAiProvider(new FakeAIProvider(
            new AssistantMessage('{"score":0.2,"reasoning":"Off topic."}'),
        ));
        $executor = new RuleExecutor();

        $executor->execute(new AgentJudge($judge, 'Stay on topic'), 'The weather is nice.');

        $failure = $executor->snapshot()->failures[0];
        $this->assertTrue($failure->isAIJudgeFailure());
        $this->assertInstanceOf(JudgeScoreOutput::class, $failure->getAIJudgeScore());
        $this->assertSame(0.2, $failure->getAIJudgeScore()->score);
    }
}
