<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Workflow\NodeContext;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\Executor\LocalStepEngine;
use NeuronAI\Workflow\Executor\StepMemoizer;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

class MultimodalTool extends Tool
{
    public static int $executions = 0;

    protected string $name = 'multimodal_tool';

    protected ?string $description = 'Returns a multimodal result';

    public function __invoke(): ToolOutput
    {
        self::$executions++;

        return new ToolOutput([
            new TextContent('the chart'),
            new ImageContent('base64data', SourceType::BASE64, 'image/png'),
        ]);
    }
}

class MultimodalToolResultReplayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MultimodalTool::$executions = 0;
    }

    public function test_tool_output_survives_execution(): void
    {
        $tool = new MultimodalTool();
        $tool->setCallId('call_1');
        $tool->setInputs([]);

        $toolNode = new ToolNode(new InMemoryChatHistory());
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext(new NodeContext($state, $event));

        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        $result = $tool->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertCount(2, $result->getBlocks());
        $this->assertSame('the chart', $result->getText());
    }

    public function test_tool_output_restored_on_replay_without_re_execution(): void
    {
        // The memoized tool execution must record the full ToolOutput, not a text
        // projection: on crash recovery the replay restores the multimodal result
        // onto a fresh tool instance without re-running __invoke().
        $runId = 'multimodal_replay_test';
        $persistence = new InMemoryPersistence();
        $stepId = ToolNode::class . '-0';

        $tool1 = new MultimodalTool();
        $tool1->setCallId('call_1');
        $tool1->setInputs([]);

        $state = new AgentState();
        $state->set('__runId', $runId);

        // Run 1: the tool executes and its result is memoized mid-node.
        $toolCallMessage1 = new ToolCallMessage(null, [$tool1]);
        $inferenceEvent1 = new AIInferenceEvent(instructions: 'Test', tools: [$tool1]);
        $event1 = new ToolCallEvent($toolCallMessage1, $inferenceEvent1);
        $engine1 = new LocalStepEngine($persistence);
        $engine1->prepareExecution($runId);
        $node1 = new ToolNode(new InMemoryChatHistory());
        $node1->setWorkflowContext(new NodeContext($state, $event1, null, false, new StepMemoizer($engine1, $stepId)));
        foreach ($node1($event1, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        $this->assertSame(1, MultimodalTool::$executions);

        // Recovery: brand-new engine and fresh tool instance, same persistence
        // (simulates a process restart replaying the node).
        $tool2 = new MultimodalTool();
        $tool2->setCallId('call_1');
        $tool2->setInputs([]);

        $toolCallMessage2 = new ToolCallMessage(null, [$tool2]);
        $inferenceEvent2 = new AIInferenceEvent(instructions: 'Test', tools: [$tool2]);
        $event2 = new ToolCallEvent($toolCallMessage2, $inferenceEvent2);
        $engine2 = new LocalStepEngine($persistence);
        $engine2->prepareExecution($runId);
        $node2 = new ToolNode(new InMemoryChatHistory());
        $node2->setWorkflowContext(new NodeContext($state, $event2, null, false, new StepMemoizer($engine2, $stepId)));
        foreach ($node2($event2, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        $this->assertSame(1, MultimodalTool::$executions, 'Tool must not re-execute on replay');

        $result = $tool2->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertCount(2, $result->getBlocks());
        $this->assertSame('the chart', $result->getText());
        $this->assertInstanceOf(ImageContent::class, $result->getBlocks()[1]);
    }
}
