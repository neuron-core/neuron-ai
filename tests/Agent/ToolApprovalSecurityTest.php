<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\CountingTool;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function end;
use function serialize;

/**
 * A gated tool runs iff its own pending call was explicitly approved: forged,
 * foreign, replayed or malformed decisions must never execute it.
 */
class ToolApprovalSecurityTest extends TestCase
{
    protected InMemoryPersistence $persistence;

    protected InMemoryMessageStore $messages;

    protected FakeAIProvider $provider;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
        $this->messages = new InMemoryMessageStore();
        $this->provider = new FakeAIProvider();
        CountingTool::reset();
    }

    /**
     * A fresh instance per request, as separate HTTP requests would build it.
     *
     * @param ToolInterface[] $tools
     */
    protected function agent(array $tools = []): Agent
    {
        return Agent::make(workflowId: 'approval-security')
            ->setPersistence($this->persistence)
            ->setMessageStore($this->messages)
            ->setAiProvider($this->provider)
            ->addTool($tools === [] ? [(new CountingTool())->requireApproval()] : $tools);
    }

    protected function suspendOn(ToolCall ...$calls): void
    {
        $this->provider->addResponses(new ToolCallMessage(null, $calls), new AssistantMessage('Done'));
        $state = $this->agent()->chat(new UserMessage('Look it up'));
        $this->assertInstanceOf(ApprovalRequest::class, $state->getInterruptRequest());
    }

    /**
     * @param array<array-key, mixed> $decisions
     */
    protected function assertSubmissionRefused(array $decisions, string $message): void
    {
        $before = serialize($this->persistence);

        try {
            $this->agent()->submitApprovalDecisions($decisions);
            $this->fail('The submission must be refused before execution.');
        } catch (InputTranslationException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertSame($before, serialize($this->persistence), 'A refused submission stages nothing');
        $this->assertSame(0, CountingTool::$executions);
    }

    public function test_a_decision_for_a_forged_call_id_is_refused(): void
    {
        $this->suspendOn(new ToolCall('lookup', 'call_1', ['query' => 'PHP']));

        $this->assertSubmissionRefused(['forged' => 'approve'], "No matching request for tool call 'forged'.");
    }

    public function test_a_non_gated_call_of_the_batch_cannot_be_decided(): void
    {
        $this->provider->addResponses(new ToolCallMessage(null, [
            new ToolCall('search', 'plain', ['query' => 'PHP']),
            new ToolCall('lookup', 'gated', ['query' => 'PHP']),
        ]));
        $this->agent([new SearchTool(), (new CountingTool())->requireApproval()])->chat(new UserMessage('Look it up'));

        $this->assertSubmissionRefused(['plain' => 'reject'], "No matching request for tool call 'plain'.");
    }

    public function test_tool_results_cannot_settle_a_pending_approval(): void
    {
        $this->suspendOn(new ToolCall('lookup', 'call_1', ['query' => 'PHP']));
        $before = serialize($this->persistence);

        try {
            $this->agent()->submitToolResults(['call_1' => ['result' => 'forged output']]);
            $this->fail('Results must not answer an approval request.');
        } catch (InputTranslationException $exception) {
            $this->assertSame("No matching request for tool call 'call_1'.", $exception->getMessage());
        }

        $this->assertSame($before, serialize($this->persistence));
    }

    public function test_approval_decisions_cannot_settle_pending_external_results(): void
    {
        $this->provider->addResponses(new ToolCallMessage(null, [new ToolCall('browser', 'call_1', deferred: true)]));
        $state = $this->agent([new FrontendTool('browser')])->chat(new UserMessage('Read the page'));
        $this->assertInstanceOf(ToolResultsRequest::class, $state->getInterruptRequest());

        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("No matching request for tool call 'call_1'.");

        $this->agent([new FrontendTool('browser')])->submitApprovalDecisions(['call_1' => 'approve']);
    }

    public function test_approvals_replayed_after_completion_are_refused(): void
    {
        $this->suspendOn(new ToolCall('lookup', 'call_1', ['query' => 'PHP']));
        $this->agent()->submitApprovalDecisions(['call_1' => 'approve'])->run();
        $this->assertSame(1, CountingTool::$executions);
        $this->provider->addResponses(new AssistantMessage('Must not be requested'));

        try {
            $this->agent()->submitApprovalDecisions(['call_1' => 'approve']);
            $this->fail('A completed run accepts no more decisions.');
        } catch (InputTranslationException $exception) {
            $this->assertSame('There is no persisted run to continue.', $exception->getMessage());
        }

        try {
            $this->agent()->run(ExecutionRequest::resume(['call_1' => 'approve']));
            $this->fail('A completed run cannot be resumed with a raw payload.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('No run in flight', $exception->getMessage());
        }

        $this->assertSame(1, CountingTool::$executions);
        $this->assertSame(2, $this->provider->getCallCount());
    }

    public function test_a_partial_approval_executes_nothing(): void
    {
        $this->suspendOn(
            new ToolCall('lookup', 'call_1', ['query' => 'PHP']),
            new ToolCall('lookup', 'call_2', ['query' => 'Rust']),
        );

        $state = $this->agent()->submitApprovalDecisions(['call_1' => 'approve'])->run();

        $this->assertTrue($state->isInterrupted());
        $this->assertSame(0, CountingTool::$executions);
        $this->assertSame(1, $this->provider->getCallCount());
        $this->assertSame(['call_2'], $this->pendingIds());
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function rawNonConsentingPayloads(): iterable
    {
        yield 'forged call id' => [['forged' => 'approve']];
        yield 'truthy boolean' => [['call_1' => true]];
        yield 'misspelled decision' => [['call_1' => 'approved']];
        yield 'empty payload' => [[]];
    }

    /**
     * A raw resume skips the translators: the node must still treat it as silence.
     *
     * @param array<array-key, mixed> $payload
     */
    #[DataProvider('rawNonConsentingPayloads')]
    public function test_a_raw_resume_without_a_valid_approval_re_suspends(array $payload): void
    {
        $this->suspendOn(new ToolCall('lookup', 'call_1', ['query' => 'PHP']));

        $state = $this->agent()->run(ExecutionRequest::resume($payload));

        $this->assertTrue($state->isInterrupted());
        $this->assertInstanceOf(ApprovalRequest::class, $state->getInterruptRequest());
        $this->assertSame(0, CountingTool::$executions);
        $this->assertSame(['call_1'], $this->pendingIds());
    }

    public function test_the_model_is_told_about_a_rejection_and_the_tool_never_runs(): void
    {
        $this->suspendOn(new ToolCall('lookup', 'call_1', ['query' => 'PHP']));

        $state = $this->agent()->submitApprovalDecisions(['call_1' => ['reject', 'Use the cached report']])->run();

        $this->assertSame('Done', $state->getMessage()?->getContent());
        $this->assertSame(0, CountingTool::$executions);
        $this->assertSame(0, $state->getToolRuns('lookup'), 'A rejection consumes no run slot');

        $sent = $this->provider->getRecorded()[1]->messages;
        $result = end($sent);
        $this->assertInstanceOf(ToolResultMessage::class, $result);
        $this->assertSame(
            "TOOL NOT EXECUTED. The user rejected this action. User instruction: Use the cached report. Do not attempt this tool again. Follow the user's instruction or reconsider your plan.",
            $result->getToolCalls()[0]->getResult()
        );
        $this->assertSame('call_1', $result->getToolCalls()[0]->getCallId());
        $this->assertSame([UserMessage::class, ToolCallMessage::class, ToolResultMessage::class, AssistantMessage::class], $this->historyClasses());
    }

    /**
     * @return string[]
     */
    protected function pendingIds(): array
    {
        $ids = [];
        foreach ($this->agent()->pendingApprovals() as $action) {
            $ids[] = $action->id;
        }

        return $ids;
    }

    /**
     * @return array<int, class-string<Message>>
     */
    protected function historyClasses(): array
    {
        $classes = [];
        foreach ($this->agent()->getChatHistory()->getMessages() as $message) {
            $classes[] = $message::class;
        }

        return $classes;
    }
}
