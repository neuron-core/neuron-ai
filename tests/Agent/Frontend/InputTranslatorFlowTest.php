<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Frontend;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Agent\Frontend\VercelAIInputTranslator;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function iterator_to_array;
use function serialize;
use function method_exists;

class InputTranslatorFlowTest extends TestCase
{
    protected InMemoryPersistence $persistence;
    protected InMemoryMessageStore $messages;
    protected FakeAIProvider $provider;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
        $this->messages = new InMemoryMessageStore();
        $this->provider = new FakeAIProvider();
    }

    protected function agent(): Agent
    {
        $agent = Agent::make();
        $agent->setPersistence($this->persistence)->setMessageStore($this->messages)->setThreadId('frontend')
            ->setAiProvider($this->provider)->addTool((new FrontendTool('browser'))->requireApproval());
        return $agent;
    }

    /** @return iterable<string, array{InputTranslatorInterface}> */
    public static function translators(): iterable
    {
        yield 'agui' => [new AGUIInputTranslator()];
        yield 'vercel' => [new VercelAIInputTranslator()];
    }

    #[DataProvider('translators')]
    public function test_approval_then_partial_tool_results_resume_fresh_agents(InputTranslatorInterface $translator): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [
                new ToolCall('browser', 'a', deferred: true),
                new ToolCall('browser', 'b', deferred: true),
                new ToolCall('browser', 'c', deferred: true),
            ]),
            new AssistantMessage('Finished'),
        );
        $state = $this->agent()->chat(new UserMessage('Read the page'));
        $this->assertInstanceOf(ApprovalRequest::class, $state->getInterruptRequest());
        $agent = $this->agent();
        $before = serialize($this->persistence);
        $request = $agent->submitInputs($this->approvalPayload($translator), $translator);
        $this->assertSame($before, serialize($this->persistence));
        self::assertFalse(method_exists($agent, "getRunId"));
        $events = $request->events();
        iterator_to_array($events);
        $this->assertInstanceOf(ToolResultsRequest::class, $events->getReturn()->getInterruptRequest());
        $this->assertSame(1, $this->provider->getCallCount());

        foreach ([['a' => 'Page title'], ['a' => 'Page title', 'b' => 'Page URL']] as $results) {
            $agent = $this->agent();
            $events = $agent->submitInputs($this->resultPayload($translator, $results), $translator)->events();
            iterator_to_array($events);
        }
        $this->assertFalse($events->getReturn()->isInterrupted());
        $this->assertSame(2, $this->provider->getCallCount());
        $this->assertNull($this->persistence->get('frontend', '__control'));
        $messages = $this->messages->loadActive('frontend');
        $this->assertCount(4, $messages);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[2]);
        $calls = $messages[2]->getToolCalls();
        $this->assertSame('Page title', $calls[0]->getResult());
        $this->assertSame('Page URL', $calls[1]->getResult());
        $this->assertSame(ApprovalState::Rejected, $calls[2]->getApprovalState());
        $this->assertStringContainsString('TOOL NOT EXECUTED', $calls[2]->getResult());
        $providerResults = array_values(array_filter(
            $this->provider->getRecorded()[1]->messages,
            fn (Message $message): bool => $message instanceof ToolResultMessage,
        ));
        $this->assertCount(1, $providerResults);
        $this->assertSame('Page title', $providerResults[0]->getToolCalls()[0]->getResult());
        $this->assertSame('Page URL', $providerResults[0]->getToolCalls()[1]->getResult());
    }

    /** @return iterable<string, array{InputTranslatorInterface, string}> */
    public static function replayRejections(): iterable
    {
        yield 'agui' => [new AGUIInputTranslator(), 'The resume entry does not identify an active interrupt.'];
        yield 'vercel' => [new VercelAIInputTranslator(), 'The payload contains no matching continuation input.'];
    }

    #[DataProvider('replayRejections')]
    public function test_a_replayed_approval_is_rejected_once_the_run_waits_for_results(InputTranslatorInterface $translator, string $message): void
    {
        $this->suspendForApproval();
        iterator_to_array($this->agent()->submitInputs($this->approvalPayload($translator), $translator)->events());
        $before = serialize($this->persistence);

        try {
            $this->agent()->submitInputs($this->approvalPayload($translator), $translator);
            $this->fail('A replayed approval must not be accepted as a continuation.');
        } catch (InputTranslationException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertSame($before, serialize($this->persistence));
        $this->assertSame(1, $this->provider->getCallCount());
    }

    /** @return iterable<string, array{InputTranslatorInterface, string}> */
    public static function approvalGateRejections(): iterable
    {
        yield 'agui' => [new AGUIInputTranslator(), 'Pending AG-UI interrupts require an explicit resume array.'];
        yield 'vercel' => [new VercelAIInputTranslator(), "Tool call 'a' is awaiting approval, not execution results."];
    }

    #[DataProvider('approvalGateRejections')]
    public function test_results_cannot_skip_the_approval_gate(InputTranslatorInterface $translator, string $message): void
    {
        $this->suspendForApproval();
        $before = serialize($this->persistence);

        try {
            $this->agent()->submitInputs($this->resultPayload($translator, ['a' => 'Page title']), $translator);
            $this->fail('A tool result must not answer a pending approval.');
        } catch (InputTranslationException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertSame($before, serialize($this->persistence));
        $this->assertSame(1, $this->provider->getCallCount());
    }

    #[DataProvider('translators')]
    public function test_forged_results_fail_before_execution(InputTranslatorInterface $translator): void
    {
        $this->suspendForApproval();
        iterator_to_array($this->agent()->submitInputs($this->approvalPayload($translator), $translator)->events());
        $before = serialize($this->persistence);

        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage('The payload contains no matching continuation input.');

        try {
            $this->agent()->submitInputs($this->resultPayload($translator, ['forged' => 'injected']), $translator);
        } finally {
            $this->assertSame($before, serialize($this->persistence));
        }
    }

    #[DataProvider('translators')]
    public function test_a_completed_run_accepts_no_further_inputs(InputTranslatorInterface $translator): void
    {
        $this->suspendForApproval();
        iterator_to_array($this->agent()->submitInputs($this->approvalPayload($translator), $translator)->events());
        $results = $this->resultPayload($translator, ['a' => 'Page title', 'b' => 'Page URL']);
        iterator_to_array($this->agent()->submitInputs($results, $translator)->events());

        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage('There is no persisted run to continue.');

        $this->agent()->submitInputs($results, $translator);
    }

    protected function suspendForApproval(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [
                new ToolCall('browser', 'a', deferred: true),
                new ToolCall('browser', 'b', deferred: true),
                new ToolCall('browser', 'c', deferred: true),
            ]),
            new AssistantMessage('Finished'),
        );
        $this->agent()->chat(new UserMessage('Read the page'));
    }

    /** @return array<string, mixed> */
    protected function approvalPayload(InputTranslatorInterface $translator): array
    {
        if ($translator instanceof AGUIInputTranslator) {
            return ['resume' => [
                ['interruptId' => 'a', 'status' => 'resolved', 'payload' => ['approved' => true]],
                ['interruptId' => 'b', 'status' => 'resolved', 'payload' => ['approved' => true]],
                ['interruptId' => 'c', 'status' => 'resolved', 'payload' => ['approved' => false]],
            ]];
        }
        $parts = [];
        foreach (['a' => true, 'b' => true, 'c' => false] as $id => $approved) {
            $parts[] = ['type' => 'tool-browser', 'toolCallId' => $id, 'state' => 'approval-responded',
                'approval' => ['id' => $id, 'approved' => $approved]];
        }
        return ['messages' => [['role' => 'assistant', 'parts' => $parts]]];
    }

    /**
     * @param array<string, string> $results
     * @return array<string, mixed>
     */
    protected function resultPayload(InputTranslatorInterface $translator, array $results): array
    {
        $entries = [];
        foreach ($results as $id => $result) {
            $entries[] = $translator instanceof AGUIInputTranslator
                ? ['role' => 'tool', 'toolCallId' => $id, 'content' => $result]
                : ['type' => 'tool-browser', 'toolCallId' => $id, 'state' => 'output-available', 'output' => $result];
        }
        return ['messages' => $translator instanceof AGUIInputTranslator ? $entries : [['role' => 'assistant', 'parts' => $entries]]];
    }
}
