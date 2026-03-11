<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware\Tools;

use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function in_array;
use function json_encode;

/**
 * Tool for agents to write and manage their todo list.
 *
 * This tool allows agents to create, update, and track tasks during
 * complex multi-step operations.
 *
 * @method static static make(string $name = 'write_todos')
 */
class WriteTodosTool extends Tool
{
    protected array $todos = [];

    public function __construct(string $name = 'write_todos')
    {
        parent::__construct(
            name: $name,
            description: <<<TODO
Use this tool to create and manage a structured task list for your current work session. This helps you track progress, organize complex tasks, and demonstrate thoroughness to the user.

Only use this tool if you think it will be helpful in staying organized. If the user's request is trivial and takes less than 3 steps, it is better to NOT use this tool and just do the task directly.

## When to Use This Tool
Use this tool in these scenarios:

1. Complex multi-step tasks - When a task requires 3 or more distinct steps or actions
2. Non-trivial and complex tasks - Tasks that require careful planning or multiple operations
3. User explicitly requests todo list - When the user directly asks you to use the todo list
4. User provides multiple tasks - When users provide a list of things to be done (numbered or comma-separated)
5. The plan may need future revisions or updates based on results from the first few steps

## How to Use This Tool
1. When you start working on a task - Mark it as in_progress BEFORE beginning work.
2. After completing a task - Mark it as completed and add any new follow-up tasks discovered during implementation.
3. You can also update future tasks, such as deleting them if they are no longer necessary, or adding new tasks that are necessary. Don't change previously completed tasks.
4. You can make several updates to the todo list at once. For example, when you complete a task, you can mark the next task you need to start as in_progress.

## When NOT to Use This Tool
It is important to skip using this tool when:
1. There is only a single, straightforward task
2. The task is trivial and tracking it provides no benefit
3. The task can be completed in less than 3 trivial steps
4. The task is purely conversational or informational

## Task States and Management

1. **Task States**: Use these states to track progress:
   - pending: Task not yet started
   - in_progress: Currently working on (you can have multiple tasks in_progress at a time if they are not related to each other and can be run in parallel)
   - completed: Task finished successfully

2. **Task Management**:
   - Update task status in real-time as you work
   - Mark tasks complete IMMEDIATELY after finishing (don't batch completions)
   - Complete current tasks before starting new ones
   - Remove tasks that are no longer relevant from the list entirely
   - IMPORTANT: When you write this todo list, you should mark your first task (or tasks) as in_progress immediately!.
   - IMPORTANT: Unless all tasks are completed, you should always have at least one task in_progress to show the user that you are working on something.

3. **Task Completion Requirements**:
   - ONLY mark a task as completed when you have FULLY accomplished it
   - If you encounter errors, blockers, or cannot finish, keep the task as in_progress
   - When blocked, create a new task describing what needs to be resolved
   - Never mark a task as completed if:
     - There are unresolved issues or errors
     - Work is partial or incomplete
     - You encountered blockers that prevent completion
     - You couldn't find necessary resources or dependencies
     - Quality standards haven't been met

4. **Task Breakdown**:
   - Create specific, actionable items
   - Break complex tasks into smaller, manageable steps
   - Use clear, descriptive task names

Being proactive with task management demonstrates attentiveness and ensures you complete all requirements successfully
Remember: If you only need to make a few tool calls to complete a task, and it is clear what you need to do, it is better to just do the task directly and NOT call this tool at all.
TODO
        );
    }

    protected function properties(): array
    {
        return [
            ArrayProperty::make(
                name: 'todos',
                description: 'Array of todo items. Each item must have "content" (task description) and "status" (one of: pending, in_progress, completed)',
                required: true,
                items: ObjectProperty::make(
                    name: 'item',
                    description: 'Item in the todo list',
                    required: true,
                    properties: [
                        ToolProperty::make(
                            name: 'content',
                            type: PropertyType::STRING,
                            description: 'Task description'
                        ),
                        ToolProperty::make(
                            name: 'status',
                            type: PropertyType::STRING,
                            description: 'Current status of the task',
                            required: true,
                            enum: ['pending', 'in_progress', 'completed']
                        ),
                    ]
                )
            ),
        ];
    }

    /**
     * Update the agent's todo list.
     */
    public function __invoke(array $todos): string
    {
        // Validate todos structure
        foreach ($todos as $index => $todo) {
            if (!isset($todo['content'], $todo['status'])) {
                return "Error: Todo at index {$index} must have 'content' and 'status' fields.";
            }

            if (!in_array($todo['status'], ['pending', 'in_progress', 'completed'], true)) {
                return "Error: Todo at index {$index} has invalid status '{$todo['status']}'. Must be one of: pending, in_progress, completed.";
            }
        }

        $this->todos = $todos;

        return "Updated to do list to: " . json_encode($todos);
    }
}
