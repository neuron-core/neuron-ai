<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * Tool for agents to write and manage their todo list.
 *
 * This tool allows agents to create, update, and track tasks during
 * complex multi-step operations.
 */
class WriteTodosTool extends Tool
{
    public function __construct(string $name = 'write_todos')
    {
        parent::__construct(
            name: $name,
            description: 'Update the todo list with current task status. Use this to track progress on complex multi-step operations.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'todos',
                type: PropertyType::ARRAY,
                description: 'Array of todo items. Each item must have "content" (task description) and "status" (one of: pending, in_progress, completed)',
                required: true,
            ),
        ];
    }

    /**
     * Update the agent's todo list.
     *
     * @param array $todos Array of todo items with content and status
     * @return string Confirmation message
     */
    public function __invoke(array $todos): string
    {
        // Validate todo structure
        foreach ($todos as $index => $todo) {
            if (!isset($todo['content'], $todo['status'])) {
                return "Error: Todo at index {$index} must have 'content' and 'status' fields.";
            }

            if (!\in_array($todo['status'], ['pending', 'in_progress', 'completed'], true)) {
                return "Error: Todo at index {$index} has invalid status '{$todo['status']}'. Must be one of: pending, in_progress, completed.";
            }
        }

        // Count todos by status
        $pending = 0;
        $inProgress = 0;
        $completed = 0;

        foreach ($todos as $todo) {
            match ($todo['status']) {
                'pending' => $pending++,
                'in_progress' => $inProgress++,
                'completed' => $completed++,
                default => null,
            };
        }

        $total = \count($todos);

        return "Todo list updated: {$total} total tasks ({$completed} completed, {$inProgress} in progress, {$pending} pending)";
    }
}
