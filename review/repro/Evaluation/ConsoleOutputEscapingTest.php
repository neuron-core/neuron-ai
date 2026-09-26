<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Output;

use DateTimeImmutable;
use NeuronAI\Evaluation\AssertionFailure;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use PHPUnit\Framework\TestCase;

use function ob_get_clean;
use function ob_start;

class ConsoleOutputEscapingTest extends TestCase
{
    public function test_terminal_control_sequences_from_agent_output_are_not_emitted_raw(): void
    {
        // Model-controlled text: clear screen, move cursor home, fake an "OK" verdict line
        $hostile = "\e[2J\e[H\e[32mOK\e[0m\r";
        $at = new DateTimeImmutable('2026-09-03T10:00:00+00:00');
        $report = new EvaluationReport([
            new EvaluatorReport('App\\E', new EvaluationResults([
                new EvaluatorResult('App\\E', 0, false, ['q' => 'x'], $hostile, 0.1, 0, 1, [
                    new AssertionFailure('App\\E', 'StringContains', "Expected '{$hostile}' to contain 'refund'", 10),
                ]),
                new EvaluatorResult('App\\E', 1, false, ['q' => 'y'], null, 0.1, 0, 0, [], [], "Provider error: {$hostile}"),
            ]), $at, $at),
        ], $at, $at);

        ob_start();
        (new ConsoleOutput(verbose: true))->output($report);
        $output = (string) ob_get_clean();

        $this->assertStringNotContainsString("\e", $output);
        $this->assertStringNotContainsString("\r", $output);
        $this->assertStringContainsString('Output: "\\033[2J\\033[H\\033[32mOK\\033[0m\\r"', $output);
        $this->assertStringContainsString('Error: Provider error: \\033[2J', $output);
    }
}
