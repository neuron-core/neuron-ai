<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Tests\Support\AgentResourcesFactory;
use NeuronAI\Agent\Interrupt\ToolResultsTranslator;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\CountingTool;
use NeuronAI\Tests\Agent\Stub\CrashSearchTool;
use NeuronAI\Tests\Support\WorkflowTestStore;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function array_map;
use function iterator_to_array;

class ToolRunLimitTest extends TestCase
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

    /** @param ToolInterface[] $tools */
    protected function agent(array $tools, int $limit = 1, bool $parallel = false): Agent
    {
        $agent = Agent::make();
        $agent->setPersistence($this->persistence);
        $agent->setMessageStore($this->messages)->setThreadId('tool-run-limit');
        $agent->setAiProvider($this->provider);
        $agent->addTool($tools);
        $agent->toolMaxRuns($limit);
        $agent->parallelToolCalls($parallel);
        return $agent;
    }

    public function test_deferred_limit_survives_a_resume_on_a_fresh_agent(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [new ToolCall('browser', 'a', deferred: true)]),
            new ToolCallMessage(null, [new ToolCall('browser', 'b', deferred: true)]),
        );
        $tools = [new FrontendTool('browser')];
        $state = $this->agent($tools)->chat(new UserMessage('Go'));
        $this->assertSame(1, $state->getToolRuns('browser'));
        $this->expectException(ToolRunsExceededException::class);
        $invocationAgent = $this->agent($tools);
        $invocationAgent->submitInputs(['a' => ['result' => 'ok']], new ToolResultsTranslator())->run();
    }

    public function test_partial_results_preserve_the_batch_count_without_consuming_more_slots(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [new ToolCall('browser', 'a', deferred: true), new ToolCall('browser', 'b', deferred: true)]),
            new AssistantMessage('Done'),
        );
        $tools = [new FrontendTool('browser')];
        $this->agent($tools, 2)->chat(new UserMessage('Go'));
        $invocationAgent = $this->agent([], 2);
        $state = $invocationAgent->submitInputs(['a' => ['result' => 'ok']], new ToolResultsTranslator())->run();
        $this->assertTrue($state->isInterrupted());
        $this->assertSame(2, $state->getToolRuns('browser'));
        $invocationAgent = $this->agent([], 2);
        $state = $invocationAgent->submitInputs(['b' => ['result' => 'ok']], new ToolResultsTranslator())->run();
        $this->assertFalse($state->isInterrupted());
        $this->assertSame(2, $state->getToolRuns('browser'));
    }

    #[DataProvider('executionModes')]
    public function test_local_limit_survives_an_external_wait(bool $parallel): void
    {
        $calls = [new ToolCall('lookup', 'local-a', ['query' => 'PHP'])];
        if ($parallel) {
            $calls[] = new ToolCall('lookup', 'local-b', ['query' => 'Python']);
        }
        $calls[] = new ToolCall('browser', 'external', deferred: true);
        $this->provider->addResponses(
            new ToolCallMessage(null, $calls),
            new ToolCallMessage(null, [new ToolCall('lookup', 'next', ['query' => 'Rust'])]),
        );
        $limit = $parallel ? 2 : 1;
        $tools = [new CountingTool(), new FrontendTool('browser')];
        $this->agent($tools, $limit, $parallel)->chat(new UserMessage('Go'));
        $this->expectException(ToolRunsExceededException::class);
        $invocationAgent = $this->agent($tools, $limit, $parallel);
        $invocationAgent->submitInputs(['external' => ['result' => 'ok']], new ToolResultsTranslator())->run();
    }

    public static function executionModes(): array
    {
        return ['sequential' => [false], 'parallel' => [true]];
    }

    public function test_approval_resumes_preserve_counts_and_rejections_do_not_count(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [new ToolCall('lookup', 'a', ['query' => 'PHP'])]),
            new ToolCallMessage(null, [new ToolCall('lookup', 'b', ['query' => 'Rust'])]),
            new ToolCallMessage(null, [new ToolCall('lookup', 'c', ['query' => 'Python'])]),
        );
        $tools = [(new CountingTool())->requireApproval()];
        $this->agent($tools)->chat(new UserMessage('Go'));
        $invocationAgent = $this->agent($tools);
        $state = $invocationAgent->submitInputs(['a' => 'approve'], new ApprovalTranslator())->run();
        $this->assertSame(1, $state->getToolRuns('lookup'));
        $invocationAgent = $this->agent($tools);
        $state = $invocationAgent->submitInputs(['b' => 'reject'], new ApprovalTranslator())->run();
        $this->assertSame(1, $state->getToolRuns('lookup'));
        $this->assertSame(1, CountingTool::$executions);
        $this->expectException(ToolRunsExceededException::class);
        $invocationAgent = $this->agent($tools);
        $invocationAgent->submitInputs(['c' => 'approve'], new ApprovalTranslator())->run();
    }

    public function test_new_runs_on_the_same_agent_start_with_fresh_counters(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [new ToolCall('lookup', 'a', ['query' => 'PHP'])]),
            new AssistantMessage('Done'),
            new ToolCallMessage(null, [new ToolCall('lookup', 'b', ['query' => 'Rust'])]),
            new AssistantMessage('Done again'),
        );
        $agent = $this->agent([new CountingTool()]);
        $this->assertSame(1, $agent->chat(new UserMessage('First'))->getToolRuns('lookup'));
        $this->assertSame(1, $agent->chat(new UserMessage('Second'))->getToolRuns('lookup'));
        $this->assertSame(2, CountingTool::$executions);
    }

    public function test_failed_step_restores_accounting_for_cached_results_and_retries(): void
    {
        $crashing = new CrashSearchTool();
        $initial = new AgentState();
        $initial->request = new InferenceRequest('Test');
        $initial->incrementToolRun('lookup');
        $event = new ToolCallEvent(new ToolCallMessage(null, [
            new ToolCall('lookup', 'completed', ['query' => 'PHP']),
            new ToolCall('search', 'failed', ['query' => 'PHP']),
        ]));
        try {
            $this->runNode(clone $initial, $event, 'batch', 2, [new CountingTool(), $crashing]);
            $this->fail('The second call must fail on its first execution.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash', $e->getMessage());
        }

        $restored = clone $initial;
        $this->runNode($restored, $event, 'batch', 2, [$crashing]);
        $this->assertSame(2, $restored->getToolRuns('lookup'));
        $this->assertSame(1, $restored->getToolRuns('search'));
        $this->assertSame(1, CountingTool::$executions);
        $this->assertSame(2, $crashing->getCallCount());

        $next = new ToolCallEvent(new ToolCallMessage(null, [new ToolCall('lookup', 'next', ['query' => 'Rust'])]));
        $this->expectException(ToolRunsExceededException::class);
        $this->runNode($restored, $next, 'next-batch', 2, [new CountingTool()]);
    }

    public function test_replaying_a_batch_does_not_lower_or_increment_recorded_counts(): void
    {
        $state = new AgentState();
        $state->request = new InferenceRequest('Test');
        $event = new ToolCallEvent(new ToolCallMessage(null, [
            new ToolCall('lookup', 'a', ['query' => 'PHP']),
            new ToolCall('lookup', 'b', ['query' => 'Rust']),
        ]));
        $this->runNode($state, $event, 'completed-batch', 2, [new CountingTool()]);
        $this->assertSame(2, $state->getToolRuns('lookup'));

        $this->runNode($state, $event, 'completed-batch', 2, []);
        $this->assertSame(2, $state->getToolRuns('lookup'));
        $this->assertSame(2, CountingTool::$executions);
    }

    public function test_over_limit_decision_is_preserved_on_replay(): void
    {
        $event = new ToolCallEvent(new ToolCallMessage(null, [new ToolCall('browser', 'a', deferred: true)]));
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $state = new AgentState();
            $state->request = new InferenceRequest('Test');
            try {
                $this->runNode($state, $event, 'denied', $attempt === 0 ? 0 : 10, [new FrontendTool('browser')]);
                $this->fail('The recorded limit must still reject the call on replay.');
            } catch (ToolRunsExceededException) {
                $this->assertSame(1, $state->getToolRuns('browser'));
            }
        }
    }

    public function test_a_tool_limit_overrides_the_agent_limit(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [
                new ToolCall('lookup', 'a', ['query' => 'PHP']),
                new ToolCall('lookup', 'b', ['query' => 'Rust']),
                new ToolCall('lookup', 'c', ['query' => 'Go']),
            ]),
            new ToolCallMessage(null, [new ToolCall('lookup', 'd', ['query' => 'Zig'])]),
        );
        $agent = $this->agent([(new CountingTool())->setMaxRuns(3)], limit: 1);

        try {
            $agent->chat(new UserMessage('Go'));
            $this->fail('The fourth call must exceed the tool limit.');
        } catch (ToolRunsExceededException $exception) {
            $this->assertStringStartsWith('Tool lookup has been executed too many times - 3 -', $exception->getMessage());
        }

        $this->assertSame(3, CountingTool::$executions);
    }

    public function test_only_the_calls_over_the_limit_are_settled_by_the_error_handler(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [
                new ToolCall('lookup', 'a', ['query' => 'PHP']),
                new ToolCall('lookup', 'b', ['query' => 'Rust']),
                new ToolCall('lookup', 'c', ['query' => 'Go']),
            ]),
            new AssistantMessage('Done'),
        );
        $agent = $this->agent([new CountingTool()], limit: 2);
        $agent->toolErrorHandler(fn (Throwable $error, ToolCall $call): string => "{$call->getCallId()}: " . $error::class);

        $state = $agent->chat(new UserMessage('Go'));

        $this->assertSame('Done', $state->getMessage()?->getContent());
        $this->assertSame(2, CountingTool::$executions);
        $this->assertSame(3, $state->getToolRuns('lookup'), 'The refused call still consumed its attempt');
        $result = $this->messages->loadActive('tool-run-limit')[2];
        $this->assertInstanceOf(ToolResultMessage::class, $result);
        $this->assertSame(
            ['Results for: PHP', 'Results for: Rust', 'c: ' . ToolRunsExceededException::class],
            array_map(static fn (ToolCall $call): string|ToolOutput => $call->getResult(), $result->getToolCalls())
        );
    }

    public function test_a_zero_limit_refuses_the_first_call(): void
    {
        $this->provider->addResponses(new ToolCallMessage(null, [new ToolCall('lookup', 'a', ['query' => 'PHP'])]));

        try {
            $this->agent([new CountingTool()], limit: 0)->chat(new UserMessage('Go'));
            $this->fail('A zero limit allows no call.');
        } catch (ToolRunsExceededException $exception) {
            $this->assertStringStartsWith('Tool lookup has been executed too many times - 0 -', $exception->getMessage());
        }

        $this->assertSame(0, CountingTool::$executions);
    }

    /**
     * @param ToolInterface[] $tools
     */
    protected function runNode(AgentState $state, ToolCallEvent $event, string $step, int $limit, array $tools): void
    {
        $node = new ToolNode($limit);
        $node->setWorkflowContext(new NodeContext(
            memoizer: WorkflowTestStore::memoizer($this->persistence, 'counter-recovery', $step),
        ));
        iterator_to_array($node($event, $state, AgentResourcesFactory::make($tools, new ChatHistory($this->messages, 'tool-run-limit'))));
    }
}
