<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Output;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Score;
use NeuronAI\Evaluation\Usage\ModelUsage;
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
        'average_run_time',
        'average_evaluation_time',
        'run_usage',
        'judge_usage',
        'reruns',
        'warnings',
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
                'evaluator_class', 'index', 'passed', 'input', 'output', 'execution_time', 'run_time', 'evaluation_time',
                'run_usage', 'judge_usage', 'reruns', 'cached_run', 'error',
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

    public function test_a_measured_item_reports_its_times_its_usage_and_its_reruns(): void
    {
        $usage = new ModelUsage('OpenAI', 'gpt', 1, 100, 20, 40, 30, 5);
        $judge = new ModelUsage('Anthropic', 'claude', 1, 10, 5, 4, 3, 2);
        $rerun = new EvaluatorResult('App\\Eval', 0, true, [], 'ok', 0.5, 1, 0, runTime: 0.25, evaluationTime: 0.125, runUsage: [$usage], judgeUsage: [$judge]);
        $results = new EvaluationResults([
            new EvaluatorResult('App\\Eval', 0, false, [], 'no', 1.0, 0, 1, runTime: 0.75, evaluationTime: 0.25, runUsage: [$usage], judgeUsage: [$judge], reruns: [$rerun]),
        ], ['Mind the cache']);

        $data = $this->decode(EvaluationReportFixture::report(EvaluationReportFixture::evaluatorReport('App\\Eval', $results)));

        $record = ['provider' => 'OpenAI', 'model' => 'gpt', 'calls' => 1, 'input_tokens' => 100, 'output_tokens' => 20, 'cached_input_tokens' => 40, 'cache_write_tokens' => 30, 'reasoning_tokens' => 5];
        $judgeRecord = ['provider' => 'Anthropic', 'model' => 'claude', 'calls' => 1, 'input_tokens' => 10, 'output_tokens' => 5, 'cached_input_tokens' => 4, 'cache_write_tokens' => 3, 'reasoning_tokens' => 2];
        $this->assertEqualsWithDelta(0.75, $data['average_run_time'], 1e-12);
        $this->assertEqualsWithDelta(0.25, $data['average_evaluation_time'], 1e-12);
        $this->assertSame(['Mind the cache'], $data['warnings']);
        $this->assertSame(1, $data['reruns']);
        $this->assertEquals([['calls' => 2, 'input_tokens' => 200, 'output_tokens' => 40, 'cached_input_tokens' => 80, 'cache_write_tokens' => 60, 'reasoning_tokens' => 10] + $record], $data['run_usage']);
        $this->assertEquals([['calls' => 2, 'input_tokens' => 20, 'output_tokens' => 10, 'cached_input_tokens' => 8, 'cache_write_tokens' => 6, 'reasoning_tokens' => 4] + $judgeRecord], $data['judge_usage']);
        $this->assertEquals([$record], $data['results'][0]['run_usage']);
        $this->assertEquals([$judgeRecord], $data['results'][0]['judge_usage']);
        $this->assertEqualsWithDelta(0.75, $data['results'][0]['run_time'], 1e-12);
        $this->assertEqualsWithDelta(0.25, $data['results'][0]['evaluation_time'], 1e-12);
        $this->assertEquals(
            [['passed' => true, 'error' => null, 'run_time' => 0.25, 'evaluation_time' => 0.125, 'run_usage' => [$record], 'judge_usage' => [$judgeRecord]]],
            $data['results'][0]['reruns'],
        );
    }

    public function test_a_score_carries_its_assertion_its_message_and_its_line(): void
    {
        $results = new EvaluationResults([
            new EvaluatorResult('App\\Eval', 0, true, [], 'yes', 1.0, 1, 0, [], [new Score('tone', 0.9, true, 'AgentJudge', 'Reads well.', 31)]),
        ]);

        $data = $this->decode(EvaluationReportFixture::report(EvaluationReportFixture::evaluatorReport('App\\Eval', $results)));

        $this->assertEquals(
            [['label' => 'tone', 'value' => 0.9, 'passed' => true, 'assertion' => 'AgentJudge', 'message' => 'Reads well.', 'line' => 31]],
            $data['results'][0]['scores'],
        );
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
                'run_time' => null,
                'evaluation_time' => null,
                'run_usage' => [],
                'judge_usage' => [],
                'reruns' => [],
                'cached_run' => false,
                'error' => null,
                'assertions_passed' => 1,
                'assertions_failed' => 1,
                'assertion_scores' => [0, 0.8],
                'scores' => [
                    ['label' => 'ToolWasCalled', 'value' => 0, 'passed' => false, 'assertion' => null, 'message' => '', 'line' => null],
                    ['label' => 'quality', 'value' => 0.8, 'passed' => true, 'assertion' => null, 'message' => '', 'line' => null],
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
        yield 'invalid UTF-8 string is substituted' => ["caf\xE9", "caf\u{FFFD}"];
        yield 'invalid UTF-8 inside an array is substituted' => [['name' => "caf\xE9"], '{"name":"caf\\ufffd"}'];
        yield 'trajectory is its transcript' => [
            Trajectory::fromMessages([new UserMessage('Refund order 123'), new AssistantMessage('Done.')]),
            "User: Refund order 123\nAssistant: Done.",
        ];
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

    public function test_invalid_utf8_in_one_item_does_not_suppress_the_report(): void
    {
        $report = EvaluationReportFixture::report(EvaluationReportFixture::evaluatorReport(
            EvaluationReportFixture::EVALUATOR,
            new EvaluationResults([
                new EvaluatorResult(EvaluationReportFixture::EVALUATOR, 0, false, ['q' => "\xC3\x28"], null, 0.5, 0, 0, [], [], "Truncated \xE2\x82"),
                new EvaluatorResult(EvaluationReportFixture::EVALUATOR, 1, true, ['q' => 'fine'], 'ok', 0.5, 1, 0, [], []),
            ])
        ));

        $results = $this->decode($report)['results'];

        $this->assertCount(2, $results);
        $this->assertSame("\u{FFFD}(", $results[0]['input']['q']);
        $this->assertSame("Truncated \u{FFFD}", $results[0]['error']);
        $this->assertSame('ok', $results[1]['output']);
    }
}
