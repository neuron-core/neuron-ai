<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Output;

use NeuronAI\Evaluation\AssertionFailure;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Tests\Evaluation\Stub\EvaluationReportFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function ob_get_clean;
use function ob_start;

class ConsoleOutputTest extends TestCase
{
    public function test_summary_is_exact(): void
    {
        $this->assertSame(
            "Neuron AI Evaluation Runner\n\n"
            . "\n\n"
            . "There were 2 failure(s):\n\n"
            . "1) SupportEvaluator #1\n"
            . "   Evaluation failed\n"
            . "   Assertions: 1 passed, 1 failed\n"
            . "   Execution Time: 0.5s\n\n"
            . "2) SupportEvaluator #2\n"
            . "   Error: Provider timeout\n"
            . "   Execution Time: 0.125s\n\n"
            . "Assertion Failure Summary:\n"
            . "--------------------------------------------------\n"
            . "SupportEvaluator:42 - 1 failure in ToolWasCalled\n\n"
            . "Started: 2026-09-03T10:00:00.000000+00:00\n"
            . "Finished: 2026-09-03T10:00:02.500000+00:00\n"
            . "Duration: 2.5 seconds, Average: 0.292 seconds per test\n\n"
            . "FAILURES!\n"
            . "Tests: 3, Passed: 1, Failed: 2, Success Rate: 33.3%\n"
            . "Assertions: 3, Passed: 2, Failed: 1, Success Rate: 66.7%\n"
            . "Cached runs: 1 of 3 (assertions re-evaluated)\n"
            . "Score Stats: Avg: 0.6, Min: 0, Max: 1\n"
            . "  StringContains: Avg: 1, Min: 1, Max: 1 (1 assertions)\n"
            . "  ToolWasCalled: Avg: 0, Min: 0, Max: 0 (1 assertions)\n"
            . "  quality: Avg: 0.8, Min: 0.8, Max: 0.8 (1 assertions)\n",
            $this->render(new ConsoleOutput(), EvaluationReportFixture::mixed())
        );
    }

    public function test_verbose_summary_adds_input_output_and_failure_messages(): void
    {
        $output = $this->render(new ConsoleOutput(verbose: true), EvaluationReportFixture::mixed());

        $this->assertStringStartsWith("Neuron AI Evaluation Runner\n\nThere were 2 failure(s):\n\n", $output);
        $this->assertStringContainsString(
            "1) SupportEvaluator #1\n"
            . "   Evaluation failed\n"
            . "   Input: {\n    \"q\": \"refund\"\n}\n"
            . "   Output: {\n    \"status\": \"denied\"\n}\n"
            . "   Assertions: 1 passed, 1 failed\n",
            $output
        );
        // Errored items show the error, never the (missing) output
        $this->assertStringContainsString("2) SupportEvaluator #2\n   Error: Provider timeout\n   Execution Time: 0.125s\n", $output);
        $this->assertStringContainsString(
            "SupportEvaluator:42 - 1 failure in ToolWasCalled\n"
            . "  - ToolWasCalled: Expected tool 'refund_order' to be called\n",
            $output
        );
    }

    public function test_all_passing_run_prints_ok_without_failure_sections(): void
    {
        $output = $this->render(new ConsoleOutput(), EvaluationReportFixture::singleResult('Hello'));

        $this->assertStringContainsString("\nOK\nTests: 1, Passed: 1, Failed: 0, Success Rate: 100%\n", $output);
        $this->assertStringContainsString("Assertions: 1, Passed: 1, Failed: 0, Success Rate: 100%\n", $output);
        $this->assertStringNotContainsString('failure', $output);
        $this->assertStringNotContainsString('Cached runs', $output);
        // No assertion recorded a score
        $this->assertStringNotContainsString('Score Stats', $output);
    }

    public function test_empty_report_prints_zero_totals(): void
    {
        $output = $this->render(new ConsoleOutput(), EvaluationReportFixture::report());

        $this->assertStringContainsString(
            "Duration: 2.5 seconds, Average: 0 seconds per test\n\nOK\n"
            . "Tests: 0, Passed: 0, Failed: 0, Success Rate: 0%\n"
            . "Assertions: 0, Passed: 0, Failed: 0, Success Rate: 0%\n",
            $output
        );
    }

