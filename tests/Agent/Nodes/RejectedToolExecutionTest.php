<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Workflow\NodeContext;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class RejectedToolExecutionTest extends TestCase
{
    public function test_rejected_tool_is_not_executed(): void
    {
        $executed = false;

        $tool = new class ($executed) extends Tool {
            public function __construct(public bool &$executedFlag)
            {
                $this->name = 'side_effect_tool';
                $this->description = 'A tool with side effects';
            }

            public function __invoke(): string
            {
                $this->executedFlag = true;
                return 'real result';
            }
        };

        // The ToolApproval middleware would do this before the node runs.
        $tool->setCallId('call_1');
        $tool->setInputs([]);
        $tool->setApprovalState(ApprovalState::Rejected);
        $tool->setResult('TOOL NOT EXECUTED. The user rejected this action.');

        $toolNode = new ToolNode(new InMemoryChatHistory());
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent('test', []);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext(new NodeContext($state, $event));

        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // consume the generator
        }

        $this->assertFalse($executed, 'A rejected tool must not run its logic');
        $this->assertStringContainsString('TOOL NOT EXECUTED', $tool->getResult());
    }

    public function test_approved_tool_is_executed(): void
    {
        $executed = false;

        $tool = new class ($executed) extends Tool {
            public function __construct(public bool &$executedFlag)
            {
                $this->name = 'approved_tool';
                $this->description = 'An approved tool';
            }

            public function __invoke(): string
            {
                $this->executedFlag = true;
                return 'real result';
            }
        };

        $tool->setCallId('call_2');
        $tool->setInputs([]);
        $tool->setApprovalState(ApprovalState::Approved);

        $toolNode = new ToolNode(new InMemoryChatHistory());
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent('test', []);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext(new NodeContext($state, $event));

        foreach ($toolNode($event, $state) as $_) {
            $_ = null;
        }

        $this->assertTrue($executed, 'An approved tool must run');
        $this->assertSame('real result', $tool->getResult());
    }
}
