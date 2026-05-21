<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\HandleContent;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

use function in_array;
use function is_array;

class ToolSearchMiddleware implements WorkflowMiddleware
{
    use HandleContent;

    protected const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
        ---

        ## `tool_search`

        You have access to the `tool_search` tool to discover additional tools that may help you complete your task.
        When you need a capability that your current tools do not provide, use `tool_search` to find relevant tools from the available pool.

        After searching, matching tools become available for you to use in subsequent steps.
        Always search before concluding that a task cannot be completed — the right tool may exist but not be loaded yet.
        PROMPT;

    /**
     * @var callable(string, ToolInterface): bool|null
     */
    protected $searchCallback;

    /**
     * @param ToolInterface[] $toolPool
     * @param callable(string, ToolInterface): bool|null $searchCallback
     */
    public function __construct(
        protected array $toolPool,
        ?callable $searchCallback = null,
        protected string $systemPrompt = self::DEFAULT_SYSTEM_PROMPT,
    ) {
        $this->searchCallback = $searchCallback;
    }

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if (!$event instanceof AIInferenceEvent) {
            return;
        }

        if (!$this->instructionsContainPrompt($event->instructions, $this->systemPrompt)) {
            if (is_array($event->instructions)) {
                $event->instructions[] = new SystemContent($this->systemPrompt);
            } else {
                $event->instructions .= "\n\n" . $this->systemPrompt;
            }
        }

        if (!$this->hasToolSearchTool($event->tools)) {
            $event->tools[] = new ToolSearchTool($this->toolPool, $this->searchCallback);
        }
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        if (!$result instanceof AIInferenceEvent) {
            return;
        }

        $discovered = $this->extractDiscoveredTools($result);

        if ($discovered === []) {
            return;
        }

        $existingNames = $this->getToolNames($result->tools);

        foreach ($discovered as $tool) {
            if (!in_array($tool->getName(), $existingNames, true)) {
                $result->tools[] = $tool;
                $existingNames[] = $tool->getName();
            }
        }
    }

    /**
     * @return ToolInterface[]
     */
    protected function extractDiscoveredTools(AIInferenceEvent $event): array
    {
        $discovered = [];

        foreach ($event->getMessages() as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->getTools() as $tool) {
                if ($tool instanceof ToolSearchTool) {
                    $discovered = [...$discovered, ...$tool->discoveredTools()];
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
