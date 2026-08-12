<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Executor\StepMemoizer;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function iterator_to_array;

/**
 * The tool approval flow owned by ToolNode: the node gates by asking
 * the LIVE registry tool, writes the annotated ToolCallMessage once
 * (memoized), and settles the cumulative decision set through interrupt().
 */
class ToolApprovalFlowTest extends TestCase
{
    /**
     * A registry tool that self-declares it requires approval via approvalPolicy().
     */
    private function gatedTool(string $name): Tool
    {
        $tool = new class () extends Tool {
            protected function approvalPolicy(array $inputs): bool
            {
                return true;
            }

            public function __invoke(mixed ...$arguments): string
            {
                return 'executed';
            }
        };

        $tool->setName($name)->setDescription("Gated {$name}");

        return $tool;
    }

    /**
     * A plain executable registry tool that does NOT require approval (default policy).
     */
    private function plainTool(string $name): Tool
    {
        $tool = new class () extends Tool {
            public function __invoke(mixed ...$arguments): string
            {
                return 'executed';
            }
        };

        $tool->setName($name)->setDescription("Plain {$name}");

        return $tool;
    }

    /**
     * @var ToolInterface[] The registry the next created event will offer —
     *      resolution reads the inference event's tool list.
     */
    private array $registry = [];

    /**
     * @param ToolInterface[] $registry
     */
    private function node(array $registry, ?InMemoryChatHistory $history = null): ToolNode
    {
        $this->registry = $registry;

        return new ToolNode($history ?? new InMemoryChatHistory());
    }

    /**
     * @param ToolCall[] $calls
     */
    private function createToolCallEvent(array $calls): ToolCallEvent
    {
        return new ToolCallEvent(
            new ToolCallMessage(null, $calls),
            new AIInferenceEvent('test instructions', $this->registry)
        );
    }

    private function stepStore(): InMemoryPersistence
    {
        return new InMemoryPersistence();
    }

    /**
     * Run the node once. Returns the resulting AIInferenceEvent, or the caught
     * WorkflowInterrupt when the approval gate suspended.
     */
    private function runNode(
        ToolNode $node,
        ToolCallEvent $event,
        AgentState $state,
        ?array $payload = null,
        ?StepMemoizer $memoizer = null,
    ): AIInferenceEvent|WorkflowInterrupt {
        $node->setWorkflowContext(new NodeContext($state, $event, $payload, false, $memoizer));

        $generator = $node($event, $state);
        $this->assertInstanceOf(Generator::class, $generator);

        try {
            iterator_to_array($generator, false);
        } catch (WorkflowInterrupt $interrupt) {
            return $interrupt;
        }

        return $generator->getReturn();
    }

    private function assertSuspends(
        ToolNode $node,
        ToolCallEvent $event,
        AgentState $state,
        ?array $payload = null,
        ?StepMemoizer $memoizer = null,
        string $message = '',
    ): ApprovalRequest {
        $result = $this->runNode($node, $event, $state, $payload, $memoizer);
        $this->assertInstanceOf(WorkflowInterrupt::class, $result, $message ?: 'Expected the node to suspend');

        $request = $result->getRequest();
        $this->assertInstanceOf(ApprovalRequest::class, $request);

        return $request;
    }

    private function assertExecutes(
        ToolNode $node,
        ToolCallEvent $event,
        AgentState $state,
        ?array $payload = null,
        ?StepMemoizer $memoizer = null,
        string $message = '',
    ): AIInferenceEvent {
        $result = $this->runNode($node, $event, $state, $payload, $memoizer);
        $this->assertInstanceOf(AIInferenceEvent::class, $result, $message ?: 'Expected the node to complete');

        return $result;
    }

    /**
     * @return array<string, Action>
     */
    private function actionsById(ApprovalRequest $request): array
    {
        $byId = [];
        foreach ($request->getActions() as $action) {
            $byId[$action->id] = $action;
        }

        return $byId;
    }

