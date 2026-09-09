<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

use function in_array;
use function is_string;
use function max;

class ToolSearchMiddleware implements WorkflowMiddleware
{
    protected const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
        ---

        ## `tool_search`

        You have access to the `tool_search` tool to discover additional tools that may help you complete your task.
        When you need a capability that your current tools do not provide, use `tool_search` to find relevant tools from the available pool.

        After searching, matching tools become available for you to use in subsequent steps.
        Always search before concluding that a task cannot be completed — the right tool may exist but not be loaded yet.
        PROMPT;

    /**
     * @param ToolInterface[] $toolPool
     * @param int<1,max> $topN Maximum number of matching tools the search returns
     */
    public function __construct(
        protected array $toolPool,
        protected int $topN = 5,
        protected string $systemPrompt = self::DEFAULT_SYSTEM_PROMPT,
    ) {
        $this->topN = max(1, $this->topN);
    }

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if ($state instanceof AgentState && $event instanceof ToolCallEvent) {
            $this->resupplyExecutionTools($node, $state);
            return;
        }

        if (!$state instanceof AgentState || !$event instanceof AIInferenceEvent) {
            return;
        }

        if (!$state->request->instructions->contains($this->systemPrompt)) {
            $state->request->instructions->addContent(new SystemContent($this->systemPrompt));
        }

        if (!$this->hasToolSearchTool($state->request->tools)) {
            $state->request->tools[] = new ToolSearchTool($this->toolPool, $this->topN);
        }
    }

    /**
     * Re-establish this middleware's contribution on the state request's tool
     * list after Agent::restoreState() restores the base registry. Each
     * middleware re-supplies what it added — here, the search tool itself plus
     * every tool discovered earlier in the conversation, re-derived from chat
     * history (deterministic for a given pool). On the live path everything is
     * already present, making this a no-op.
     */
    protected function resupplyExecutionTools(NodeInterface $node, AgentState $state): void
    {
        $request = $state->request;
        $existingNames = $this->getToolNames($request->tools);

        if (!$this->hasToolSearchTool($request->tools)) {
            $request->tools[] = new ToolSearchTool($this->toolPool, $this->topN);
        }

        if (!$node instanceof AgentNodeInterface) {
            return;
        }

        foreach ($this->discoverFromMessages($node->getChatHistory()->getMessages()) as $tool) {
            if (!in_array($tool->getName(), $existingNames, true)) {
                $request->tools[] = $tool;
                $existingNames[] = $tool->getName();
            }
        }
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        if (!$state instanceof AgentState || !$result instanceof AIInferenceEvent) {
            return;
        }

        $discovered = $this->discoverFromMessages($state->request->messages);

        if ($discovered === []) {
            return;
        }

        $existingNames = $this->getToolNames($state->request->tools);

        foreach ($discovered as $tool) {
            if (!in_array($tool->getName(), $existingNames, true)) {
                $state->request->tools[] = $tool;
                $existingNames[] = $tool->getName();
            }
        }
    }

    /**
     * Re-derive discovered tools from recorded tool_search calls: the search is
     * deterministic for a given pool, and message entries carry no side-channel
     * objects.
     *
     * @param iterable<mixed> $messages
     * @return ToolInterface[]
     */
    protected function discoverFromMessages(iterable $messages): array
    {
        $finder = null;
        $discovered = [];

        foreach ($messages as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->getToolCalls() as $call) {
                $finder ??= new ToolSearchTool($this->toolPool, $this->topN);

                if ($call->getName() !== $finder->getName()) {
                    continue;
                }

                $query = $call->getInput('query');
                if (is_string($query)) {
                    $discovered = [...$discovered, ...$finder->search($query)];
                }
            }
        }

        return $discovered;
    }

    /**
     * @param ToolInterface[] $tools
     * @return string[]
     */
    protected function getToolNames(array $tools): array
    {
        $names = [];
        foreach ($tools as $tool) {
            $names[] = $tool->getName();
        }
        return $names;
    }

    /**
     * @param ToolInterface[] $tools
     */
    protected function hasToolSearchTool(array $tools): bool
    {
        foreach ($tools as $tool) {
            if ($tool instanceof ToolSearchTool) {
                return true;
            }
        }
        return false;
    }
}
