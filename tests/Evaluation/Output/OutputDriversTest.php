<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Output;

use DateTimeImmutable;
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Evaluation\Output\OutputPipeline;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Score;
use NeuronAI\Tests\Evaluation\Stub\ScoreBasedEvaluator;
use NeuronAI\Tests\Evaluation\Stub\StringContainsEvaluator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function file_exists;
use function file_get_contents;
use function json_decode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function ob_get_clean;
use function ob_start;
use function ini_set;

class OutputDriversTest extends TestCase
{
    public function test_json_output_driver_outputs_to_stdout(): void
    {
        $driver = new JsonOutput();
        $summary = $this->createSummary();

        ob_start();
        $driver->output($summary);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertEquals(2, $data['total']);
        $this->assertEquals(1, $data['passed']);
        $this->assertEquals(1, $data['failed']);
    }

    public function test_json_output_driver_writes_to_file(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'neuron_test_');

        try {
            $driver = new JsonOutput($tempFile);
            $summary = $this->createSummary();

            $driver->output($summary);

            $this->assertFileExists($tempFile);
            $content = file_get_contents($tempFile);
            $data = json_decode($content, true);

            $this->assertIsArray($data);
            $this->assertEquals(2, $data['total']);
            $this->assertEquals(1, $data['passed']);
            $this->assertEquals(1, $data['failed']);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function test_json_output_driver_throws_exception_on_file_write_failure(): void
    {
        // Use an invalid path
        $driver = new JsonOutput('/nonexistent/directory/file.json');
        $summary = $this->createSummary();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to write to file');

        $driver->output($summary);
    }

    public function test_json_output_driver_includes_all_summary_fields(): void
    {
        $driver = new JsonOutput();
        $summary = $this->createSummary();

        ob_start();
        $driver->output($summary);
        $output = ob_get_clean();

        $data = json_decode($output, true);

        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('passed', $data);
        $this->assertArrayHasKey('failed', $data);
        $this->assertArrayHasKey('success_rate', $data);
        $this->assertArrayHasKey('started_at', $data);
        $this->assertArrayHasKey('finished_at', $data);
        $this->assertArrayHasKey('duration', $data);
        $this->assertArrayHasKey('average_execution_time', $data);
        $this->assertArrayHasKey('total_assertions', $data);
        $this->assertArrayHasKey('assertions_passed', $data);
        $this->assertArrayHasKey('evaluators', $data);
        $this->assertCount(1, $data['evaluators']);
        $this->assertArrayHasKey('assertions_failed', $data);
        $this->assertArrayHasKey('assertion_success_rate', $data);
        $this->assertArrayHasKey('has_failures', $data);
        $this->assertArrayHasKey('results', $data);
    }

    public function test_json_output_driver_includes_result_details(): void
    {
        $driver = new JsonOutput();
        $summary = $this->createSummary();

        ob_start();
        $driver->output($summary);
        $output = ob_get_clean();

        $data = json_decode($output, true);

        $this->assertIsArray($data['results']);
        $this->assertCount(2, $data['results']);

        $result0 = $data['results'][0];
        $this->assertArrayHasKey('evaluator_class', $result0);
        $this->assertArrayHasKey('index', $result0);
        $this->assertArrayHasKey('passed', $result0);
        $this->assertArrayHasKey('input', $result0);
        $this->assertArrayHasKey('output', $result0);
        $this->assertArrayHasKey('execution_time', $result0);
        $this->assertArrayHasKey('error', $result0);
        $this->assertArrayHasKey('assertions_passed', $result0);
        $this->assertArrayHasKey('assertions_failed', $result0);
    }

    public function test_output_pipeline_executes_all_drivers(): void
    {
        $calls = [];

        $driver1 = $this->createMockDriver(function (EvaluationReport $summary) use (&$calls): void {
            $calls[] = 'driver1';
        });

        $driver2 = $this->createMockDriver(function (EvaluationReport $summary) use (&$calls): void {
            $calls[] = 'driver2';
        });

        $pipeline = new OutputPipeline([$driver1, $driver2]);
        $summary = $this->createSummary();

        $pipeline->output($summary);

        $this->assertEquals(['driver1', 'driver2'], $calls);
    }

    public function test_output_pipeline_continues_on_driver_failure(): void
    {
        $calls = [];

        $driver1 = $this->createMockDriver(function (EvaluationReport $summary) use (&$calls): void {
            $calls[] = 'driver1';
            throw new RuntimeException('Driver 1 failed');
        });

        $driver2 = $this->createMockDriver(function (EvaluationReport $summary) use (&$calls): void {
            $calls[] = 'driver2';
        });

        $pipeline = new OutputPipeline([$driver1, $driver2]);
        $summary = $this->createSummary();

        // Redirect error_log to a temp file to suppress noisy output
        $tempLog = tempnam(sys_get_temp_dir(), 'error_log_');
        $originalLog = ini_set('error_log', $tempLog);

        try {
            // Should not throw, continues despite driver1 failing
            $pipeline->output($summary);
        } finally {
            ini_set('error_log', $originalLog);
            unlink($tempLog);
        }

        $this->assertEquals(['driver1', 'driver2'], $calls);
    }

    public function test_output_pipeline_get_drivers(): void
    {
        $driver1 = $this->createMockDriver();
        $driver2 = $this->createMockDriver();

        $pipeline = new OutputPipeline([$driver1, $driver2]);

        $drivers = $pipeline->getDrivers();

        $this->assertCount(2, $drivers);
        $this->assertSame($driver1, $drivers[0]);
        $this->assertSame($driver2, $drivers[1]);
    }

    public function test_json_output_driver_handles_complex_output_types(): void
    {
        $result1 = new EvaluatorResult(
            StringContainsEvaluator::class,
            0,
            true,
            ['input' => 'data'],
            ['output' => ['nested' => 'array']],
            0.1,
            1,
            0,
            []
        );

        $result2 = new EvaluatorResult(
            StringContainsEvaluator::class,
            1,
            true,
            ['input' => 'data2'],
            (object) ['output' => 'object'],
            0.2,
            1,
            0,
            []
        );

        $result3 = new EvaluatorResult(
            StringContainsEvaluator::class,
            2,
            true,
            ['input' => 'data3'],
            true,
            0.3,
            1,
            0,
            []
        );

        $summary = new EvaluationResults([$result1, $result2, $result3]);

        $driver = new JsonOutput();

        ob_start();
        $driver->output($this->createSuite($summary));
        $output = ob_get_clean();

        $data = json_decode($output, true);

        $this->assertEquals(['output' => ['nested' => 'array']], json_decode((string) $data['results'][0]['output'], true));
        $this->assertEquals(['output' => 'object'], json_decode((string) $data['results'][1]['output'], true));
        $this->assertTrue($data['results'][2]['output']);
    }

    public function test_json_output_driver_includes_labeled_scores_and_metrics(): void
    {
        $result1 = new EvaluatorResult(
            StringContainsEvaluator::class,
            0,
            true,
            ['input' => 'data1'],
            'output1',
            0.1,
            2,
            0,
            [],
            [
                new Score('task_completion', 0.8, true),
                new Score('helpfulness', 0.6, true),
            ]
        );

        $result2 = new EvaluatorResult(
            StringContainsEvaluator::class,
            1,
            true,
            ['input' => 'data2'],
            'output2',
            0.2,
            1,
            0,
            [],
            [
                new Score('task_completion', 0.4, true),
            ]
        );

        $summary = new EvaluationResults([$result1, $result2]);

        $driver = new JsonOutput();

        ob_start();
        $driver->output($this->createSuite($summary));
        $output = ob_get_clean();

        $data = json_decode($output, true);

        // Legacy keys are preserved
        $this->assertEquals([0.8, 0.6], $data['results'][0]['assertion_scores']);
        $this->assertEqualsWithDelta(0.6, $data['score_statistics']['average_score'], 0.001);

        // Per-item labeled scores
        $this->assertEquals(
            [
                ['label' => 'task_completion', 'value' => 0.8, 'passed' => true],
                ['label' => 'helpfulness', 'value' => 0.6, 'passed' => true],
            ],
            $data['results'][0]['scores']
        );

        // Per-metric aggregation
        $this->assertEqualsWithDelta(0.6, $data['metrics']['task_completion']['average'], 0.001);
        $this->assertEquals(0.4, $data['metrics']['task_completion']['min']);
        $this->assertEquals(0.8, $data['metrics']['task_completion']['max']);
        $this->assertEquals(2, $data['metrics']['task_completion']['count']);
        $this->assertEquals(1, $data['metrics']['helpfulness']['count']);
    }

    public function test_json_output_includes_per_evaluator_reports(): void
    {
        $firstResult = new EvaluatorResult(
            StringContainsEvaluator::class,
            0,
            true,
            [],
            'first',
            0.1,
            1,
            0,
        );
        $moreFirstResults = [
            new EvaluatorResult(
                StringContainsEvaluator::class,
                1,
                true,
                [],
                'first',
                0.1,
                1,
                0,
            ),
            new EvaluatorResult(
                StringContainsEvaluator::class,
                2,
                true,
                [],
                'first',
                0.1,
                1,
                0,
            ),
        ];
        $secondResult = new EvaluatorResult(
            ScoreBasedEvaluator::class,
            0,
            false,
            [],
            'second',
            0.2,
            0,
            1,
        );
        $suite = $this->createEvaluationReport([
            $this->createReport(
                StringContainsEvaluator::class,
                new EvaluationResults([$firstResult, ...$moreFirstResults]),
                namespace: 'App\\Agents\\SupportAgent',
            ),
            $this->createReport(
                ScoreBasedEvaluator::class,
                new EvaluationResults([$secondResult]),
            ),
        ]);

        ob_start();
        (new JsonOutput())->output($suite);
        $data = json_decode((string) ob_get_clean(), true);

        $this->assertSame(0.75, $data['success_rate']);
        $this->assertEquals(1.0, $data['duration']);
        $this->assertSame(StringContainsEvaluator::class, $data['evaluators'][0]['evaluator_class']);
        $this->assertSame('App\\Agents\\SupportAgent', $data['evaluators'][0]['namespace']);
        $this->assertSame('2026-09-03T10:00:00.000000+00:00', $data['started_at']);
        $this->assertSame('2026-09-03T10:00:01.000000+00:00', $data['finished_at']);
        $this->assertSame('2026-09-03T10:00:00.000000+00:00', $data['evaluators'][0]['started_at']);
        $this->assertSame('2026-09-03T10:00:01.000000+00:00', $data['evaluators'][0]['finished_at']);
        $this->assertEquals(1.0, $data['evaluators'][0]['duration']);
        $this->assertEquals(1.0, $data['evaluators'][0]['success_rate']);
        $this->assertSame(ScoreBasedEvaluator::class, $data['evaluators'][1]['evaluator_class']);
        $this->assertEquals(0.0, $data['evaluators'][1]['success_rate']);
        $this->assertSame(StringContainsEvaluator::class, $data['results'][0]['evaluator_class']);
        $this->assertSame(ScoreBasedEvaluator::class, $data['results'][3]['evaluator_class']);
    }

    public function test_json_output_keeps_empty_and_errored_evaluators(): void
    {
        $suite = $this->createEvaluationReport([
            $this->createReport(
                StringContainsEvaluator::class,
                new EvaluationResults([]),
            ),
            $this->createReport(
                ScoreBasedEvaluator::class,
                new EvaluationResults([]),
                'Dataset failed to load',
            ),
        ]);

        ob_start();
        (new JsonOutput())->output($suite);
        $data = json_decode((string) ob_get_clean(), true);

        $this->assertSame(0, $data['total']);
        $this->assertTrue($data['has_failures']);
        $this->assertCount(2, $data['evaluators']);
        $this->assertSame(0, $data['evaluators'][0]['total']);
        $this->assertNull($data['evaluators'][0]['error']);
        $this->assertSame('Dataset failed to load', $data['evaluators'][1]['error']);
        $this->assertTrue($data['evaluators'][1]['has_failures']);
    }

    public function test_console_output_attributes_failures_and_disambiguates_short_names(): void
    {
        $firstClass = 'First\\DuplicateEvaluator';
        $secondClass = 'Second\\DuplicateEvaluator';
        $suite = $this->createEvaluationReport([
            $this->createReport(
                $firstClass,
                new EvaluationResults([
                    new EvaluatorResult($firstClass, 0, false, [], 'first', 0.1, 0, 1),
                ]),
            ),
            $this->createReport(
                $secondClass,
                new EvaluationResults([
                    new EvaluatorResult($secondClass, 0, false, [], 'second', 0.2, 0, 1),
                ]),
            ),
        ]);

        ob_start();
        (new ConsoleOutput())->output($suite);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('First\\DuplicateEvaluator #0', $output);
        $this->assertStringContainsString('Second\\DuplicateEvaluator #0', $output);
        $this->assertStringContainsString('By evaluator:', $output);
    }

    public function test_console_output_reports_evaluator_errors_as_failures(): void
    {
        $suite = $this->createEvaluationReport([
            $this->createReport(
                StringContainsEvaluator::class,
                new EvaluationResults([]),
                'Setup failed',
            ),
        ]);

        ob_start();
        (new ConsoleOutput())->output($suite);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('StringContainsEvaluator', $output);
        $this->assertStringContainsString('Error: Setup failed', $output);
        $this->assertStringContainsString('FAILURES!', $output);
        $this->assertStringContainsString('Started: 2026-09-03T10:00:00.000000+00:00', $output);
        $this->assertStringContainsString('Finished: 2026-09-03T10:00:01.000000+00:00', $output);
        $this->assertStringContainsString('Duration: 1 seconds', $output);
        $this->assertStringNotContainsString('By evaluator:', $output);
    }

    private function createSummary(): EvaluationReport
    {
        $result1 = new EvaluatorResult(
            StringContainsEvaluator::class,
            0,
            false,
            ['input' => 'data1'],
            'output1',
            0.1,
            0,
            1,
            []
        );

        $result2 = new EvaluatorResult(
            StringContainsEvaluator::class,
            1,
            true,
            ['input' => 'data2'],
            'output2',
            0.2,
            1,
            0,
            []
        );

        return $this->createSuite(new EvaluationResults([$result1, $result2]));
    }

    private function createSuite(EvaluationResults $summary): EvaluationReport
    {
        return $this->createEvaluationReport([
            $this->createReport(StringContainsEvaluator::class, $summary),
        ]);
    }

    /**
     * @param array<EvaluatorReport> $evaluatorReports
     */
    protected function createEvaluationReport(array $evaluatorReports): EvaluationReport
    {
        return new EvaluationReport(
            $evaluatorReports,
            new DateTimeImmutable('2026-09-03T10:00:00+00:00'),
            new DateTimeImmutable('2026-09-03T10:00:01+00:00'),
        );
    }

    private function createReport(
        string $evaluatorClass,
        EvaluationResults $summary,
        ?string $error = null,
        ?string $namespace = null,
    ): EvaluatorReport {
        return new EvaluatorReport(
            $evaluatorClass,
            $summary,
            new DateTimeImmutable('2026-09-03T10:00:00+00:00'),
            new DateTimeImmutable('2026-09-03T10:00:01+00:00'),
            $error,
            $namespace,
        );
    }

    /**
     * @param callable(EvaluationReport): void $outputCallback
     */
    private function createMockDriver(?callable $outputCallback = null): EvaluationOutputInterface
    {
        $mock = $this->createMock(EvaluationOutputInterface::class);
        if ($outputCallback !== null) {
            $mock->method('output')->willReturnCallback($outputCallback);
        }
        return $mock;
    }
}