    private function lastToolCall(ToolNode $node): ToolCallMessage
    {
        $last = $node->getChatHistory()->getLastMessage();
        $this->assertInstanceOf(ToolCallMessage::class, $last);

        return $last;
    }

    public function test_tool_self_declaration_suspends(): void
    {
        $node = $this->node([$this->gatedTool('delete_file')]);
        $state = new AgentState();

        $call = ToolCall::make('delete_file', 'call_a', ['path' => '/tmp/x']);

        $request = $this->assertSuspends($node, $this->createToolCallEvent([$call]), $state);
        $this->assertEquals(ActionDecision::Pending, $this->actionsById($request)['call_a']->decision);

        // History carries the annotated ToolCallMessage with the call pending.
        $last = $this->lastToolCall($node);
        $this->assertEquals(ApprovalState::Pending, $last->getToolCalls()[0]->getApprovalState());
    }

    public function test_plain_tools_execute_without_suspension(): void
    {
        $node = $this->node([$this->plainTool('read_file')]);
        $state = new AgentState();

        $call = ToolCall::make('read_file', 'call_a');
        $event = $this->createToolCallEvent([$call]);

        $result = $this->assertExecutes($node, $event, $state);

        $this->assertSame('executed', $call->getResult());
        $this->assertNull($call->getApprovalState(), 'A non-gated call must keep null approval state');

        // Deferred pair-commit: with no suspend possible the node writes nothing —
        // the call/result pair travels as the next inference's inbound messages
        // and commits only after that provider call succeeds.
        $this->assertSame([], $node->getChatHistory()->getMessages());
        $this->assertSame(
            [$event->toolCallMessage, $result->getMessages()[1]],
            $result->getMessages()
        );
        $this->assertInstanceOf(ToolResultMessage::class, $result->getMessages()[1]);
    }

    public function test_failed_tool_leaves_no_dangling_tool_call_in_history(): void
    {
        $tool = new class () extends Tool {
            public function __invoke(mixed ...$arguments): string
            {
                throw new RuntimeException('boom');
            }
        };
        $tool->setName('boom')->setDescription('Always throws');

        $node = $this->node([$tool]);
        $state = new AgentState();

        try {
            $this->runNode($node, $this->createToolCallEvent([ToolCall::make('boom', 'call_a')]), $state);
            $this->fail('Expected the tool exception to propagate');
        } catch (RuntimeException) {
        }

        // Deferred pair-commit: the crash happened before any history write, so
        // the thread is not wedged behind a tool call with no result.
        $this->assertSame([], $node->getChatHistory()->getMessages());
    }

