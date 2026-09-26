<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Output;

use DateTimeImmutable;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Score;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function ob_get_clean;
use function ob_start;

use const NAN;

class JsonOutputRobustnessTest extends TestCase
{
    /**
     * @return iterable<string, array{EvaluatorResult}>
     */
    public static function oneBadItem(): iterable
    {
        // e.g. an agent answer truncated with substr() in the middle of a multibyte character
        yield 'invalid UTF-8 output' => [new EvaluatorResult('App\\E', 0, true, [], "caf\xC3", 0.1, 1, 0)];
        yield 'invalid UTF-8 error message' => [new EvaluatorResult('App\\E', 0, false, [], null, 0.1, 0, 0, [], [], "Provider said \xC3")];
        yield 'NaN score' => [new EvaluatorResult('App\\E', 0, true, [], 'ok', 0.1, 1, 0, [], [new Score('similarity', NAN, true)])];
    }

    #[DataProvider('oneBadItem')]
    public function test_one_unencodable_item_does_not_lose_the_whole_report(EvaluatorResult $bad): void
    {
        $good = new EvaluatorResult('App\\E', 1, true, [], 'fine', 0.1, 1, 0);
        $at = new DateTimeImmutable('2026-09-03T10:00:00+00:00');
        $report = new EvaluationReport([new EvaluatorReport('App\\E', new EvaluationResults([$bad, $good]), $at, $at)], $at, $at);

        ob_start();
        try {
            (new JsonOutput())->output($report);
        } finally {
            $json = (string) ob_get_clean();
        }

        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertSame(2, $data['total']);
        $this->assertSame('fine', $data['results'][1]['output']);
    }
}
