<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Runner;

use NeuronAI\Tests\Evaluation\Stubs\StringContainsEvaluator;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use PHPUnit\Framework\TestCase;

class EvaluatorRunnerTest extends TestCase
{
    public function testAssertionStateDoesNotLeakBetweenDatasetItems(): void
    {
        $evaluator = new StringContainsEvaluator();
        $runner = new EvaluatorRunner();

        $summary = $runner->run($evaluator);

        $results = $summary->getResults();
        $this->assertCount(2, $results);

        // First item: failing assertion
        $result0 = $results[0];
        $this->assertFalse($result0->isPassed());
        $this->assertEquals(0, $result0->getAssertionsPassed());
        $this->assertEquals(1, $result0->getAssertionsFailed());
        $this->assertEquals(1, $result0->getTotalAssertions());

        // Second item: passing assertion (should not inherit first item's failures)
        $result1 = $results[1];
        $this->assertTrue($result1->isPassed());
        $this->assertEquals(1, $result1->getAssertionsPassed());
        $this->assertEquals(0, $result1->getAssertionsFailed());
        $this->assertEquals(1, $result1->getTotalAssertions());

        // Summary: exactly 2 assertions total (one per dataset item)
        $this->assertEquals(2, $summary->getTotalAssertions());
        $this->assertEquals(1, $summary->getTotalAssertionsPassed());
        $this->assertEquals(1, $summary->getTotalAssertionsFailed());
    }

    public function testConcurrentRunProducesSameResultsAsSequential(): void
    {
        // Exercises the parallel path where pcntl is available (Linux/macOS),
        // and the sequential fallback elsewhere (e.g. Windows)
        $evaluator = new StringContainsEvaluator();
        $runner = new EvaluatorRunner();

        $summary = $runner->run($evaluator, 4);

        $results = $summary->getResults();
        $this->assertCount(2, $results);

        // Results must come back in dataset order
        $this->assertEquals(0, $results[0]->getIndex());
        $this->assertEquals(1, $results[1]->getIndex());

        $this->assertFalse($results[0]->isPassed());
        $this->assertTrue($results[1]->isPassed());

        $this->assertEquals(2, $summary->getTotalAssertions());
        $this->assertEquals(1, $summary->getTotalAssertionsPassed());
        $this->assertEquals(1, $summary->getTotalAssertionsFailed());
    }
}
