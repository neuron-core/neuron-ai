<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Conversation\Conversation;
use NeuronAI\Evaluation\Conversation\UserSimulator;
use NeuronAI\Evaluation\EvaluationException;
use NeuronAI\Evaluation\Trajectory\Trajectory;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use PHPUnit\Framework\TestCase;
use function json_encode;

class ConversationTest extends TestCase
{
    protected function makeAgent(FakeAIProvider $provider, bool $withApproval = false): Agent
    {
        $agent = Agent::make();
        $agent->setChatHistory(new InMemoryChatHistory());
        $agent->setAiProvider($provider);

        if ($withApproval) {
            // Attach-time approval config.
            $agent->addTool((new SearchTool())->requireApproval());
            $agent->setExecutor(new WorkflowExecutor());
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

    public function test_run_without_configuration_throws(): void
    {
        $conversation = Conversation::make($this->makeAgent(new FakeAIProvider()));

        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage('nothing to run');

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
        $this->expectExceptionMessage('no approval policy is configured');

        $conversation->run();
    }

    public function test_incomplete_decision_set_throws(): void
    {
        $provider = new FakeAIProvider(
            $this->searchCall('call_1', 'PHP frameworks'),
            new AssistantMessage('Never reached.'),
        );

        $conversation = Conversation::make($this->makeAgent($provider, withApproval: true))
            ->withTurns(['Search for PHP frameworks'])
            ->withApprovals(fn (InterruptRequest $request, Trajectory $soFar): array => []);

        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage('incomplete decision set');

        $conversation->run();
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

    public function test_multiple_active_interruptions_are_resolved_one_at_a_time(): void
    {
        $first = new AgentState();
        $first->markAsSuspended([
            1 => (new ApprovalRequest('first'))->withId(1),
            2 => (new ApprovalRequest('second'))->withId(2),
        ]);

        $second = new AgentState();
        $second->markAsSuspended([
            2 => (new ApprovalRequest('second'))->withId(2),
        ]);

        $completed = new AgentState();
        $completed->clearInterrupt();

        $history = new InMemoryChatHistory();
        $resumedInterrupts = [];

        $agent = $this->createMock(AgentInterface::class);
        $agent->method('getChatHistory')->willReturn($history);
        $agent->expects($this->once())->method('chat')->willReturn($first);
        $agent->expects($this->exactly(2))
            ->method('run')
            ->willReturnCallback(function (array $inputs) use (&$resumedInterrupts, $second, $completed): AgentState {
                $input = $inputs[0] ?? null;
                $this->assertInstanceOf(ResumeInput::class, $input);
                $resumedInterrupts[] = $input->interruptId;

                return $input->interruptId === 1 ? $second : $completed;
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
        $this->assertSame([1, 2], $resumedInterrupts);
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
        $simulator = $this->makeSimulator(new FakeAIProvider(
            $this->simulatorResponse(stop: false, message: 'Tell me more (1)'),
            $this->simulatorResponse(stop: false, message: 'Tell me more (2)'),
            $this->simulatorResponse(stop: false, message: 'Tell me more (3)'),
            $this->simulatorResponse(stop: false, message: 'Tell me more (4)'),
        ));

        $agentProvider = new FakeAIProvider(
            new AssistantMessage('Answer 1'),
            new AssistantMessage('Answer 2'),
            new AssistantMessage('Answer 3'),
        );

        $trajectory = Conversation::make($this->makeAgent($agentProvider))
            ->withUser($simulator, maxTurns: 3)
            ->run();

        // The cap ends the conversation normally — three user turns, no error.
        $this->assertCount(3, $trajectory->userMessages());
        $this->assertSame('Answer 3', $trajectory->finalAnswer());
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
        $this->expectExceptionMessage('mutually exclusive');

        $conversation->run();
    }

    public function test_max_turns_below_one_throws(): void
    {
        $this->expectException(EvaluationException::class);
        $this->expectExceptionMessage('maxTurns must be at least 1');

        Conversation::make($this->makeAgent(new FakeAIProvider()))
            ->withUser($this->makeSimulator(new FakeAIProvider()), maxTurns: 0);
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
