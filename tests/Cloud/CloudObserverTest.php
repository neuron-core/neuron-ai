<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Cloud;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Cloud\CloudClient;
use NeuronAI\Cloud\CloudObserver;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

class CloudObserverTest extends TestCase
{
    public function testImplementsObserverInterface(): void
    {
        $observer = new CloudObserver(new RecordingCloudClient());
        $this->assertInstanceOf(\NeuronAI\Observability\ObserverInterface::class, $observer);
    }

    public function testStaticFactoryWithKey(): void
    {
        $observer = CloudObserver::makeWithKey('test_key');
        $this->assertInstanceOf(CloudObserver::class, $observer);
    }

    public function testStaticFactoryThrowsWithoutKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Neuron Cloud API key is required');
        CloudObserver::makeWithKey();
    }

    public function testIgnoresUnknownEvents(): void
    {
        $client = new RecordingCloudClient();
        $observer = new CloudObserver($client);

        // Should not throw or record anything
        $observer->onEvent('unknown-event', new stdClass());

        $this->assertEmpty($client->calls);
    }

    public function testFullWorkflowTrace(): void
    {
        $client = new RecordingCloudClient();
        $observer = new CloudObserver($client);

        // Simulate workflow-start
        $workflowSource = new stdClass();
        $observer->onEvent('workflow-start', $workflowSource, new WorkflowStart([]));

        // Simulate node-start
        $state = new WorkflowState();
        $observer->onEvent('workflow-node-start', $workflowSource, new WorkflowNodeStart('ChatNode', $state));

        // Simulate inference-start
        $userMessage = Message::make(MessageRole::USER, 'What is the weather?');
        $observer->onEvent('inference-start', $workflowSource, new InferenceStart($userMessage));

        // Simulate inference-stop
        $assistantMessage = new AssistantMessage('The weather is sunny.');
        $assistantMessage->setUsage(new Usage(24, 12));
        $observer->onEvent('inference-stop', $workflowSource, new InferenceStop($userMessage, $assistantMessage));

        // Simulate node-end
        $observer->onEvent('workflow-node-end', $workflowSource, new WorkflowNodeEnd('ChatNode', $state));

        // Simulate workflow-end
        $observer->onEvent('workflow-end', $workflowSource, new WorkflowEnd($state));

        // Assert one trace was sent
        $this->assertCount(1, $client->calls);
        $this->assertEquals('sendTrace', $client->calls[0]['method']);

        $payload = $client->calls[0]['payload'];
        $this->assertArrayHasKey('trace_id', $payload);
        $this->assertArrayHasKey('workflow', $payload);
        $this->assertArrayHasKey('spans', $payload);
        $this->assertStringStartsWith('trace_', $payload['trace_id']);
        $this->assertEquals(stdClass::class, $payload['workflow']);

        // Root span + inference span + node span = 3
        $this->assertCount(3, $payload['spans']);

        // Root span
        $rootSpan = $payload['spans'][0];
        $this->assertNull($rootSpan['parent_span_id']);
        $this->assertEquals('ok', $rootSpan['status']);
        $this->assertEquals('INTERNAL', $rootSpan['kind']);
        $this->assertArrayHasKey('start_time_unix_nano', $rootSpan);
        $this->assertArrayHasKey('end_time_unix_nano', $rootSpan);

        // Inference span
        $inferenceSpan = $payload['spans'][1];
        $this->assertEquals($rootSpan['span_id'], $inferenceSpan['parent_span_id']);
        $this->assertEquals('CLIENT', $inferenceSpan['kind']);
        $this->assertEquals('user', $inferenceSpan['attributes']['neuron.inference.input_role']);
        $this->assertEquals('assistant', $inferenceSpan['attributes']['neuron.inference.output_role']);
        $this->assertEquals('The weather is sunny.', $inferenceSpan['attributes']['neuron.inference.output_content']);
        $this->assertEquals(24, $inferenceSpan['attributes']['neuron.inference.usage.input_tokens']);
        $this->assertEquals(12, $inferenceSpan['attributes']['neuron.inference.usage.output_tokens']);
    }

    public function testToolCallTrace(): void
    {
        $client = new RecordingCloudClient();
        $observer = new CloudObserver($client);

        // Workflow
        $workflowSource = new stdClass();
        $observer->onEvent('workflow-start', $workflowSource, new WorkflowStart([]));

        // Tool calling
        $tool = Tool::make('get_weather', 'Get weather for a city');
        $tool->setCallId('call_123');
        $tool->setInputs(['city' => 'Rome']);
        $observer->onEvent('tool-calling', $workflowSource, new ToolCalling($tool));

        // Tool called — need a tool with result
        $toolWithResult = clone $tool;
        $toolWithResult->setResult('Sunny, 22C');
        $observer->onEvent('tool-called', $workflowSource, new ToolCalled($toolWithResult));

        // Workflow end
        $observer->onEvent('workflow-end', $workflowSource, new WorkflowEnd(new WorkflowState()));

        $payload = $client->calls[0]['payload'];

        // Root + tool = 2 spans
        $this->assertCount(2, $payload['spans']);

        $toolSpan = $payload['spans'][1];
        $this->assertEquals('tool_call(get_weather)', $toolSpan['name']);
        $this->assertEquals('INTERNAL', $toolSpan['kind']);
        $this->assertEquals('get_weather', $toolSpan['attributes']['neuron.tool.name']);
        $this->assertEquals(['city' => 'Rome'], $toolSpan['attributes']['neuron.tool.inputs']);
        $this->assertEquals('Sunny, 22C', $toolSpan['attributes']['neuron.tool.result']);
    }

    public function testErrorMarksRootSpanAsError(): void
    {
        $client = new RecordingCloudClient();
        $observer = new CloudObserver($client);

        $workflowSource = new stdClass();
        $observer->onEvent('workflow-start', $workflowSource, new WorkflowStart([]));

        $observer->onEvent('error', $workflowSource, new AgentError(new RuntimeException('Something broke')));

        $observer->onEvent('workflow-end', $workflowSource, new WorkflowEnd(new WorkflowState()));

        $payload = $client->calls[0]['payload'];

        // Root span + error span = 2
        $this->assertCount(2, $payload['spans']);

        $rootSpan = $payload['spans'][0];
        $this->assertEquals('error', $rootSpan['status']);

        $errorSpan = $payload['spans'][1];
        $this->assertEquals('error', $errorSpan['name']);
        $this->assertEquals('error', $errorSpan['status']);
        $this->assertEquals('Something broke', $errorSpan['attributes']['neuron.error.message']);
        $this->assertEquals(RuntimeException::class, $errorSpan['attributes']['neuron.error.class']);
    }

    public function testNoFlushWithoutCompletedSpans(): void
    {
        $client = new RecordingCloudClient();
        $observer = new CloudObserver($client);

        // workflow-end without workflow-start → no spans, no HTTP call
        $observer->onEvent('workflow-end', new stdClass(), new WorkflowEnd(new WorkflowState()));

        $this->assertEmpty($client->calls);
    }

    public function testMakeWithKeyFromEnv(): void
    {
        $_ENV['NEURON_CLOUD_API_KEY'] = 'env_test_key';

        try {
            $observer = CloudObserver::makeWithKey();
            $this->assertInstanceOf(CloudObserver::class, $observer);
        } finally {
            unset($_ENV['NEURON_CLOUD_API_KEY']);
        }
    }

    public function testFlushSilentlyIgnoresHttpExceptions(): void
    {
        $this->expectNotToPerformAssertions();

        $client = $this->createMock(CloudClient::class);
        $client->method('sendTrace')->willThrowException(new RuntimeException('Connection refused'));

        $observer = new CloudObserver($client);

        $workflowSource = new stdClass();
        $observer->onEvent('workflow-start', $workflowSource, new WorkflowStart([]));

        $observer->onEvent('workflow-end', $workflowSource, new WorkflowEnd(new WorkflowState()));
    }

    public function testInferenceStopWithoutStartIsIgnored(): void
    {
        $client = new RecordingCloudClient();
        $observer = new CloudObserver($client);

        // inference-stop without a prior inference-start
        $userMessage = Message::make(MessageRole::USER, 'Hello');
        $assistantMessage = new AssistantMessage('Hi');
        $observer->onEvent('inference-stop', new stdClass(), new InferenceStop($userMessage, $assistantMessage));

        // No workflow-end → no flush
        $this->assertEmpty($client->calls);
    }
}
