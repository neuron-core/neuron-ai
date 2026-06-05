<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Cloud;

use NeuronAI\Cloud\CloudEvaluationOutput;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CloudEvaluationOutputTest extends TestCase
{
    public function testImplementsEvaluationOutputInterface(): void
    {
        $output = new CloudEvaluationOutput(new RecordingCloudClient());
        $this->assertInstanceOf(\NeuronAI\Evaluation\Contracts\EvaluationOutputInterface::class, $output);
    }

    public function testSendsEvaluationPayload(): void
    {
        $client = new RecordingCloudClient();
        $output = new CloudEvaluationOutput($client);

        $summary = $this->createSummary();
        $output->output($summary);

        $this->assertCount(1, $client->calls);
        $this->assertEquals('sendEvaluation', $client->calls[0]['method']);

        $payload = $client->calls[0]['payload'];

        // Top-level fields
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertIsInt($payload['timestamp']);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('results', $payload);
    }

    public function testPayloadSummaryFields(): void
    {
        $client = new RecordingCloudClient();
        $output = new CloudEvaluationOutput($client);

        $summary = $this->createSummary();
        $output->output($summary);

        $summaryData = $client->calls[0]['payload']['summary'];

        $this->assertEquals(2, $summaryData['total']);
        $this->assertEquals(1, $summaryData['passed']);
        $this->assertEquals(1, $summaryData['failed']);
        $this->assertEquals(0.5, $summaryData['success_rate']);
        $this->assertEquals(0.3, $summaryData['total_execution_time']);
        $this->assertEquals(0.15, $summaryData['average_execution_time']);
        $this->assertTrue($summaryData['has_failures']);
    }

    public function testPayloadResultFields(): void
    {
        $client = new RecordingCloudClient();
        $output = new CloudEvaluationOutput($client);

        $summary = $this->createSummary();
        $output->output($summary);

        $results = $client->calls[0]['payload']['results'];

        $this->assertCount(2, $results);

        $result0 = $results[0];
        $this->assertEquals(0, $result0['index']);
        $this->assertFalse($result0['passed']);
        $this->assertEquals(['input' => 'data1'], $result0['input']);
        $this->assertEquals('output1', $result0['output']);
        $this->assertEquals(0.1, $result0['execution_time']);
        $this->assertNull($result0['error']);
        $this->assertEquals(0, $result0['assertions_passed']);
        $this->assertEquals(1, $result0['assertions_failed']);
    }

    public function testSilentlyIgnoresHttpExceptions(): void
    {
        $this->expectNotToPerformAssertions();

        $client = $this->createMock(\NeuronAI\Cloud\CloudClient::class);
        $client->method('sendEvaluation')->willThrowException(new RuntimeException('Connection refused'));

        $output = new CloudEvaluationOutput($client);
        $output->output($this->createSummary());
    }

    public function testFormatOutputHandlesVariousTypes(): void
    {
        $client = new RecordingCloudClient();
        $output = new CloudEvaluationOutput($client);

        $result1 = new EvaluatorResult(0, true, [], ['nested' => 'array'], 0.1, 1, 0, []);
        $result2 = new EvaluatorResult(1, true, [], (object) ['key' => 'val'], 0.1, 1, 0, []);
        $result3 = new EvaluatorResult(2, true, [], true, 0.1, 1, 0, []);

        $output->output(new EvaluatorSummary([$result1, $result2, $result3], 0.3));

        $results = $client->calls[0]['payload']['results'];

        // Array output is JSON-encoded
        $this->assertIsString($results[0]['output']);
        $this->assertEquals('{"nested":"array"}', $results[0]['output']);

        // Object output is JSON-encoded
        $this->assertIsString($results[1]['output']);

        // Scalar output passes through
        $this->assertTrue($results[2]['output']);
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
}
