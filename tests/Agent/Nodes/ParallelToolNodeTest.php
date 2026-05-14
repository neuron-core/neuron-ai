<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Tests\Agent\Tools\TestCallable;
use NeuronAI\Tests\Agent\Tools\TestParameterizedTool;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class ParallelToolNodeTest extends TestCase
{
    public function test_parameterized_tools_tracked_by_run_key_in_parallel(): void
    {
        $tools = [
            new TestParameterizedTool('parallel_tool', 'id=1'),
            new TestParameterizedTool('parallel_tool', 'id=2'),
        ];

        $tools[0]->setCallId('call_1');
        $tools[1]->setCallId('call_2');

        $toolNode = new ParallelToolNode(maxRuns: 1);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, $tools);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: $tools);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);

        $toolNode->setWorkflowContext($state, $event);

        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        // Each unique parameter combination tracked separately
        $this->assertSame(1, $state->getToolRuns('parallel_tool:id=1'));
        $this->assertSame(1, $state->getToolRuns('parallel_tool:id=2'));
        $this->assertSame(0, $state->getToolRuns('parallel_tool'));
    }

    public function test_regular_tools_tracked_by_name_in_parallel(): void
    {
        $tool1 = Tool::make('regular_tool', 'A regular tool')
            ->setCallable(new TestCallable());
        $tool1->setCallId('call_1');
        $tool1->setInputs([]);

        $tool2 = Tool::make('another_tool', 'Another tool')
            ->setCallable(new TestCallable());
        $tool2->setCallId('call_2');
        $tool2->setInputs([]);

        $toolNode = new ParallelToolNode(maxRuns: 1);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool1, $tool2]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool1, $tool2]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);

        $toolNode->setWorkflowContext($state, $event);

        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        $this->assertSame(1, $state->getToolRuns('regular_tool'));
        $this->assertSame(1, $state->getToolRuns('another_tool'));
    }

    public function test_max_runs_enforced_per_run_key_in_parallel(): void
    {
        $tools = [
            new TestParameterizedTool('bounded_tool', 'id=1'),
            new TestParameterizedTool('bounded_tool', 'id=1'),
        ];

        $tools[0]->setCallId('call_1');
        $tools[1]->setCallId('call_2');

        $toolNode = new ParallelToolNode(maxRuns: 1);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, $tools);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: $tools);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);

        $toolNode->setWorkflowContext($state, $event);

        $this->expectException(ToolRunsExceededException::class);
        $this->expectExceptionMessage('Tool bounded_tool has been attempted too many times: 1 attempts.');

        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }
    }
}
