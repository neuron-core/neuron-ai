<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\CountingTool;
use NeuronAI\Tests\Agent\Stub\CrashSearchTool;
use NeuronAI\Tests\Support\WorkflowTestStore;
use NeuronAI\Tools\DeferredTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ToolRunLimitTest extends TestCase
{
    protected InMemoryPersistence $persistence;
    protected InMemoryChatHistory $history;
    protected FakeAIProvider $provider;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
        $this->history = new InMemoryChatHistory('tool-run-limit');
        $this->provider = new FakeAIProvider();
        CountingTool::reset();
    }

    /** @param ToolInterface[] $tools */
    protected function agent(array $tools, int $limit = 1, bool $parallel = false): Agent
    {
        $agent = Agent::make();
        $agent->setPersistence($this->persistence);
        $agent->setChatHistory($this->history);
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
        $tools = [new DeferredTool('browser')];
        $state = $this->agent($tools)->chat(new UserMessage('Go'));
        $this->assertSame(1, $state->getToolRuns('browser'));
        $this->expectException(ToolRunsExceededException::class);
        $this->agent($tools)->toolResults(['a' => ['result' => 'ok']])->run();
    }

    public function test_partial_results_preserve_the_batch_count_without_consuming_more_slots(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [new ToolCall('browser', 'a', deferred: true), new ToolCall('browser', 'b', deferred: true)]),
            new AssistantMessage('Done'),
        );
        $tools = [new DeferredTool('browser')];
        $this->agent($tools, 2)->chat(new UserMessage('Go'));
        $state = $this->agent([], 2)->toolResults(['a' => ['result' => 'ok']])->run();
        $this->assertTrue($state->isInterrupted());
        $this->assertSame(2, $state->getToolRuns('browser'));
        $state = $this->agent([], 2)->toolResults(['b' => ['result' => 'ok']])->run();
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
        $tools = [new CountingTool(), new DeferredTool('browser')];
        $this->agent($tools, $limit, $parallel)->chat(new UserMessage('Go'));
        $this->expectException(ToolRunsExceededException::class);
        $this->agent($tools, $limit, $parallel)->toolResults(['external' => ['result' => 'ok']])->run();
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
        $state = $this->agent($tools)->toolApprovalDecisions(['a' => 'approve'])->run();
        $this->assertSame(1, $state->getToolRuns('lookup'));
        $state = $this->agent($tools)->toolApprovalDecisions(['b' => 'reject'])->run();
        $this->assertSame(1, $state->getToolRuns('lookup'));
        $this->assertSame(1, CountingTool::$executions);
        $this->expectException(ToolRunsExceededException::class);
        $this->agent($tools)->toolApprovalDecisions(['c' => 'approve'])->run();
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
        $initial->request = new InferenceRequest('Test', [new CountingTool(), $crashing]);
        $initial->incrementToolRun('lookup');
        $event = new ToolCallEvent(new ToolCallMessage(null, [
            new ToolCall('lookup', 'completed', ['query' => 'PHP']),
            new ToolCall('search', 'failed', ['query' => 'PHP']),
        ]));
        try {
            $this->runNode(clone $initial, $event, 'batch', 2);
            $this->fail('The second call must fail on its first execution.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash', $e->getMessage());
        }

        $restored = clone $initial;
        $restored->request->tools = [$crashing];
        $this->runNode($restored, $event, 'batch', 2);
        $this->assertSame(2, $restored->getToolRuns('lookup'));
        $this->assertSame(1, $restored->getToolRuns('search'));
        $this->assertSame(1, CountingTool::$executions);
        $this->assertSame(2, $crashing->getCallCount());

        $restored->request->tools = [new CountingTool()];
        $next = new ToolCallEvent(new ToolCallMessage(null, [new ToolCall('lookup', 'next', ['query' => 'Rust'])]));
        $this->expectException(ToolRunsExceededException::class);
        $this->runNode($restored, $next, 'next-batch', 2);
    }

    public function test_replaying_a_batch_does_not_lower_or_increment_recorded_counts(): void
    {
        $state = new AgentState();
        $state->request = new InferenceRequest('Test', [new CountingTool()]);
        $event = new ToolCallEvent(new ToolCallMessage(null, [
            new ToolCall('lookup', 'a', ['query' => 'PHP']),
            new ToolCall('lookup', 'b', ['query' => 'Rust']),
        ]));
        $this->runNode($state, $event, 'completed-batch', 2);
        $this->assertSame(2, $state->getToolRuns('lookup'));

        $state->request->tools = [];
        $this->runNode($state, $event, 'completed-batch', 2);
        $this->assertSame(2, $state->getToolRuns('lookup'));
        $this->assertSame(2, CountingTool::$executions);
    }

    public function test_over_limit_decision_is_preserved_on_replay(): void
    {
        $event = new ToolCallEvent(new ToolCallMessage(null, [new ToolCall('browser', 'a', deferred: true)]));
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $state = new AgentState();
            $state->request = new InferenceRequest('Test', [new DeferredTool('browser')]);
            try {
                $this->runNode($state, $event, 'denied', $attempt === 0 ? 0 : 10);
                $this->fail('The recorded limit must still reject the call on replay.');
            } catch (ToolRunsExceededException) {
                $this->assertSame(1, $state->getToolRuns('browser'));
            }
        }
    }

    protected function runNode(AgentState $state, ToolCallEvent $event, string $step, int $limit): void
    {
        $node = new ToolNode($this->history, $limit);
        $node->setWorkflowContext(new NodeContext(
            $state,
            $event,
            memoizer: WorkflowTestStore::memoizer($this->persistence, 'counter-recovery', $step),
        ));
        iterator_to_array($node($event, $state));
    }
}
