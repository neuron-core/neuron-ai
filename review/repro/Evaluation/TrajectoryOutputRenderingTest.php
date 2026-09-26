<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Output;

use DateTimeImmutable;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function ob_get_clean;
use function ob_start;

class TrajectoryOutputRenderingTest extends TestCase
{
    protected function report(): EvaluationReport
    {
        // Conversation::run() output, the documented multi-turn evaluation subject
        $trajectory = Trajectory::fromMessages([
            new UserMessage('Refund order 123'),
            new AssistantMessage('Your refund has been issued.'),
        ]);
        $at = new DateTimeImmutable('2026-09-03T10:00:00+00:00');

        return new EvaluationReport([
            new EvaluatorReport('App\\E', new EvaluationResults([
                new EvaluatorResult('App\\E', 0, false, [], $trajectory, 0.1, 0, 1),
            ]), $at, $at),
        ], $at, $at);
    }

    public function test_json_report_contains_the_conversation(): void
    {
        ob_start();
        (new JsonOutput())->output($this->report());
        $data = json_decode((string) ob_get_clean(), true);

        $this->assertStringContainsString('Your refund has been issued.', (string) $data['results'][0]['output']);
    }

    public function test_verbose_console_failure_shows_the_conversation(): void
    {
        ob_start();
        (new ConsoleOutput(verbose: true))->output($this->report());
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Your refund has been issued.', $output);
    }
}
