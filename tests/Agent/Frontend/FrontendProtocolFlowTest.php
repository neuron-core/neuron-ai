<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Frontend;

use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Agent\Frontend\VercelAIInputTranslator;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FrontendProtocolFlowTest extends TestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function protocols(): iterable
    {
        yield 'AG-UI approval' => [true, true];
        yield 'AG-UI rejection' => [true, false];
        yield 'Vercel approval' => [false, true];
        yield 'Vercel rejection' => [false, false];
    }

    /** @param iterable<string> $frames
     * @return list<array<string, mixed>>
     */
    protected function decode(iterable $frames): array
    {
        $events = [];
        foreach ($frames as $frame) {
            if ($frame !== "data: [DONE]\n\n") {
                $events[] = json_decode(substr($frame, 6), true, flags: JSON_THROW_ON_ERROR);
            }
        }
        return $events;
    }

    #[DataProvider('protocols')]
    public function test_approval_dispatch_and_result_round_trip(bool $agui, bool $approved): void
    {
        $history = new InMemoryChatHistory('frontend-flow');
        $persistence = new InMemoryPersistence();
        $provider = new FakeAIProvider();
        $provider->addResponses(
            new ToolCallMessage(null, [new ToolCall('browser', 'browser-call', deferred: true)]),
            new AssistantMessage('Finished'),
        );
        $factory = static function () use ($history, $persistence, $provider): Agent {
            $agent = Agent::make();
            $agent->setChatHistory($history)->setPersistence($persistence)->setAiProvider($provider);
            $agent->addTool((new FrontendTool('browser'))->requireApproval());
            return $agent;
        };
        $messages = [['id' => 'user', 'role' => 'user', 'content' => 'Read the page']];
        $agent = $factory();
        $agent->setStreamAdapter($agui ? new AGUIAdapter('frontend-flow', 'wire-1', $messages) : new VercelAIAdapter());
        $first = $this->decode($agent->stream(new UserMessage('Read the page')));
        $this->assertNotContains('TOOL_CALL_START', array_column($first, 'type'));
        $this->assertNotContains('tool-input-available', array_column($first, 'type'));
        $translator = $agui ? new AGUIInputTranslator() : new VercelAIInputTranslator();
        if ($agui) {
            $interrupt = $first[array_key_last($first)]['outcome']['interrupts'][0];
            $payload = ['resume' => [['interruptId' => $interrupt['id'], 'status' => 'resolved', 'payload' => ['approved' => $approved]]]];
            $messageId = null;
            $parts = [];
        } else {
            $approval = array_values(array_filter($first, fn (array $event): bool => $event['type'] === 'tool-approval-request'))[0];
            $messageId = $first[0]['messageId'];
            $parts = [['type' => 'tool-browser', 'toolCallId' => $approval['toolCallId'], 'input' => [],
                'state' => 'approval-responded', 'approval' => ['id' => $approval['approvalId'], 'approved' => $approved]]];
            $payload = ['messages' => [['id' => $messageId, 'role' => 'assistant', 'parts' => $parts]]];
        }
        $agent = $factory();
        $agent->setStreamAdapter($agui ? new AGUIAdapter('frontend-flow', 'wire-2', $messages) : new VercelAIAdapter($messageId, $parts));
        $stream = $agent->submitInputs($payload, $translator)->events();
        $second = [];
        foreach ($stream as $frame) {
            foreach ($this->decode([$frame]) as $event) {
                if (in_array($event['type'], ['TOOL_CALL_START', 'tool-input-available'], true) && $approved) {
                    $this->assertSame(WorkflowStatus::Suspended, (new WorkflowExecutor())->inspect($agent)->status);
                }
                $second[] = $event;
            }
        }
        if (!$approved) {
            $this->assertFalse($stream->getReturn()->isInterrupted());
            $this->assertNotContains('tool-input-available', array_column($second, 'type'));
            $this->assertSame(2, $provider->getCallCount());
            return;
        }
        $this->assertInstanceOf(ToolResultsRequest::class, $stream->getReturn()->getInterruptRequest());
        $this->assertSame(1, $provider->getCallCount());
        if ($agui) {
            $this->assertArrayNotHasKey('outcome', $second[array_key_last($second)]);
            $call = array_values(array_filter($second, fn (array $event): bool => $event['type'] === 'TOOL_CALL_START'))[0];
            $messages[] = ['id' => $call['parentMessageId'], 'role' => 'assistant', 'toolCalls' => [[
                'id' => $call['toolCallId'], 'type' => 'function', 'function' => ['name' => 'browser', 'arguments' => '{}'],
            ]]];
            $messages[] = ['id' => 'frontend-result', 'role' => 'tool', 'toolCallId' => $call['toolCallId'], 'content' => 'Page title'];
            $payload = ['messages' => $messages];
        } else {
            $dispatch = array_values(array_filter($second, fn (array $event): bool => $event['type'] === 'tool-input-available'))[0];
            $parts = [['type' => 'tool-browser', 'toolCallId' => $dispatch['toolCallId'], 'input' => [], 'state' => 'output-available', 'output' => 'Page title']];
            $payload = ['messages' => [['id' => $messageId, 'role' => 'assistant', 'parts' => $parts]]];
        }
        $agent = $factory();
        $agent->setStreamAdapter($agui ? new AGUIAdapter('frontend-flow', 'wire-3', $messages) : new VercelAIAdapter($messageId, $parts));
        $stream = $agent->submitInputs($payload, $translator)->events();
        $last = $this->decode($stream);
        $this->assertFalse($stream->getReturn()->isInterrupted());
        $this->assertNotContains('TOOL_CALL_RESULT', array_column($last, 'type'));
        $this->assertNotContains('tool-output-available', array_column($last, 'type'));
        $resultMessages = array_values(array_filter($provider->getRecorded()[1]->messages, fn (Message $message): bool => $message instanceof ToolResultMessage));
        $this->assertSame('Page title', $resultMessages[0]->getToolCalls()[0]->getResult());
    }
}
