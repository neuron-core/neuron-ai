<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

use function serialize;
use function unserialize;

class AgentStateTest extends TestCase
{
    public function test_add_and_get_steps(): void
    {
        $state = new AgentState();

        $this->assertSame([], $state->getSteps());

        $state->addStep(new AssistantMessage('Step 1'));
        $state->addStep(new AssistantMessage('Step 2'));

        $steps = $state->getSteps();
        $this->assertCount(2, $steps);
        $this->assertSame('Step 1', $steps[0]->getContent());
        $this->assertSame('Step 2', $steps[1]->getContent());
    }

    public function test_reset_steps(): void
    {
        $state = new AgentState();
        $state->addStep(new AssistantMessage('Step 1'));
        $state->addStep(new AssistantMessage('Step 2'));

        $state->resetSteps();

        $this->assertSame([], $state->getSteps());
    }

    public function test_steps_are_excluded_from_serialization(): void
    {
        // The steps transcript is per-execution-cycle and duplicates messages
        // already persisted in the chat history — it must never reach the
        // durable step snapshots.
        $state = new AgentState();
        $state->set('some_key', 'some_value');
        $state->addStep(new AssistantMessage('Step 1'));

        /** @var AgentState $restored */
        $restored = unserialize(serialize($state));

        $this->assertSame([], $restored->getSteps());
        $this->assertSame('some_value', $restored->get('some_key'));
        $this->assertCount(1, $state->getSteps(), 'Serializing must not strip the live transcript');
    }

    public function test_a_rewritten_tool_call_step_replaces_the_recorded_one(): void
    {
        // A replayed approval write records the same calls again with updated
        // approval states: the transcript keeps one entry, the latest.
        $state = new AgentState();
        $pending = new ToolCallMessage(null, [ToolCall::make('delete', 'call_1'), ToolCall::make('delete', 'call_2')]);
        $state->addStep($pending);

        $settled = new ToolCallMessage(null, [
            ToolCall::make('delete', 'call_1')->setApprovalState(ApprovalState::Approved),
            ToolCall::make('delete', 'call_2')->setApprovalState(ApprovalState::Rejected),
        ]);
        $state->addStep($settled);

        $this->assertSame([$settled], $state->getSteps());
    }

    public function test_tool_call_steps_with_different_call_ids_are_both_recorded(): void
    {
        $state = new AgentState();
        $first = new ToolCallMessage(null, [ToolCall::make('search', 'call_1')]);
        $second = new ToolCallMessage(null, [ToolCall::make('search', 'call_2')]);

        $state->addStep($first);
        $state->addStep($second);

        $this->assertSame([$first, $second], $state->getSteps());
    }

    public function test_tool_call_steps_with_the_same_ids_in_another_order_are_both_recorded(): void
    {
        $state = new AgentState();
        $first = new ToolCallMessage(null, [ToolCall::make('a', 'call_1'), ToolCall::make('b', 'call_2')]);
        $reordered = new ToolCallMessage(null, [ToolCall::make('b', 'call_2'), ToolCall::make('a', 'call_1')]);

        $state->addStep($first);
        $state->addStep($reordered);

        $this->assertSame([$first, $reordered], $state->getSteps());
    }

    public function test_a_repeated_tool_call_after_its_result_starts_a_new_step(): void
    {
        // Only a direct rewrite of the last step is collapsed: a new cycle that
        // happens to reuse a call ID is a distinct step.
        $state = new AgentState();
        $call = new ToolCallMessage(null, [ToolCall::make('search', 'call_1')]);
        $result = new ToolResultMessage([ToolCall::make('search', 'call_1')->setResult('found')]);
        $again = new ToolCallMessage(null, [ToolCall::make('search', 'call_1')]);

        $state->addStep($call);
        $state->addStep($result);
        $state->addStep($again);

        $this->assertSame([$call, $result, $again], $state->getSteps());
    }

    #[TestWith(['calculator'])]
    #[TestWith(['read_file:offset=0'])]
    public function test_tool_runs_increment_per_run_key(string $runKey): void
    {
        $state = new AgentState();
        $this->assertSame(0, $state->getToolRuns($runKey));

        $state->incrementToolRun($runKey);
        $state->incrementToolRun($runKey);

        $this->assertSame(2, $state->getToolRuns($runKey));
    }

    public function test_different_run_keys_are_tracked_separately(): void
    {
        $state = new AgentState();
        $state->incrementToolRun('read_file:offset=0');
        $state->incrementToolRun('read_file:offset=0');
        $state->incrementToolRun('read_file:offset=100');

        $this->assertSame(2, $state->getToolRuns('read_file:offset=0'));
        $this->assertSame(1, $state->getToolRuns('read_file:offset=100'));
        $this->assertSame(0, $state->getToolRuns('read_file'));
    }

    public function test_reset_tool_runs_clears_every_run_key(): void
    {
        $state = new AgentState();
        $state->incrementToolRun('calculator');
        $state->incrementToolRun('read_file:offset=0');

        $state->resetToolRuns();

        $this->assertSame(0, $state->getToolRuns('calculator'));
        $this->assertSame(0, $state->getToolRuns('read_file:offset=0'));
        $this->assertFalse($state->has('__tool_runs'));
    }

    public function test_restoring_a_run_count_never_lowers_it(): void
    {
        // Recovery restores a recorded count onto a possibly newer state: a
        // stale record must not hand back slots that were already consumed.
        $state = new AgentState();
        $state->incrementToolRun('search');
        $state->incrementToolRun('search');
        $state->incrementToolRun('search');

        $state->restoreToolRunCount('search', 1);

        $this->assertSame(3, $state->getToolRuns('search'));
    }

    public function test_restoring_a_run_count_raises_an_older_snapshot(): void
    {
        $state = new AgentState();
        $state->incrementToolRun('search');

        $state->restoreToolRunCount('search', 4);
        $state->restoreToolRunCount('lookup', 2);

        $this->assertSame(4, $state->getToolRuns('search'));
        $this->assertSame(2, $state->getToolRuns('lookup'));
    }

    public function test_the_final_message_is_read_from_the_recorded_response(): void
    {
        $state = new AgentState();
        $this->assertNull($state->getResponse());
        $this->assertNull($state->getMessage(), 'No inference has produced a response yet');

        $message = new AssistantMessage('Final answer');
        $response = new ProviderResponse($message);

        $this->assertSame($state, $state->setResponse($response));
        $this->assertSame($response, $state->getResponse());
        $this->assertSame($message, $state->getMessage());
    }
}
