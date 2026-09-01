<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Output;

use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Evaluation\Output\OutputPipeline;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;
use NeuronAI\Evaluation\Score;
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
        $this->assertArrayHasKey('total_execution_time', $data);
        $this->assertArrayHasKey('average_execution_time', $data);
        $this->assertArrayHasKey('total_assertions', $data);
        $this->assertArrayHasKey('assertions_passed', $data);
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

        $driver1 = $this->createMockDriver(function (EvaluatorSummary $summary) use (&$calls): void {
            $calls[] = 'driver1';
        });

        $driver2 = $this->createMockDriver(function (EvaluatorSummary $summary) use (&$calls): void {
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

        $driver1 = $this->createMockDriver(function (EvaluatorSummary $summary) use (&$calls): void {
            $calls[] = 'driver1';
            throw new RuntimeException('Driver 1 failed');
        });

        $driver2 = $this->createMockDriver(function (EvaluatorSummary $summary) use (&$calls): void {
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
            2,
            true,
            ['input' => 'data3'],
            true,
            0.3,
            1,
            0,
            []
        );

        $summary = new EvaluatorSummary([$result1, $result2, $result3], 0.6);

        $driver = new JsonOutput();

        ob_start();
        $driver->output($summary);
        $output = ob_get_clean();

        $data = json_decode($output, true);

        $this->assertEquals(['output' => ['nested' => 'array']], json_decode((string) $data['results'][0]['output'], true));
        $this->assertEquals(['output' => 'object'], json_decode((string) $data['results'][1]['output'], true));
        $this->assertTrue($data['results'][2]['output']);
    }

    public function test_json_output_driver_includes_labeled_scores_and_metrics(): void
    {
        $result1 = new EvaluatorResult(
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

        $summary = new EvaluatorSummary([$result1, $result2], 0.3);

        $driver = new JsonOutput();

        ob_start();
        $driver->output($summary);
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

    private function createSummary(): EvaluatorSummary
    {
        $result1 = new EvaluatorResult(
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
            1,
            true,
            ['input' => 'data2'],
            'output2',
            0.2,
            1,
            0,
            []
        );

        return new EvaluatorSummary([$result1, $result2], 0.3);
    }

    /**
     * @param callable(\NeuronAI\Evaluation\Runner\EvaluatorSummary): void $outputCallback
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
