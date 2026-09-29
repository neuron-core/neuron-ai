<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation;

use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\RuleExecutor;
use NeuronAI\Evaluation\Score;
use NeuronAI\Tests\Evaluation\Stub\AssertingEvaluator;
use NeuronAI\Tests\Evaluation\Stub\FixedResultAssertion;
use NeuronAI\Tests\Support\LocatesSourceLines;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RuleExecutorTest extends TestCase
{
    use LocatesSourceLines;

    public function test_execute_returns_the_verdict_and_counts_it(): void
    {
        $executor = new RuleExecutor(AssertingEvaluator::class);

        $this->assertTrue($executor->execute(new FixedResultAssertion(AssertionResult::pass(1.0)), 'actual', 10));
        $this->assertFalse($executor->execute(new FixedResultAssertion(AssertionResult::fail(0.0, 'nope')), 'actual', 10));
        $this->assertTrue($executor->execute(new FixedResultAssertion(AssertionResult::pass(0.5)), 'actual', 10));

        $outcomes = $executor->snapshot();
        $this->assertSame(2, $outcomes->passedCount);
        $this->assertSame(1, $outcomes->failedCount);
        $this->assertFalse($outcomes->isPassed());
    }

    public function test_every_assertion_records_a_score_even_when_it_fails(): void
    {
        $executor = new RuleExecutor(AssertingEvaluator::class);

        $executor->execute(new FixedResultAssertion(AssertionResult::pass(0.9)), 'actual', 10);
        $executor->execute(new FixedResultAssertion(AssertionResult::fail(0.2, 'too low')), 'actual', 10, 'quality');

        $this->assertEquals(
            [
                new Score('FixedResultAssertion', 0.9, true),
                new Score('quality', 0.2, false),
            ],
            $executor->snapshot()->scores
        );
    }

    public function test_failure_keeps_rule_name_message_and_context(): void
    {
        $executor = new RuleExecutor(AssertingEvaluator::class);

        $executor->execute(
            new FixedResultAssertion(AssertionResult::fail(0.1, 'Score 0.1 below threshold', ['threshold' => 0.7])),
            'actual',
            10,
            'custom_label'
        );

        $failures = $executor->snapshot()->failures;
        $this->assertCount(1, $failures);
        // The label renames the metric, not the failing rule
        $this->assertSame('FixedResultAssertion', $failures[0]->getAssertionMethod());
        $this->assertSame('Score 0.1 below threshold', $failures[0]->getMessage());
        $this->assertSame(['threshold' => 0.7], $failures[0]->getContext());
    }

    public function test_failure_without_message_gets_a_default_one(): void
    {
        $executor = new RuleExecutor(AssertingEvaluator::class);

        $executor->execute(new FixedResultAssertion(AssertionResult::fail(0.0, '')), 'actual', 10);

        $this->assertSame('Evaluation rule failed', $executor->snapshot()->failures[0]->getMessage());
    }

    public function test_passing_assertions_record_no_failure(): void
    {
        $executor = new RuleExecutor(AssertingEvaluator::class);

        $executor->execute(new FixedResultAssertion(AssertionResult::pass(1.0)), 'actual', 10);

        $this->assertSame([], $executor->snapshot()->failures);
        $this->assertTrue($executor->snapshot()->isPassed());
    }

    public function test_snapshot_is_not_affected_by_later_executions(): void
    {
        $executor = new RuleExecutor(AssertingEvaluator::class);
        $executor->execute(new FixedResultAssertion(AssertionResult::pass(1.0)), 'actual', 10);

        $before = $executor->snapshot();
        $executor->execute(new FixedResultAssertion(AssertionResult::fail(0.0, 'later')), 'actual', 10);

        $this->assertSame(1, $before->passedCount);
        $this->assertSame(0, $before->failedCount);
        $this->assertSame([], $before->failures);
        $this->assertCount(1, $before->scores);
    }

    public function test_reset_clears_counts_failures_and_scores(): void
    {
        $executor = new RuleExecutor(AssertingEvaluator::class);
        $executor->execute(new FixedResultAssertion(AssertionResult::pass(1.0)), 'actual', 10);
        $executor->execute(new FixedResultAssertion(AssertionResult::fail(0.0, 'failed')), 'actual', 10);

        $executor->reset();

        $outcomes = $executor->snapshot();
        $this->assertSame(0, $outcomes->passedCount);
        $this->assertSame(0, $outcomes->failedCount);
        $this->assertSame([], $outcomes->failures);
        $this->assertSame([], $outcomes->scores);
        $this->assertTrue($outcomes->isPassed());
    }

    public function test_no_assertions_counts_as_passed(): void
    {
        $this->assertTrue((new RuleExecutor(AssertingEvaluator::class))->snapshot()->isPassed());
    }

    public function test_failures_are_attributed_to_the_concrete_evaluator(): void
    {
        $outcomes = (new AssertingEvaluator())->performEvaluation([
            [new FixedResultAssertion(AssertionResult::fail(0.0, 'failed')), 'actual'],
        ], []);

        $this->assertSame(AssertingEvaluator::class, $outcomes->failures[0]->getEvaluatorClass());
    }

    public function test_perform_evaluation_starts_every_item_from_a_clean_state(): void
    {
        $evaluator = new AssertingEvaluator();

        $first = $evaluator->performEvaluation([
            [new FixedResultAssertion(AssertionResult::fail(0.0, 'first item failure')), 'actual', 'first'],
        ], []);
        $second = $evaluator->performEvaluation([
            [new FixedResultAssertion(AssertionResult::pass(1.0)), 'actual', 'second'],
        ], []);

        $this->assertSame(1, $first->failedCount);
        $this->assertSame(0, $second->failedCount);
        $this->assertSame(1, $second->passedCount);
        $this->assertSame([], $second->failures);
        $this->assertEquals([new Score('second', 1.0, true)], $second->scores);
    }

    public function test_a_failure_records_the_evaluator_and_the_line_it_is_given(): void
    {
        $executor = new RuleExecutor('App\\Evaluators\\RefundEvaluator');

        $executor->execute(new FixedResultAssertion(AssertionResult::fail(0.0, 'failed')), 'actual', 42);

        $failure = $executor->snapshot()->failures[0];
        $this->assertSame('App\\Evaluators\\RefundEvaluator', $failure->getEvaluatorClass());
        $this->assertSame(42, $failure->getLineNumber());
    }

    public function test_a_failure_points_at_the_assert_call_in_the_evaluator(): void
    {
        $outcomes = (new AssertingEvaluator())->performEvaluation([
            [new FixedResultAssertion(AssertionResult::fail(0.0, 'failed')), 'actual'],
        ], []);

        $stub = (string) (new ReflectionClass(AssertingEvaluator::class))->getFileName();
        $this->assertSame($this->lineContaining($stub, '$this->assert('), $outcomes->failures[0]->getLineNumber());
    }

    public function test_a_failure_names_the_evaluator_that_ran_not_the_one_declaring_evaluate(): void
    {
        $evaluator = new class () extends AssertingEvaluator {};

        $outcomes = $evaluator->performEvaluation([
            [new FixedResultAssertion(AssertionResult::fail(0.0, 'failed')), 'actual'],
        ], []);

        $this->assertSame($evaluator::class, $outcomes->failures[0]->getEvaluatorClass());
    }
}
