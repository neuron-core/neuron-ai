<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\Tools\WriteTodosTool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

class TodoPlanning implements WorkflowMiddleware
{
    private const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
---

## `write_todos`

You have access to the `write_todos` tool to help you manage and plan complex objectives.
Use this tool for complex objectives to ensure that you are tracking each necessary step and giving the user visibility into your progress.
This tool is very helpful for planning complex objectives, and for breaking down these larger complex objectives into smaller steps.

It is critical that you mark todos as completed as soon as you are done with a step. Do not batch up multiple steps before marking them as completed.
For simple objectives that only require a few steps, it is better to just complete the objective directly and NOT use this tool.
Writing todos takes time and tokens, use it when it is helpful for managing complex many-step problems! But not for simple few-step requests.

## Important To-Do List Usage Notes to Remember
- The `write_todos` tool should never be called multiple times in parallel.
- Don't be afraid to revise the To-Do list as you go. New information may reveal new tasks that need to be done, or old tasks that are irrelevant.
```
PROMPT;

    /**
     * @param string $systemPrompt Custom system prompt for to-do planning guidance
     * @param string $toolName Name of the todos tool (default: 'write_todos')
     */
    public function __construct(
        private readonly string $systemPrompt = self::DEFAULT_SYSTEM_PROMPT,
        private readonly string $toolName = 'write_todos',
    ) {
    }

    /**
     * Inject to-do planning instructions and tool before inference.
     */
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        // Only modify AIInferenceEvent
        if (!$event instanceof AIInferenceEvent) {
            return;
        }

        // Inject to-do planning instructions
        $event->instructions .= "\n\n" . $this->systemPrompt;

        // Add WriteTodosTool if not already present (avoid duplicates during tool loops)
        if (!$this->hasWriteTodosTool($event->tools)) {
            $event->tools[] = new WriteTodosTool($this->toolName);
        }
    }

    /**
     * After inference - can be used for observability/logging.
     */
    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        //
    }

    /**
     * Check if WriteTodosTool is already in the tool array.
     *
     * @param ToolInterface[] $tools
     */
    private function hasWriteTodosTool(array $tools): bool
    {
        foreach ($tools as $tool) {
            if ($tool instanceof WriteTodosTool) {
                return true;
            }
        }
        return false;
    }
}
