<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Conversation;

use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ActionDecision;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Conversation\Conversation;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Conversation\UserSimulator;
use NeuronAI\Evaluation\EvaluationException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Executor\SequentialBranchRunner;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function json_encode;

class ConversationTest extends TestCase
{
    protected function makeAgent(FakeAIProvider $provider, bool $withApproval = false): Agent
    {
        $agent = Agent::make();
        $agent->setMessageStore(new InMemoryMessageStore());
        $agent->setAiProvider($provider);

        if ($withApproval) {
            // Attach-time approval config.
            $agent->addTool((new SearchTool())->requireApproval());
            $agent->setBranchRunner(new SequentialBranchRunner());
        }

        return $agent;
    }

    protected function searchCall(string $callId, string $query): ToolCallMessage
    {
        return new ToolCallMessage(null, [
            ToolCall::make('search', $callId, ['query' => $query]),
        ]);
    }

    public function test_scripted_multi_turn_conversation(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Hi! How can I help?'),
            new AssistantMessage('The capital of France is Paris.'),
        );

        $trajectory = Conversation::make($this->makeAgent($provider))
            ->withTurns([
                'Hello',
                new UserMessage('What is the capital of France?'),
            ])
            ->run();

        $this->assertInstanceOf(Trajectory::class, $trajectory);
        $this->assertSame(['Hello', 'What is the capital of France?'], $trajectory->userMessages());
        $this->assertSame('The capital of France is Paris.', $trajectory->finalAnswer());
        $provider->assertCallCount(2);
    }

    public function test_scripted_user_messages_are_delivered_with_their_attachments(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('A cat.'));
        $turn = new UserMessage('What is in this picture?');
        $turn->addContent(new ImageContent('https://example.com/cat.png', SourceType::URL, 'image/png'));

        $trajectory = Conversation::make($this->makeAgent($provider))->withTurns([$turn])->run();

        $this->assertSame(
            "User: What is in this picture? [attached: image (image/png)]\nAssistant: A cat.",
            $trajectory->toTranscript()
        );
    }

    public function test_run_without_configuration_throws(): void
    {
        $conversation = Conversation::make($this->makeAgent(new FakeAIProvider()));

        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage(
            'The conversation has nothing to run. Configure a script with withTurns() or a simulator with withUser().'
        );

        $conversation->run();
    }

    public function test_approval_flow_approve(): void
    {
        $provider = new FakeAIProvider(
            $this->searchCall('call_1', 'PHP frameworks'),
            new AssistantMessage('Here are the search results.'),
        );

        $seenRequests = [];

        $trajectory = Conversation::make($this->makeAgent($provider, withApproval: true))
            ->withTurns(['Search for PHP frameworks'])
            ->withApprovals(function (ApprovalRequest $request, Trajectory $soFar) use (&$seenRequests): array {
                $seenRequests[] = $request;

                $payload = [];
                foreach ($request->getActions() as $action) {
                    if ($action->isPending()) {
                        $payload[$action->id] = 'approve';
                    }
                }

                return $payload;
            })
            ->run();

        $this->assertCount(1, $seenRequests);
        $this->assertInstanceOf(ApprovalRequest::class, $seenRequests[0]);

        $call = $trajectory->lastToolCall('search');
        $this->assertNotNull($call);
        $this->assertSame(ApprovalState::Approved, $call->getApprovalState());
        $this->assertSame('Results for: PHP frameworks', $call->getResult());
        $this->assertSame('Here are the search results.', $trajectory->finalAnswer());
    }

    public function test_approval_flow_reject(): void
    {
        $provider = new FakeAIProvider(
            $this->searchCall('call_1', 'PHP frameworks'),
            new AssistantMessage('Understood, I will not search.'),
        );

        $trajectory = Conversation::make($this->makeAgent($provider, withApproval: true))
            ->withTurns(['Search for PHP frameworks'])
            ->withApprovals(fn (InterruptRequest $request, Trajectory $soFar): array => [
                'call_1' => ['reject', 'searching is not allowed'],
            ])
            ->run();

        $call = $trajectory->lastToolCall('search');
        $this->assertNotNull($call);
        $this->assertSame(ApprovalState::Rejected, $call->getApprovalState());
        $this->assertSame('searching is not allowed', $call->getRejectReason());
        $this->assertSame('Understood, I will not search.', $trajectory->finalAnswer());
    }

    public function test_policy_receives_trajectory_with_pending_tail(): void
    {
        $provider = new FakeAIProvider(
            $this->searchCall('call_1', 'PHP frameworks'),
            new AssistantMessage('Done.'),
        );

        $stateAtPolicyTime = null;
        $inputsAtPolicyTime = null;

        Conversation::make($this->makeAgent($provider, withApproval: true))
            ->withTurns(['Search for PHP frameworks'])
            ->withApprovals(function (InterruptRequest $request, Trajectory $soFar) use (&$stateAtPolicyTime, &$inputsAtPolicyTime): array {
                // Argument-dependent decisions read arguments from the Trajectory
                // tail at policy time. (Read values, not objects: the trajectory
                // exposes the LIVE tool entries, which the middleware annotates
                // in place on resume.)
                $call = $soFar->lastToolCall('search');
                $stateAtPolicyTime = $call?->getApprovalState();
                $inputsAtPolicyTime = $call?->getInputs();

                return ['call_1' => 'approve'];
            })
            ->run();

        $this->assertSame(ApprovalState::Pending, $stateAtPolicyTime);
        $this->assertSame(['query' => 'PHP frameworks'], $inputsAtPolicyTime);
    }

    public function test_suspension_without_policy_throws(): void
    {
        $provider = new FakeAIProvider(
            $this->searchCall('call_1', 'PHP frameworks'),
            new AssistantMessage('Never reached.'),
        );

        $conversation = Conversation::make($this->makeAgent($provider, withApproval: true))
            ->withTurns(['Search for PHP frameworks']);

        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage(
            'The agent suspended (' . ApprovalRequest::class . ') but no approval policy is configured.'
            . ' Configure one with withApprovals() — silence is never consent.'
        );

        $conversation->run();
    }

    public function test_incomplete_decision_set_throws(): void
    {
        $provider = new FakeAIProvider(
            $this->searchCall('call_1', 'PHP frameworks'),
            new AssistantMessage('Never reached.'),
        );

        $policyCalls = 0;
        $conversation = Conversation::make($this->makeAgent($provider, withApproval: true))
            ->withTurns(['Search for PHP frameworks'])
            ->withApprovals(function () use (&$policyCalls): array {
                // Resuming with the incomplete set would re-suspend and ask again forever: fail fast instead
                if (++$policyCalls > 1) {
                    throw new LogicException('The incomplete decision set was resumed');
                }

                return [];
            });

        try {
            $conversation->run();
            $this->fail('An incomplete decision set must not resume the agent');
        } catch (EvaluationException $exception) {
            $this->assertSame(
                'The approval policy returned an incomplete decision set — missing decisions for: search (call_1).'
                . ' An incomplete set would re-suspend the workflow.',
                $exception->getMessage()
            );
        }

        // The agent was never resumed with the incomplete payload
        $provider->assertCallCount(1);
    }

    public function test_decision_set_must_cover_only_the_pending_actions(): void
    {
        $request = (new ApprovalRequest('Approve the actions', [
            new Action('call_1', 'search'),
            new Action('call_2', 'refund_order'),
            new Action('call_3', 'send_email', decision: ActionDecision::Approved),
        ]))->withId(1);

        $payloads = [];
        $agent = $this->suspendingAgent($request, $payloads);

        Conversation::make($agent)
            ->withTurns(['Do it'])
            ->withApprovals(fn (InterruptRequest $request): array => [
                'call_1' => 'approve',
                'call_2' => ['reject', 'not allowed'],
            ])
            ->run();

        $this->assertSame([['call_1' => 'approve', 'call_2' => ['reject', 'not allowed']]], $payloads);
    }

    public function test_incomplete_decision_set_names_every_missing_pending_action(): void
    {
        $request = (new ApprovalRequest('Approve the actions', [
            new Action('call_1', 'search'),
            new Action('call_2', 'refund_order'),
            new Action('call_3', 'send_email'),
            new Action('call_4', 'archive', decision: ActionDecision::Rejected),
        ]))->withId(1);

        $payloads = [];
        $conversation = Conversation::make($this->suspendingAgent($request, $payloads))
            ->withTurns(['Do it'])
            ->withApprovals(fn (InterruptRequest $request): array => ['call_2' => 'approve']);

        try {
            $conversation->run();
            $this->fail('An incomplete decision set must not resume the agent');
        } catch (EvaluationException $exception) {
            $this->assertStringContainsString('missing decisions for: search (call_1), send_email (call_3).', $exception->getMessage());
        }

        $this->assertSame([], $payloads);
    }

    public function test_non_approval_interrupts_resume_with_the_policy_payload_verbatim(): void
    {
        // Not an approval: no decision-set validation applies, the payload is the policy's own
        $request = (new WaitForEventRequest('payment_confirmed'))->withId(7);
        $payloads = [];
        $seen = [];

        Conversation::make($this->suspendingAgent($request, $payloads))
            ->withTurns(['Continue'])
            ->withApprovals(function (InterruptRequest $request, Trajectory $soFar) use (&$seen): array {
                $seen[] = [$request->getId(), $soFar->count()];
                return ['answer' => 'yes'];
            })
            ->run();

        $this->assertSame([[7, 0]], $seen);
        $this->assertSame([['answer' => 'yes']], $payloads);
    }

    public function test_interrupted_agent_without_an_interrupt_request_is_an_error(): void
    {
        $suspended = new AgentState();
        $suspended->markAsSuspended(null);

        $agent = $this->createMock(AgentInterface::class);
        $agent->method('chat')->willReturn($suspended);
        $agent->expects($this->never())->method('run');
        $policyCalls = 0;

        $conversation = Conversation::make($agent)
            ->withTurns(['Hello'])
            ->withApprovals(function () use (&$policyCalls): array {
                $policyCalls++;
                return [];
            });

        try {
            $conversation->run();
            $this->fail('A suspension without a request cannot be answered');
        } catch (EvaluationException $exception) {
            $this->assertSame('The interrupted Agent exposed no interrupt request.', $exception->getMessage());
        }

        $this->assertSame(0, $policyCalls);
    }

    public function test_turns_after_a_failed_turn_are_not_sent(): void
    {
        $provider = new FakeAIProvider(
            $this->searchCall('call_1', 'PHP frameworks'),
        );

        $conversation = Conversation::make($this->makeAgent($provider, withApproval: true))
            ->withTurns(['Search for PHP frameworks', 'This turn must never be sent']);

        try {
            $conversation->run();
            $this->fail('The suspension without a policy must stop the script');
        } catch (EvaluationException) {
        }

        $provider->assertCallCount(1);
    }

    /**
     * An agent that suspends once on chat() with the given request, records
     * every resume payload and completes on resume.
     *
     * @param array<int, array<string, mixed>> $payloads
     */
    protected function suspendingAgent(InterruptRequest $request, array &$payloads): AgentInterface
    {
        $suspended = new AgentState();
        $suspended->markAsSuspended($request);
        $completed = new AgentState();
        $completed->clearInterrupt();

        $agent = $this->createMock(AgentInterface::class);
        $agent->method('getThreadId')->willReturn(null);
        $agent->method('chat')->willReturn($suspended);
        $agent->method('run')->willReturnCallback(
            function (ExecutionRequest $execution) use (&$payloads, $completed): AgentState {
                $payloads[] = $execution->payload();
                return $completed;
            }
        );

        return $agent;
    }

    public function test_turn_with_consecutive_suspensions(): void
    {
        // The model calls the gated tool again after the first approval: the
        // resume suspends a second time and the policy answers again.
        $provider = new FakeAIProvider(
            $this->searchCall('call_1', 'first query'),
            $this->searchCall('call_2', 'second query'),
            new AssistantMessage('Both searches done.'),
        );

        $policyCalls = 0;

        $trajectory = Conversation::make($this->makeAgent($provider, withApproval: true))
            ->withTurns(['Search twice'])
            ->withApprovals(function (ApprovalRequest $request, Trajectory $soFar) use (&$policyCalls): array {
                $policyCalls++;

                $payload = [];
                foreach ($request->getActions() as $action) {
                    if ($action->isPending()) {
                        $payload[$action->id] = 'approve';
                    }
                }

                return $payload;
            })
            ->run();

        $this->assertSame(2, $policyCalls);
        $this->assertCount(2, $trajectory->toolCalls('search'));
        $this->assertSame('Both searches done.', $trajectory->finalAnswer());
    }

    public function test_sequential_interruptions_are_resolved_one_at_a_time(): void
    {
        $first = new AgentState();
        $first->markAsSuspended((new ApprovalRequest('first'))->withId(1));

        $second = new AgentState();
        $second->markAsSuspended((new ApprovalRequest('second'))->withId(2));

        $completed = new AgentState();
        $completed->clearInterrupt();

        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $responses = [];

        $agent = $this->createMock(AgentInterface::class);
        $agent->method('getChatHistory')->willReturn($history);
        $agent->expects($this->once())->method('chat')->willReturn($first);
        $agent->expects($this->exactly(2))->method('run')
            ->willReturnCallback(function (\NeuronAI\Workflow\Executor\ExecutionRequest $request) use (&$responses, $second, $completed): AgentState {
                $this->assertSame([], $request->payload());
                $responses[] = $request->payload();
                return count($responses) === 1 ? $second : $completed;
            });

        $policyInterrupts = [];

        Conversation::make($agent)
            ->withTurns(['Approve both'])
            ->withApprovals(function (InterruptRequest $request) use (&$policyInterrupts): array {
                $policyInterrupts[] = $request->getId();
                return [];
            })
            ->run();

        $this->assertSame([1, 2], $policyInterrupts);
        $this->assertSame([[], []], $responses);
    }

    protected function makeSimulator(FakeAIProvider $provider): UserSimulator
    {
        $simulator = UserSimulator::make()
            ->withPersona('A curious user')
            ->withGoal('Learn the capital of France');
        $simulator->setAiProvider($provider);

        return $simulator;
    }

    protected function simulatorResponse(bool $stop, ?string $message = null): AssistantMessage
    {
        return new AssistantMessage((string) json_encode([
            'stop' => $stop,
            'message' => $message,
            'reason' => $stop ? 'goal satisfied' : 'continuing',
        ]));
    }

    public function test_simulated_conversation_runs_until_the_simulator_stops(): void
    {
        $simulator = $this->makeSimulator(new FakeAIProvider(
            $this->simulatorResponse(stop: false, message: 'What is the capital of France?'),
            $this->simulatorResponse(stop: true),
        ));

        $agentProvider = new FakeAIProvider(
            new AssistantMessage('The capital of France is Paris.'),
        );

        $trajectory = Conversation::make($this->makeAgent($agentProvider))
            ->withUser($simulator, maxTurns: 5)
            ->run();

        $this->assertSame(['What is the capital of France?'], $trajectory->userMessages());
        $this->assertSame('The capital of France is Paris.', $trajectory->finalAnswer());
        $agentProvider->assertCallCount(1);
    }

    public function test_simulated_conversation_respects_max_turns(): void
    {
        $simulatorProvider = new FakeAIProvider(
            $this->simulatorResponse(stop: false, message: 'Tell me more (1)'),
            $this->simulatorResponse(stop: false, message: 'Tell me more (2)'),
            $this->simulatorResponse(stop: false, message: 'Tell me more (3)'),
            $this->simulatorResponse(stop: false, message: 'Tell me more (4)'),
        );
        $simulator = $this->makeSimulator($simulatorProvider);

        $agentProvider = new FakeAIProvider(
            new AssistantMessage('Answer 1'),
            new AssistantMessage('Answer 2'),
            new AssistantMessage('Answer 3'),
        );

        $trajectory = Conversation::make($this->makeAgent($agentProvider))
            ->withUser($simulator, maxTurns: 3)
            ->run();

        // The cap ends the conversation normally — three user turns, no error.
        $this->assertSame(['Tell me more (1)', 'Tell me more (2)', 'Tell me more (3)'], $trajectory->userMessages());
        $this->assertSame('Answer 3', $trajectory->finalAnswer());
        // The simulator is not asked for a turn beyond the cap
        $simulatorProvider->assertCallCount(3);
    }

    public function test_simulator_stopping_immediately_yields_an_empty_trajectory(): void
    {
        $simulatorProvider = new FakeAIProvider($this->simulatorResponse(stop: true));
        $agentProvider = new FakeAIProvider();

        $trajectory = Conversation::make($this->makeAgent($agentProvider))
            ->withUser($this->makeSimulator($simulatorProvider), maxTurns: 5)
            ->run();

        $this->assertSame(0, $trajectory->count());
        $this->assertSame('', $trajectory->finalAnswer());
        $agentProvider->assertNothingSent();
    }

    public function test_simulator_sees_the_conversation_so_far_before_each_turn(): void
    {
        $simulatorProvider = new FakeAIProvider(
            $this->simulatorResponse(stop: false, message: 'What is the capital of France?'),
            $this->simulatorResponse(stop: true),
        );

        Conversation::make($this->makeAgent(new FakeAIProvider(new AssistantMessage('Paris.'))))
            ->withUser($this->makeSimulator($simulatorProvider), maxTurns: 5)
            ->run();

        $prompts = array_map(
            static fn (RequestRecord $record): string => (string) $record->messages[0]->getContent(),
            $simulatorProvider->getRecorded()
        );
        $this->assertStringContainsString('the conversation has not started yet', $prompts[0]);
        $this->assertStringContainsString("User: What is the capital of France?\nAssistant: Paris.", $prompts[1]);
    }

    public function test_simulated_conversation_with_approval_flow(): void
    {
        $simulator = $this->makeSimulator(new FakeAIProvider(
            $this->simulatorResponse(stop: false, message: 'Search for PHP frameworks'),
            $this->simulatorResponse(stop: true),
        ));

        $agentProvider = new FakeAIProvider(
            $this->searchCall('call_1', 'PHP frameworks'),
            new AssistantMessage('Here is what I found.'),
        );

        $trajectory = Conversation::make($this->makeAgent($agentProvider, withApproval: true))
            ->withUser($simulator, maxTurns: 5)
            ->withApprovals(fn (InterruptRequest $request, Trajectory $soFar): array => ['call_1' => 'approve'])
            ->run();

        $call = $trajectory->lastToolCall('search');
        $this->assertNotNull($call);
        $this->assertSame(ApprovalState::Approved, $call->getApprovalState());
        $this->assertSame('Here is what I found.', $trajectory->finalAnswer());
    }

    public function test_both_configuration_paths_throw(): void
    {
        $conversation = Conversation::make($this->makeAgent(new FakeAIProvider()))
            ->withTurns(['Hello'])
            ->withUser($this->makeSimulator(new FakeAIProvider()), maxTurns: 3);

        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage('withTurns() and withUser() are mutually exclusive. Configure one path.');

        $conversation->run();
    }

    public function test_max_turns_below_one_throws(): void
    {
        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage('maxTurns must be at least 1.');

        Conversation::make($this->makeAgent(new FakeAIProvider()))
            ->withUser($this->makeSimulator(new FakeAIProvider()), maxTurns: 0);
    }

    public function test_negative_max_turns_throws(): void
    {
        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage('maxTurns must be at least 1.');

        Conversation::make($this->makeAgent(new FakeAIProvider()))
            ->withUser($this->makeSimulator(new FakeAIProvider()), maxTurns: -1);
    }

    public function test_approval_mid_multi_turn_script(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Hello! What do you need?'),
            $this->searchCall('call_1', 'PHP frameworks'),
            new AssistantMessage('Here is what I found.'),
            new AssistantMessage('You are welcome!'),
        );

        $trajectory = Conversation::make($this->makeAgent($provider, withApproval: true))
            ->withTurns([
                'Hi',
                'Search for PHP frameworks',
                'Thanks!',
            ])
            ->withApprovals(fn (InterruptRequest $request, Trajectory $soFar): array => ['call_1' => 'approve'])
            ->run();

        $this->assertSame(['Hi', 'Search for PHP frameworks', 'Thanks!'], $trajectory->userMessages());
        $this->assertCount(1, $trajectory->toolCalls('search'));
        $this->assertSame('You are welcome!', $trajectory->finalAnswer());
    }
}
