<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware\Stub;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Agent\Nodes\StartNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

class RequestEditingMiddleware implements WorkflowMiddleware
{
    public int $entryCalls = 0;

    /** @var list<list<string>> */
    public array $toolSelections = [];

    public function __construct(
        protected ToolInterface $tool,
        protected string $instructions = 'Middleware instructions',
    ) {
    }

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if (!$state instanceof AgentState || !($node instanceof InferenceNode || $node instanceof ToolNode)) {
            return;
        }

        $this->toolSelections[] = array_map(
            static fn (ToolInterface $tool): string => $tool->getName(),
            $state->request->tools,
        );
        $state->request->tools = [$this->tool];
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        if (!$state instanceof AgentState || !$node instanceof StartNode) {
            return;
        }

        $this->entryCalls++;
        $state->request->instructions = new SystemMessage($this->instructions);
        $state->request->messages = [new UserMessage('Middleware question')];
        $state->request->options->maxRetries = 0;
        $state->request->options->rememberMemory = false;
    }
}
