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
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

class RejectedToolExecutionTest extends TestCase
{
    private function sideEffectTool(string $name, bool &$executed): Tool
    {
        $tool = new class ($executed) extends Tool {
            public function __construct(public bool &$executedFlag)
            {
            }

            public function __invoke(): string
            {
                $this->executedFlag = true;
                return 'real result';
            }
        };

        $tool->setName($name)->setDescription('A tool with side effects');

        return $tool;
    }

    public function test_rejected_tool_is_not_executed(): void
    {
        $executed = false;
        $tool = $this->sideEffectTool('side_effect_tool', $executed);

        // The approval flow stamps this on the call before execution (ADR 0009/0010).
        $call = ToolCall::make('side_effect_tool', 'call_1', []);
        $call->setApprovalState(ApprovalState::Rejected);
        $call->setResult('TOOL NOT EXECUTED. The user rejected this action.');

        $toolNode = new ToolNode(new InMemoryChatHistory());
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$call]);
        $inferenceEvent = new AIInferenceEvent('test', [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext(new NodeContext($state, $event));

        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // consume the generator
        }

        $this->assertFalse($executed, 'A rejected tool must not run its logic');
        $this->assertStringContainsString('TOOL NOT EXECUTED', $call->getResult());
    }

    public function test_approved_tool_is_executed(): void
    {
        $executed = false;
        $tool = $this->sideEffectTool('approved_tool', $executed);

        $call = ToolCall::make('approved_tool', 'call_2', []);
        $call->setApprovalState(ApprovalState::Approved);

        $toolNode = new ToolNode(new InMemoryChatHistory());
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, [$call]);
        $inferenceEvent = new AIInferenceEvent('test', [$tool]);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $toolNode->setWorkflowContext(new NodeContext($state, $event));

        foreach ($toolNode($event, $state) as $_) {
            $_ = null;
        }

        $this->assertTrue($executed, 'An approved tool must run');
        $this->assertSame('real result', $call->getResult());
    }
}