    public function test_evaluator_breakdown_lists_every_evaluator(): void
    {
        $report = EvaluationReportFixture::report(
            EvaluationReportFixture::evaluatorReport('App\\FirstEvaluator', new EvaluationResults([
                new EvaluatorResult('App\\FirstEvaluator', 0, true, [], 'ok', 0.1, 1, 0),
            ])),
            EvaluationReportFixture::evaluatorReport('App\\SecondEvaluator', new EvaluationResults([]), 'Dataset failed to load'),
        );

        $output = $this->render(new ConsoleOutput(), $report);

        $this->assertStringContainsString("There were 1 evaluator error(s):\n\n1) SecondEvaluator\n   Error: Dataset failed to load\n\n", $output);
        $this->assertStringContainsString(
            "\nBy evaluator:\n"
            . "  FirstEvaluator\n"
            . "    Duration: 2.5 seconds\n"
            . "    Tests: 1, Passed: 1, Failed: 0, Success Rate: 100%\n"
            . "    Assertions: 1, Passed: 1, Failed: 0, Success Rate: 100%\n"
            . "  SecondEvaluator\n"
            . "    Duration: 2.5 seconds\n"
            . "    Tests: 0, Passed: 0, Failed: 0, Success Rate: 0%\n"
            . "    Assertions: 0, Passed: 0, Failed: 0, Success Rate: 0%\n",
            $output
        );
        // An evaluator error fails the run even though no item failed
        $this->assertStringContainsString("\nFAILURES!\n", $output);
    }

    public function test_failure_summary_groups_failures_by_location_and_disambiguates_short_names(): void
    {
        $first = 'First\\DuplicateEvaluator';
        $second = 'Second\\DuplicateEvaluator';
        $report = EvaluationReportFixture::report(
            EvaluationReportFixture::evaluatorReport($first, new EvaluationResults([
                new EvaluatorResult($first, 0, false, [], 'a', 0.1, 0, 2, [
                    new AssertionFailure($first, 'StringContains', 'missing a', 10),
                    new AssertionFailure($first, 'MatchesRegex', 'no match', 10),
                ]),
                new EvaluatorResult($first, 1, false, [], 'b', 0.1, 0, 1, [
                    new AssertionFailure($first, 'StringContains', 'missing b', 10),
                ]),
            ])),
            EvaluationReportFixture::evaluatorReport($second, new EvaluationResults([
                new EvaluatorResult($second, 0, false, [], 'c', 0.1, 0, 1, [
                    new AssertionFailure($second, 'StringContains', 'missing c', 10),
                ]),
                new EvaluatorResult($second, 1, false, [], 'd', 0.1, 0, 2, [
                    new AssertionFailure($second, 'StringContains', 'missing d', 20),
                    new AssertionFailure($second, 'StringContains', 'missing e', 20),
                ]),
            ])),
        );

        $output = $this->render(new ConsoleOutput(), $report);

        $this->assertStringContainsString(
            "First\\DuplicateEvaluator:10 - 3 failures in StringContains, MatchesRegex\n"
            . "Second\\DuplicateEvaluator:10 - 1 failure in StringContains\n"
            . "Second\\DuplicateEvaluator:20 - 2 failures in StringContains\n",
            $output
        );
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function verboseOutputs(): iterable
    {
        yield 'string is quoted' => ['Hello', '"Hello"'];
        yield 'empty string' => ['', '""'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'null' => [null, 'null'];
        yield 'int' => [42, '42'];
        yield 'float' => [0.5, '0.5'];
        yield 'list' => [['a', 'b'], "[\n    \"a\",\n    \"b\"\n]"];
        yield 'unencodable array' => [['bad' => "\xB1"], 'Unable to serialize output'];
    }

    #[DataProvider('verboseOutputs')]
    public function test_verbose_failure_renders_the_output_by_type(mixed $output, string $rendered): void
    {
        $report = EvaluationReportFixture::singleResult($output, passed: false);

        $this->assertStringContainsString(
            "   Output: {$rendered}\n   Assertions: 0 passed, 1 failed\n",
            $this->render(new ConsoleOutput(verbose: true), $report)
        );
    }

    public function test_non_verbose_failure_does_not_print_input_or_output(): void
    {
        $report = EvaluationReportFixture::singleResult('secret output', passed: false);

        $output = $this->render(new ConsoleOutput(), $report);

        $this->assertStringNotContainsString('secret output', $output);
        $this->assertStringNotContainsString('Input:', $output);
    }

    /**
     * @return iterable<string, array{bool, bool, string}>
     */
    public static function progressSymbols(): iterable
    {
        yield 'pass' => [false, true, '.'];
        yield 'fail' => [false, false, 'F'];
        yield 'verbose pass' => [true, true, ''];
        yield 'verbose fail' => [true, false, ''];
    }

    #[DataProvider('progressSymbols')]
    public function test_progress_symbols_only_in_non_verbose_mode(bool $verbose, bool $passed, string $symbol): void
    {
        ob_start();
        (new ConsoleOutput($verbose))->printProgressSymbol($passed);

        $this->assertSame($symbol, ob_get_clean());
    }

    protected function render(ConsoleOutput $console, EvaluationReport $report): string
    {
        ob_start();
        $console->output($report);

        return (string) ob_get_clean();
    }
}
