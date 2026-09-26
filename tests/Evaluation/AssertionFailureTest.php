<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation;

use NeuronAI\Evaluation\AssertionFailure;
use PHPUnit\Framework\TestCase;

class AssertionFailureTest extends TestCase
{
    public function test_full_description_names_short_class_line_assertion_and_message(): void
    {
        $failure = new AssertionFailure('App\\Evaluators\\RefundEvaluator', 'StringContains', "Expected 'x' to contain 'y'", 42);

        $this->assertSame('RefundEvaluator', $failure->getShortEvaluatorClass());
        $this->assertSame("RefundEvaluator:42 -> StringContains: Expected 'x' to contain 'y'", $failure->getFullDescription());
    }

    public function test_short_class_of_a_global_class_is_the_class_itself(): void
    {
        $failure = new AssertionFailure('GlobalEvaluator', 'MatchesRegex', 'no match', 7);

        $this->assertSame('GlobalEvaluator', $failure->getShortEvaluatorClass());
        $this->assertSame('GlobalEvaluator:7 -> MatchesRegex: no match', $failure->getFullDescription());
    }

    public function test_exposes_its_constructor_values(): void
    {
        $failure = new AssertionFailure('App\\E', 'AgentJudge', 'below threshold', 3, ['threshold' => 0.7]);

        $this->assertSame('App\\E', $failure->getEvaluatorClass());
        $this->assertSame('AgentJudge', $failure->getAssertionMethod());
        $this->assertSame('below threshold', $failure->getMessage());
        $this->assertSame(3, $failure->getLineNumber());
        $this->assertSame(['threshold' => 0.7], $failure->getContext());
    }

    public function test_a_non_judge_failure_has_no_judge_score(): void
    {
        $failure = new AssertionFailure('App\\E', 'StringContains', 'missing', 3);

        $this->assertFalse($failure->isAIJudgeFailure());
        $this->assertNull($failure->getAIJudgeScore());
    }
}
