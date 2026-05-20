<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

use function in_array;

class ToolSearchMiddleware implements WorkflowMiddleware
{
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
    ) {
        $this->searchCallback = $searchCallback;
    }

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if (!$event instanceof AIInferenceEvent) {
            return;
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
