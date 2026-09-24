<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;

use function array_slice;
use function count;
use function is_string;
use function max;

/**
 * Lets the model find tools in a pool through the tool_search tool. Register it
 * globally: it runs before every agent node of every execution segment, so the
 * tools found during the current turn are registered again after a pause. The
 * next user message starts a turn without them.
 */
class ToolSearchMiddleware extends AgentMiddleware
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

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
    {
        $resources->tools->add(new ToolSearchTool($this->toolPool, $this->topN));

        if (!isset($state->request)) {
            return;
        }

        if ($event instanceof AIInferenceEvent && !$state->request->instructions->contains($this->systemPrompt)) {
            $state->request->instructions->addContent(new SystemContent($this->systemPrompt));
        }

        $conversation = [...$resources->history->getMessages(), ...$state->request->messages];
        foreach ($this->discoverFromMessages($this->currentTurn($conversation)) as $tool) {
            $resources->tools->add($tool);
        }
    }

    /**
     * The messages after the last user message.
     *
     * @param Message[] $messages
     * @return Message[]
     */
    protected function currentTurn(array $messages): array
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if ($messages[$index] instanceof UserMessage && !$messages[$index] instanceof ToolResultMessage) {
                return array_slice($messages, $index + 1);
            }
        }

        return $messages;
    }

    /**
     * Re-derive discovered tools from recorded tool_search calls: the search is
     * deterministic for a given pool, and message entries carry no side-channel
     * objects.
     *
     * @param Message[] $messages
     * @return ToolInterface[]
     */
    protected function discoverFromMessages(array $messages): array
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
}
