<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Repro;

use NeuronAI\Evaluation\AssertionFailure;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Tests\Evaluation\Stub\EvaluationReportFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function ob_get_clean;
use function ob_start;

class ConsoleOutputEscapeReproTest extends TestCase
{
    protected const HOSTILE = "\e[1A\e[2K\e[1A\e[2KOK\n\e]0;evaluation passed\x07";

    /** @return array<string, array{EvaluationReport}> */
    public static function hostileReports(): array
    {
        return [
            'string output' => [EvaluationReportFixture::singleResult(self::HOSTILE, passed: false)],
            'item error' => [EvaluationReportFixture::singleResult(null, passed: false, error: self::HOSTILE)],
            'assertion failure message' => [EvaluationReportFixture::singleResult('x', passed: false, failures: [
                new AssertionFailure(EvaluationReportFixture::EVALUATOR, 'StringContains', "Expected '" . self::HOSTILE . "' to contain 'refund'", 10),
            ])],
        ];
    }

    #[DataProvider('hostileReports')]
    public function test_model_controlled_text_cannot_send_terminal_control_sequences(EvaluationReport $report): void
    {
        ob_start();
        (new ConsoleOutput(verbose: true))->output($report);
        $printed = (string) ob_get_clean();

        $this->assertStringNotContainsString("\e", $printed);
        $this->assertStringNotContainsString("\x07", $printed);
        $this->assertStringContainsString('FAILURES!', $printed);
    }
}