    public function test_require_approval_forces_the_gate(): void
    {
        $tool = $this->plainTool('read_file');
        $tool->requireApproval();

        $node = $this->node([$tool]);

        $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('read_file', 'call_a', ['path' => '/tmp/x'])]),
            new AgentState(),
            message: 'requireApproval() must force the gate even though the policy declares false'
        );
    }

    public function test_suppress_approval_waives_a_declaring_tool(): void
    {
        $tool = $this->gatedTool('delete_file');
        $tool->suppressApproval();

        $node = $this->node([$tool]);
        $call = ToolCall::make('delete_file', 'call_a');

        $this->assertExecutes(
            $node,
            $this->createToolCallEvent([$call]),
            new AgentState(),
            message: 'suppressApproval() must waive a tool that declares true'
        );
        $this->assertSame('executed', $call->getResult());
    }

    public function test_with_approval_policy_gates_conditionally_and_surfaces_the_reason(): void
    {
        $policy = fn (ToolInterface $tool): bool|string => ($tool->getInputs()['amount'] ?? 0) > 100
            ? 'Transfers above $100 require a human sign-off'
            : false;

        $tool = $this->plainTool('transfer_money');
        $tool->withApprovalPolicy($policy);

        $expensive = ToolCall::make('transfer_money', 'call_a', ['amount' => 500]);
        $node = $this->node([$tool]);

        $request = $this->assertSuspends($node, $this->createToolCallEvent([$expensive]), new AgentState());
        $this->assertSame('Transfers above $100 require a human sign-off', $this->actionsById($request)['call_a']->reason);
        $this->assertSame('Transfers above $100 require a human sign-off', $expensive->getApprovalReason());

        // Below the threshold the policy returns false — no gate, no reason.
        $cheap = ToolCall::make('transfer_money', 'call_b', ['amount' => 50]);

        $node2 = $this->node([$tool]);
        $this->assertExecutes($node2, $this->createToolCallEvent([$cheap]), new AgentState());
        $this->assertNull($cheap->getApprovalReason());
    }

    public function test_policy_reason_string_gates_and_is_recorded_in_history(): void
    {
        $tool = new class () extends Tool {
            protected function approvalPolicy(array $inputs): string
            {
                return 'Deleting files is irreversible';
            }

            public function __invoke(mixed ...$arguments): string
            {
                return 'executed';
            }
        };
        $tool->setName('delete_file')->setDescription('d');

        $node = $this->node([$tool]);

        $request = $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('delete_file', 'call_a')]),
            new AgentState()
        );
        $this->assertSame('Deleting files is irreversible', $this->actionsById($request)['call_a']->reason);

        // The reason is recorded on the call entry persisted in chat history.
        $recorded = $this->lastToolCall($node)->getToolCalls()[0];
        $this->assertSame('Deleting files is irreversible', $recorded->getApprovalReason());
        $this->assertSame('Deleting files is irreversible', $recorded->jsonSerialize()['approvalReason']);
    }

    public function test_unknown_tool_is_not_gated_and_fails_at_execution(): void
    {
        // A call naming a tool missing from the registry must not suspend the run
        // waiting for an approval nobody can execute — it fails loudly instead
        // (resolve-or-throw replaces the silent dehydrated shell).
        $node = $this->node([]);

        $this->expectExceptionMessageMatches('/not registered/');
        $this->runNode($node, $this->createToolCallEvent([ToolCall::make('ghost', 'call_a')]), new AgentState());
    }

    public function test_cumulative_resume_partial_re_suspends_with_progress(): void
    {
        $store = $this->stepStore();
        $memoizer = new StepMemoizer($store, new PhpSerializer(), 'approval_flow_test', 'ToolNode-1');
        $node = $this->node([$this->gatedTool('a'), $this->gatedTool('b')]);
        $state = new AgentState();

        // First pass — suspend for both gated calls.
        $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('a', 'call_a'), ToolCall::make('b', 'call_b')]),
            $state,
            memoizer: $memoizer
        );

        // Resume with an incomplete set — fresh replayed calls.
        $request = $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('a', 'call_a'), ToolCall::make('b', 'call_b')]),
            $state,
            payload: ['call_a' => 'approve'],
            memoizer: $memoizer,
            message: 'An incomplete set must re-suspend'
        );

        $byId = $this->actionsById($request);
        $this->assertEquals(ActionDecision::Approved, $byId['call_a']->decision, 'call_a decision reflected on the outbound request');
        $this->assertEquals(ActionDecision::Pending, $byId['call_b']->decision);

        // The history keeps the suspend-time pending snapshot: the
        // memoized write ran once, and final outcomes travel on the ToolResultMessage.
        $last = $this->lastToolCall($node);
        foreach ($last->getToolCalls() as $call) {
            $this->assertEquals(ApprovalState::Pending, $call->getApprovalState());
        }
    }

    public function test_partial_decisions_are_not_remembered_across_resumes(): void
    {
        $store = $this->stepStore();
        $memoizer = new StepMemoizer($store, new PhpSerializer(), 'approval_flow_test', 'ToolNode-1');
        $node = $this->node([$this->gatedTool('a'), $this->gatedTool('b')]);
        $state = new AgentState();

        $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('a', 'call_a'), ToolCall::make('b', 'call_b')]),
            $state,
            memoizer: $memoizer
        );

        // Approve a on the first resume.
        $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('a', 'call_a'), ToolCall::make('b', 'call_b')]),
            $state,
            payload: ['call_a' => 'approve'],
            memoizer: $memoizer
        );

        // The second resume restates only b: a's earlier approval is NOT remembered —
        // the payload is cumulative and accumulation lives with the caller.
        $request = $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('a', 'call_a'), ToolCall::make('b', 'call_b')]),
            $state,
            payload: ['call_b' => 'approve'],
            memoizer: $memoizer,
            message: 'A payload omitting a prior decision must re-suspend'
        );

        $byId = $this->actionsById($request);
        $this->assertEquals(ActionDecision::Pending, $byId['call_a']->decision, 'call_a reverted to pending — not restated');
        $this->assertEquals(ActionDecision::Approved, $byId['call_b']->decision);
    }

    public function test_stale_approval_does_not_survive_on_reused_call_instances(): void
    {
        $store = $this->stepStore();
        $memoizer = new StepMemoizer($store, new PhpSerializer(), 'approval_flow_test', 'ToolNode-1');
        $node = $this->node([$this->gatedTool('a'), $this->gatedTool('b')]);
        $state = new AgentState();

        // The SAME call instances are reused across every pass. InMemoryPersistence
        // aliases stored steps by reference, so replay hands back the objects the
        // previous pass already stamped — unlike the durable backends, which
        // round-trip them through a Serializer and yield fresh calls.
        $a = ToolCall::make('a', 'call_a');
        $b = ToolCall::make('b', 'call_b');

        $this->assertSuspends($node, $this->createToolCallEvent([$a, $b]), $state, memoizer: $memoizer);

        // Approve a only — the set is incomplete, so it re-suspends.
        $this->assertSuspends($node, $this->createToolCallEvent([$a, $b]), $state, payload: ['call_a' => 'approve'], memoizer: $memoizer);
        $this->assertEquals(ApprovalState::Approved, $a->getApprovalState(), 'call_a approved on this pass');

        // Restate only b. a's earlier approval must NOT survive on the reused instance.
        $request = $this->assertSuspends(
            $node,
            $this->createToolCallEvent([$a, $b]),
            $state,
            payload: ['call_b' => 'approve'],
            memoizer: $memoizer,
            message: 'An omitted decision must not survive on a reused call instance'
        );

        $byId = $this->actionsById($request);
        $this->assertEquals(ActionDecision::Pending, $byId['call_a']->decision, 'call_a reverted to pending — not restated');
        $this->assertEquals(ActionDecision::Approved, $byId['call_b']->decision);
        $this->assertEquals(ApprovalState::Pending, $a->getApprovalState(), 'the reused instance itself was reset');
    }

    public function test_stale_rejection_does_not_survive_on_reused_call_instances(): void
    {
        $store = $this->stepStore();
        $memoizer = new StepMemoizer($store, new PhpSerializer(), 'approval_flow_test', 'ToolNode-1');
        $node = $this->node([$this->gatedTool('a'), $this->gatedTool('b')]);
        $state = new AgentState();

        $a = ToolCall::make('a', 'call_a');
        $b = ToolCall::make('b', 'call_b');

        $this->assertSuspends($node, $this->createToolCallEvent([$a, $b]), $state, memoizer: $memoizer);

        // Reject a only — incomplete, so it re-suspends.
        $this->assertSuspends($node, $this->createToolCallEvent([$a, $b]), $state, payload: ['call_a' => ['reject', 'not now']], memoizer: $memoizer);
        $this->assertEquals(ApprovalState::Rejected, $a->getApprovalState());

        // A payload omitting a must not leave the stale rejection (or its reason) behind.
        $this->assertSuspends($node, $this->createToolCallEvent([$a, $b]), $state, payload: ['call_b' => 'approve'], memoizer: $memoizer);

        $this->assertEquals(ApprovalState::Pending, $a->getApprovalState());
        $this->assertNull($a->getRejectReason(), 'the stale rejection reason was cleared with the state');
    }

    public function test_complete_decision_set_executes_approved_and_stamps_rejected(): void
    {
        $store = $this->stepStore();
        $memoizer = new StepMemoizer($store, new PhpSerializer(), 'approval_flow_test', 'ToolNode-1');
        $node = $this->node([$this->gatedTool('a'), $this->gatedTool('b')]);
        $state = new AgentState();

        $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('a', 'call_a'), ToolCall::make('b', 'call_b')]),
            $state,
            memoizer: $memoizer
        );

        // The full decision set in one resume — approve a, reject b.
        $a2 = ToolCall::make('a', 'call_a');
        $b2 = ToolCall::make('b', 'call_b');
        $this->assertExecutes(
            $node,
            $this->createToolCallEvent([$a2, $b2]),
            $state,
            payload: ['call_a' => 'approve', 'call_b' => ['reject', 'not now']],
            memoizer: $memoizer,
            message: 'A complete decision set must proceed'
        );

        $this->assertEquals(ApprovalState::Approved, $a2->getApprovalState());
        $this->assertSame('executed', $a2->getResult(), 'The approved tool ran');
        $this->assertStringContainsString('TOOL NOT EXECUTED', $b2->getResult());
        $this->assertStringContainsString('not now', $b2->getResult());
    }

    public function test_decision_overwrite_before_completeness(): void
    {
        $store = $this->stepStore();
        $memoizer = new StepMemoizer($store, new PhpSerializer(), 'approval_flow_test', 'ToolNode-1');
        $node = $this->node([$this->gatedTool('a'), $this->gatedTool('b')]);
        $state = new AgentState();

        $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('a', 'call_a'), ToolCall::make('b', 'call_b')]),
            $state,
            memoizer: $memoizer
        );

        // Approve a.
        $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('a', 'call_a'), ToolCall::make('b', 'call_b')]),
            $state,
            payload: ['call_a' => 'approve'],
            memoizer: $memoizer
        );

        // The next cumulative payload flips a to rejected; b still pending → suspends.
        $request = $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('a', 'call_a'), ToolCall::make('b', 'call_b')]),
            $state,
            payload: ['call_a' => ['reject', 'changed my mind']],
            memoizer: $memoizer,
            message: 'Still incomplete — must re-suspend'
        );

        $byId = $this->actionsById($request);
        $this->assertEquals(ActionDecision::Rejected, $byId['call_a']->decision, 'Latest payload wins');
        $this->assertEquals('changed my mind', $byId['call_a']->feedback);
        $this->assertEquals(ActionDecision::Pending, $byId['call_b']->decision);
    }

    public function test_unknown_call_id_in_payload_is_ignored(): void
    {
        $store = $this->stepStore();
        $memoizer = new StepMemoizer($store, new PhpSerializer(), 'approval_flow_test', 'ToolNode-1');
        $node = $this->node([$this->gatedTool('a'), $this->gatedTool('b')]);
        $state = new AgentState();

        $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('a', 'call_a'), ToolCall::make('b', 'call_b')]),
            $state,
            memoizer: $memoizer
        );

        // Unknown callId — states unchanged, re-suspends.
        $request = $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('a', 'call_a'), ToolCall::make('b', 'call_b')]),
            $state,
            payload: ['bogus' => 'approve'],
            memoizer: $memoizer
        );

        $byId = $this->actionsById($request);
        $this->assertEquals(ActionDecision::Pending, $byId['call_a']->decision);
        $this->assertEquals(ActionDecision::Pending, $byId['call_b']->decision);
    }

    public function test_non_gated_tools_are_untouched_by_the_settle(): void
    {
        $store = $this->stepStore();
        $memoizer = new StepMemoizer($store, new PhpSerializer(), 'approval_flow_test', 'ToolNode-1');
        $node = $this->node([$this->gatedTool('gated'), $this->plainTool('plain')]);
        $state = new AgentState();

        $gatedCall = ToolCall::make('gated', 'call_gated');
        $plainCall = ToolCall::make('plain', 'call_plain');

        $this->assertExecutes(
            $node,
            $this->createToolCallEvent([$gatedCall, $plainCall]),
            $state,
            payload: ['call_gated' => 'approve'],
            memoizer: $memoizer
        );

        $this->assertEquals(ApprovalState::Approved, $gatedCall->getApprovalState());
        $this->assertNull($plainCall->getApprovalState(), 'A non-gated call must keep null approval state');
        $this->assertSame('executed', $plainCall->getResult());
    }

    public function test_history_write_happens_once_across_suspend_and_resume(): void
    {
        $store = $this->stepStore();
        $memoizer = new StepMemoizer($store, new PhpSerializer(), 'approval_flow_test', 'ToolNode-1');
        $history = new InMemoryChatHistory();
        $node = $this->node([$this->gatedTool('a'), $this->gatedTool('b')], $history);
        $state = new AgentState();

        $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('a', 'call_a'), ToolCall::make('b', 'call_b')]),
            $state,
            memoizer: $memoizer
        );

        // Two partial resumes, then completion — the write must never repeat.
        $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('a', 'call_a'), ToolCall::make('b', 'call_b')]),
            $state,
            payload: ['call_a' => 'approve'],
            memoizer: $memoizer
        );
        $this->assertExecutes(
            $node,
            $this->createToolCallEvent([ToolCall::make('a', 'call_a'), ToolCall::make('b', 'call_b')]),
            $state,
            payload: ['call_a' => 'approve', 'call_b' => 'approve'],
            memoizer: $memoizer
        );

        $toolCallCount = 0;
        foreach ($history->getMessages() as $message) {
            if ($message instanceof ToolCallMessage) {
                $toolCallCount++;
            }
        }
        $this->assertSame(1, $toolCallCount, 'Exactly one ToolCallMessage across initial run + two resumes');
    }

    public function test_distinct_tool_cycles_write_their_own_messages(): void
    {
        // Two gated tool cycles in one run = two node steps: the memoized
        // pre-suspend write is scoped per step, so each cycle records its own
        // ToolCallMessage (the non-gated path writes nothing here at all).
        $store = $this->stepStore();
        $history = new InMemoryChatHistory();
        $node = $this->node([$this->gatedTool('first'), $this->gatedTool('second')], $history);
        $state = new AgentState();

        $firstCycle = new StepMemoizer($store, new PhpSerializer(), 'approval_flow_test', 'ToolNode-1');
        $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('first', 'call_1')]),
            $state,
            memoizer: $firstCycle
        );
        $this->assertExecutes(
            $node,
            $this->createToolCallEvent([ToolCall::make('first', 'call_1')]),
            $state,
            payload: ['call_1' => 'approve'],
            memoizer: $firstCycle
        );

        $secondCycle = new StepMemoizer($store, new PhpSerializer(), 'approval_flow_test', 'ToolNode-3');
        $this->assertSuspends(
            $node,
            $this->createToolCallEvent([ToolCall::make('second', 'call_2')]),
            $state,
            memoizer: $secondCycle
        );
        $this->assertExecutes(
            $node,
            $this->createToolCallEvent([ToolCall::make('second', 'call_2')]),
            $state,
            payload: ['call_2' => 'approve'],
            memoizer: $secondCycle
        );

        $toolCallCount = 0;
        foreach ($history->getMessages() as $message) {
            if ($message instanceof ToolCallMessage) {
                $toolCallCount++;
            }
        }
        $this->assertSame(2, $toolCallCount, 'Each cycle records its own tool call message');
    }
}
