<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\CountingTool;
use NeuronAI\Tools\DeferredTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeferredToolFlowTest extends TestCase
{
    protected InMemoryChatHistory $history;
    protected InMemoryPersistence $persistence;
    protected FakeAIProvider $provider;

    protected function setUp(): void
    {
        $this->history = new InMemoryChatHistory('deferred-thread');
        $this->persistence = new InMemoryPersistence();
        $this->provider = new FakeAIProvider();
        CountingTool::reset();
    }

    /** @param ToolInterface[] $tools */
    protected function agent(array $tools = [], bool $parallel = false): Agent
    {
        $agent = Agent::make();
        $agent->setChatHistory($this->history);
        $agent->setPersistence($this->persistence);
        $agent->setAiProvider($this->provider);
        $agent->addTool($tools);
        $agent->parallelToolCalls($parallel);
        return $agent;
    }

    /** @return ToolCall[] */
    protected function completedCalls(): array
    {
        $result = $this->history->getMessages()[2];
        $this->assertInstanceOf(ToolResultMessage::class, $result);
        return $result->getToolCalls();
    }

    public function test_mixed_batch_survives_a_new_agent_without_the_original_tools(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage('Use both', [
                new ToolCall('browser', 'external', ['selector' => '#title'], deferred: true),
                new ToolCall('lookup', 'local', ['query' => 'PHP']),
            ]),
            new AssistantMessage('Done'),
        );
        $state = $this->agent([new DeferredTool('browser'), new CountingTool()])->chat(new UserMessage('Go'));
        $request = $state->getInterruptRequest();
        $this->assertInstanceOf(ToolResultsRequest::class, $request);
        $this->assertSame('tool_results', $request->getEventName());
        $this->assertSame('external', $request->getToolCalls()[0]->getCallId());
        $this->assertSame(['selector' => '#title'], $request->jsonSerialize()['toolCalls'][0]['inputs']);
        $this->assertSame(1, CountingTool::$executions);
        $this->assertCount(2, $this->history->getMessages());

        $state = $this->agent()->toolResults(['external' => ['result' => ['text' => 'Hello']]])->run();
        $this->assertFalse($state->isInterrupted());
        $this->assertSame('Done', $state->getMessage()->getContent());
        $this->assertSame(1, CountingTool::$executions);
        $this->assertSame(2, $this->provider->getCallCount());
        $this->assertCount(4, $this->history->getMessages());
        $calls = $this->completedCalls();
        $this->assertSame(['external', 'local'], array_map(fn (ToolCall $call): ?string => $call->getCallId(), $calls));
        $this->assertSame('{"text":"Hello"}', $calls[0]->getResult());
        $this->assertSame('Results for: PHP', $calls[1]->getResult());
    }

    public function test_partial_results_accumulate_and_identical_redelivery_is_harmless(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [new ToolCall('browser', 'a', deferred: true), new ToolCall('browser', 'b', deferred: true)]),
            new AssistantMessage('Done'),
        );
        $this->agent([new DeferredTool('browser')])->chat(new UserMessage('Go'));
        $state = $this->agent()->toolResults(['a' => ['result' => false]])->run();
        $request = $state->getInterruptRequest();
        $this->assertInstanceOf(ToolResultsRequest::class, $request);
        $this->assertCount(1, $request->getToolCalls());
        $this->assertSame('b', $request->getToolCalls()[0]->getCallId());

        $state = $this->agent()->toolResults(['a' => ['result' => false]])->run();
        $this->assertTrue($state->isInterrupted());
        $this->assertSame(1, $this->provider->getCallCount());
        $state = $this->agent()->toolResults(['b' => ['error' => 'User cancelled']])->run();
        $this->assertFalse($state->isInterrupted());
        $calls = $this->completedCalls();
        $this->assertSame('false', $calls[0]->getResult());
        $this->assertInstanceOf(ToolOutput::class, $calls[1]->getResult());
        $this->assertTrue($calls[1]->getResult()->isError());
        $this->assertSame('User cancelled', $calls[1]->getResult()->getText());
    }

    /** @param array<array-key, mixed> $payload */
    #[DataProvider('invalidResults')]
    public function test_invalid_results_do_not_poison_the_pending_run(array $payload): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [new ToolCall('browser', 'a', deferred: true), new ToolCall('browser', 'b', deferred: true)]),
            new AssistantMessage('Done'),
        );
        $this->agent([new DeferredTool('browser')])->chat(new UserMessage('Go'));
        $this->agent()->toolResults(['a' => ['result' => 'accepted']])->run();
        try {
            $this->agent()->toolResults($payload)->run();
            $this->fail('Invalid results must be rejected before acceptance.');
        } catch (WorkflowException) {
        }
        $this->assertFalse($this->agent()->toolResults(['b' => ['result' => null]])->run()->isInterrupted());
        $calls = $this->completedCalls();
        $this->assertSame('accepted', $calls[0]->getResult());
        $this->assertSame('null', $calls[1]->getResult());
    }

    public static function invalidResults(): array
    {
        return [
            'unknown call' => [['unknown' => ['result' => 'data']]],
            'malformed' => [['b' => 'data']],
            'ambiguous' => [['b' => ['result' => 'data', 'error' => 'failed']]],
            'invalid error' => [['b' => ['error' => null]]],
            'conflicting redelivery' => [['a' => ['result' => 'changed']]],
        ];
    }

    public function test_approval_then_execution_use_distinct_steps_and_rejections_are_not_dispatched(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [
                new ToolCall('browser', 'allowed', deferred: true),
                new ToolCall('browser', 'rejected', deferred: true),
                new ToolCall('lookup', 'local', ['query' => 'PHP']),
            ]),
            new AssistantMessage('Done'),
        );
        $tools = [(new DeferredTool('browser'))->requireApproval(), (new CountingTool())->requireApproval()];
        $state = $this->agent($tools)->chat(new UserMessage('Go'));
        $this->assertInstanceOf(ApprovalRequest::class, $state->getInterruptRequest());
        $this->assertSame(0, CountingTool::$executions);
        $state = $this->agent($tools)->toolApprovalDecisions([
            'allowed' => 'approve', 'rejected' => 'reject', 'local' => 'approve',
        ])->run();
        $request = $state->getInterruptRequest();
        $this->assertInstanceOf(ToolResultsRequest::class, $request);
        $this->assertCount(1, $request->getToolCalls());
        $this->assertSame('allowed', $request->getToolCalls()[0]->getCallId());
        $this->assertSame(1, CountingTool::$executions);
        $this->assertCount(2, $this->history->getMessages());

        $this->agent()->toolResults(['allowed' => ['result' => 'ok']])->run();
        $this->assertSame(1, CountingTool::$executions);
        $this->assertCount(4, $this->history->getMessages());
        $this->assertStringContainsString('user rejected', $this->completedCalls()[1]->getResult());
    }

    public function test_all_rejected_deferred_calls_skip_the_wait(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [new ToolCall('browser', 'a', deferred: true)]),
            new AssistantMessage('Done'),
        );
        $tools = [(new DeferredTool('browser'))->requireApproval()];
        $this->agent($tools)->chat(new UserMessage('Go'));
        $this->assertFalse($this->agent($tools)->toolApprovalDecisions(['a' => 'reject'])->run()->isInterrupted());
    }

    public function test_parallel_execution_excludes_deferred_calls_before_forking(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [
                new ToolCall('lookup', 'one', ['query' => 'one']),
                new ToolCall('browser', 'external', deferred: true),
                new ToolCall('lookup', 'two', ['query' => 'two']),
            ]),
            new AssistantMessage('Done'),
        );
        $state = $this->agent([new CountingTool(), new DeferredTool('browser')], true)->chat(new UserMessage('Go'));
        $this->assertInstanceOf(ToolResultsRequest::class, $state->getInterruptRequest());
        $this->agent([], true)->toolResults(['external' => ['result' => 'ok']])->run();
        $this->assertSame(['Results for: one', 'ok', 'Results for: two'], array_map(
            fn (ToolCall $call): string|ToolOutput => $call->getResult(), $this->completedCalls(),
        ));
    }

    public function test_stream_continuation_does_not_redispatch_calls(): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [new ToolCall('browser', 'external', deferred: true)]),
            new AssistantMessage('Done'),
        );
        $first = iterator_to_array($this->agent([new DeferredTool('browser')])->stream(new UserMessage('Go')));
        $this->assertCount(1, array_filter($first, fn (object $item): bool => $item instanceof ToolCallChunk));
        $stream = $this->agent()->toolResults(['external' => ['result' => 0]])->events();
        $second = iterator_to_array($stream);
        $this->assertFalse($stream->getReturn()->isInterrupted());
        $this->assertCount(0, array_filter($second, fn (object $item): bool => $item instanceof ToolCallChunk));
        $this->assertCount(1, array_filter($second, fn (object $item): bool => $item instanceof ToolResultChunk));
        $this->assertSame('stream', $this->provider->getRecorded()[1]->method);
    }

    public function test_run_limit_is_checked_before_dispatch(): void
    {
        $this->provider->addResponses(new ToolCallMessage(null, [new ToolCall('browser', 'a', deferred: true)]));
        $this->expectException(ToolRunsExceededException::class);
        $this->agent([new DeferredTool('browser')])->toolMaxRuns(0)->chat(new UserMessage('Go'));
    }

    #[DataProvider('dispatchLimitBatches')]
    public function test_handled_dispatch_limits_join_completed_results(bool $includePending): void
    {
        $calls = [
            new ToolCall('lookup', 'local', ['query' => 'PHP']),
            new ToolCall('limited', 'limited', deferred: true),
        ];
        if ($includePending) {
            $calls[] = new ToolCall('browser', 'pending', deferred: true);
        }
        $this->provider->addResponses(new ToolCallMessage(null, $calls), new AssistantMessage('Done'));
        $agent = $this->agent([
            new CountingTool(),
            (new DeferredTool('limited'))->setMaxRuns(0),
            new DeferredTool('browser'),
        ]);
        $agent->toolErrorHandler(fn (\Throwable $error): ToolOutput => ToolOutput::error($error->getMessage()));
        $state = $agent->chat(new UserMessage('Go'));
        $this->assertSame($includePending, $state->isInterrupted());
        if ($includePending) {
            $request = $state->getInterruptRequest();
            $this->assertInstanceOf(ToolResultsRequest::class, $request);
            $this->assertSame(['pending'], array_map(fn (ToolCall $call): ?string => $call->getCallId(), $request->getToolCalls()));
            $this->agent()->toolResults(['pending' => ['result' => 'ok']])->run();
        }
        $completed = $this->completedCalls();
        $this->assertSame('Results for: PHP', $completed[0]->getResult());
        $this->assertSame('limited', $completed[1]->getCallId());
        $this->assertTrue($completed[1]->isDeferred());
        $this->assertInstanceOf(ToolOutput::class, $completed[1]->getResult());
        $this->assertTrue($completed[1]->getResult()->isError());
        if ($includePending) {
            $this->assertSame('ok', $completed[2]->getResult());
        }
    }

    public static function dispatchLimitBatches(): array
    {
        return ['all settled' => [false], 'external result pending' => [true]];
    }

    public function test_abandon_refuses_unanswered_deferred_calls(): void
    {
        $this->provider->addResponses(new ToolCallMessage(null, [new ToolCall('browser', 'a', deferred: true)]));
        $this->agent([new DeferredTool('browser')])->chat(new UserMessage('Go'));
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('toolResults()');
        $this->agent()->abandonRun();
    }
}
