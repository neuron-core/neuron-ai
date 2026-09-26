<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Output;

use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Tests\Evaluation\Stub\EvaluationReportFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function fclose;
use function file_get_contents;
use function fopen;
use function is_file;
use function json_decode;
use function ob_get_clean;
use function ob_start;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const INF;
use const JSON_THROW_ON_ERROR;

class JsonOutputTest extends TestCase
{
    protected const SUMMARY_KEYS = [
        'total',
        'passed',
        'failed',
        'success_rate',
        'average_execution_time',
        'total_assertions',
        'assertions_passed',
        'assertions_failed',
        'assertion_success_rate',
        'cached_runs',
        'score_statistics',
        'metrics',
    ];

    public function test_document_shape_is_stable(): void
    {
        $data = $this->decode(EvaluationReportFixture::mixed());

        $this->assertSame(
            [...self::SUMMARY_KEYS, 'results', 'started_at', 'finished_at', 'duration', 'has_failures', 'evaluators'],
            array_keys($data)
        );
        $this->assertSame(
            ['evaluator_class', 'namespace', 'started_at', 'finished_at', 'duration', ...self::SUMMARY_KEYS, 'error', 'has_failures'],
            array_keys($data['evaluators'][0])
        );
        $this->assertSame(
            [
                'evaluator_class', 'index', 'passed', 'input', 'output', 'execution_time', 'cached_run', 'error',
                'assertions_passed', 'assertions_failed', 'assertion_scores', 'scores',
            ],
            array_keys($data['results'][0])
        );
    }

    public function test_summary_values(): void
    {
        $data = $this->decode(EvaluationReportFixture::mixed());

        $this->assertSame(3, $data['total']);
        $this->assertSame(1, $data['passed']);
        $this->assertSame(2, $data['failed']);
        $this->assertEqualsWithDelta(1 / 3, $data['success_rate'], 1e-12);
        $this->assertEqualsWithDelta(0.875 / 3, $data['average_execution_time'], 1e-12);
        $this->assertSame(3, $data['total_assertions']);
        $this->assertSame(2, $data['assertions_passed']);
        $this->assertSame(1, $data['assertions_failed']);
        $this->assertSame(1, $data['cached_runs']);
        $this->assertEqualsWithDelta(0.6, $data['score_statistics']['average_score'], 1e-12);
        $this->assertEquals(0, $data['score_statistics']['min_score']);
        $this->assertEquals(1, $data['score_statistics']['max_score']);
        $this->assertSame(['StringContains', 'ToolWasCalled', 'quality'], array_keys($data['metrics']));
        $this->assertEquals(['average' => 0.8, 'min' => 0.8, 'max' => 0.8, 'count' => 1], $data['metrics']['quality']);
        $this->assertSame('2026-09-03T10:00:00.000000+00:00', $data['started_at']);
        $this->assertSame('2026-09-03T10:00:02.500000+00:00', $data['finished_at']);
        $this->assertEquals(2.5, $data['duration']);
        $this->assertTrue($data['has_failures']);
    }

    public function test_result_entries(): void
    {
        $results = $this->decode(EvaluationReportFixture::mixed())['results'];

        $this->assertTrue($results[0]['cached_run']);
        $this->assertSame('Hello', $results[0]['output']);
        $this->assertEquals(
            [
                'evaluator_class' => EvaluationReportFixture::EVALUATOR,
                'index' => 1,
                'passed' => false,
                'input' => ['q' => 'refund'],
                'output' => '{"status":"denied"}',
                'execution_time' => 0.5,
                'cached_run' => false,
                'error' => null,
                'assertions_passed' => 1,
                'assertions_failed' => 1,
                'assertion_scores' => [0, 0.8],
                'scores' => [
                    ['label' => 'ToolWasCalled', 'value' => 0, 'passed' => false],
                    ['label' => 'quality', 'value' => 0.8, 'passed' => true],
                ],
            ],
            $results[1]
        );
        $this->assertSame('Provider timeout', $results[2]['error']);
        $this->assertNull($results[2]['output']);
        $this->assertSame([], $results[2]['scores']);
    }

    public function test_statistics_are_null_without_scores(): void
    {
        $data = $this->decode(EvaluationReportFixture::singleResult('Hello'));

        $this->assertNull($data['score_statistics']);
        $this->assertNull($data['metrics']);
        $this->assertFalse($data['has_failures']);
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function outputs(): iterable
    {
        yield 'string' => ['Paris', 'Paris'];
        yield 'int' => [42, 42];
        yield 'float' => [0.5, 0.5];
        yield 'bool' => [false, false];
        yield 'null' => [null, null];
        yield 'unicode string' => ['Caffè ☕ 日本', 'Caffè ☕ 日本'];
        yield 'array is embedded as a JSON string' => [['a' => [1, 2]], '{"a":[1,2]}'];
        yield 'unencodable array' => [['score' => INF], 'Unable to serialize output'];
    }

    #[DataProvider('outputs')]
    public function test_output_values_by_type(mixed $output, mixed $expected): void
    {
        $this->assertSame($expected, $this->decode(EvaluationReportFixture::singleResult($output))['results'][0]['output']);
    }

    public function test_resource_output_is_described_as_a_string(): void
    {
        $resource = fopen('php://memory', 'r');

        try {
            $output = $this->decode(EvaluationReportFixture::singleResult($resource))['results'][0]['output'];
        } finally {
            fclose($resource);
        }

        $this->assertStringStartsWith('Resource id #', $output);
    }

    public function test_special_characters_survive_the_round_trip(): void
    {
        $output = "Line 1\nLine 2\t\"quoted\" </script> \\ \u{0000} \u{001B}[31m";

        $this->assertSame($output, $this->decode(EvaluationReportFixture::singleResult($output))['results'][0]['output']);
    }

    public function test_file_output_is_identical_to_stdout_output(): void
    {
        $path = sys_get_temp_dir() . '/neuron-json-output-' . uniqid() . '.json';
        $report = EvaluationReportFixture::mixed();

        try {
            (new JsonOutput($path))->output($report);
            $written = file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        ob_start();
        (new JsonOutput())->output($report);

        $this->assertSame(ob_get_clean(), $written);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(EvaluationReport $report): array
    {
        ob_start();
        (new JsonOutput())->output($report);

        return json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    }
}
