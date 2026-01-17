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
# Todo Planning Capabilities

You have access to todo planning functionality to help organize complex tasks.

## When to Use Todos

Use todo planning for:
- Complex multi-step tasks requiring 3 or more distinct steps
- Non-trivial operations requiring careful planning
- Tasks that benefit from visible progress tracking

DO NOT use todos for:
- Single, straightforward operations
- Trivial tasks that can be completed in 1-2 simple steps

## Todo Management

Each todo has:
- `content`: Task description (what needs to be done)
- `status`: One of "pending", "in_progress", or "completed"

## Best Practices

1. **Mark tasks as in_progress immediately** before starting work
2. **Complete tasks atomically** - mark as completed right after finishing, don't batch
3. **Be specific** - clear, actionable task descriptions
4. **Break down complexity** - decompose large tasks into smaller steps
5. **Update frequently** - keep the todo list synchronized with your actual progress

## Usage Pattern

When you start a complex task:
1. Call write_todos with your initial task breakdown
2. Mark the first task as "in_progress" before starting
3. Complete the task, then immediately mark it "completed"
4. Move to the next task and repeat

Example todo list for a complex feature implementation:
```json
[
    {"content": "Design database schema", "status": "completed"},
    {"content": "Implement data access layer", "status": "in_progress"},
    {"content": "Create API endpoints", "status": "pending"},
    {"content": "Add validation logic", "status": "pending"},
    {"content": "Write integration tests", "status": "pending"}
]
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
