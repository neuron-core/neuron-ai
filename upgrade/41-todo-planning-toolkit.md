# Upgrade: To-do planning is a toolkit

## Summary

The `NeuronAI\Agent\Middleware\TodoPlanning` middleware is removed. To-do planning is the toolkit
`NeuronAI\Tools\Toolkits\TodoPlanning\TodoPlanningToolkit`: it provides the `write_todos` tool and contributes its
guidelines to the agent instructions, like every toolkit. `WriteTodosTool` moved to the same namespace and no longer
takes the agent state.

The list is no longer copied to the `__todos` state key. It lives in the conversation, as the input of the
`write_todos` calls the model makes, so it survives interruptions and later turns.

| Before (3.x) | After |
|---|---|
| `addMiddleware(ChatNode::class, new TodoPlanning())` | `addTool(TodoPlanningToolkit::make())`, or the toolkit returned from `tools()` |
| `new TodoPlanning($systemPrompt)` | `TodoPlanningToolkit::make($guidelines)` |
| `NeuronAI\Agent\Middleware\WriteTodosTool` | `NeuronAI\Tools\Toolkits\TodoPlanning\WriteTodosTool` |
| `$state->get('__todos')` | The `todos` input of the latest `write_todos` call in the chat history |

## How to Refactor

### Case 1: An agent with the middleware

Before:

```php
use NeuronAI\Agent\Middleware\TodoPlanning;

$agent = Agent::make()
    ->addTool([new CreateSchemaTool(), new RunTestsTool()])
    ->addMiddleware(ChatNode::class, new TodoPlanning());
```

After:

```php
use NeuronAI\Tools\Toolkits\TodoPlanning\TodoPlanningToolkit;

$agent = Agent::make()
    ->addTool([new CreateSchemaTool(), new RunTestsTool(), TodoPlanningToolkit::make()]);
```

An agent class adds the toolkit to the array its `tools()` hook returns.

### Case 2: A custom planning prompt

Pass it to the toolkit. It replaces the default guidelines.

Before:

```php
new TodoPlanning($planningPrompt);
```

After:

```php
TodoPlanningToolkit::make($planningPrompt);
```

### Case 3: Reading the list

Read the input of the latest `write_todos` call from the chat history instead of the state.

Before:

```php
$todos = $state->get('__todos');
```

After:

```php
use NeuronAI\Chat\Messages\ToolCallMessage;

$todos = null;
foreach ($agent->getChatHistory()->getMessages() as $message) {
    if ($message instanceof ToolCallMessage) {
        foreach ($message->getToolCalls() as $call) {
            $todos = $call->getName() === 'write_todos' ? $call->getInput('todos') : $todos;
        }
    }
}
```

## What to Search For

```
grep -rn "TodoPlanning\|WriteTodosTool\|__todos" --include="*.php" .
```

## Checklist

- No reference to `NeuronAI\Agent\Middleware\TodoPlanning` or `NeuronAI\Agent\Middleware\WriteTodosTool` remains.
- Agents that planned with the middleware register `TodoPlanningToolkit` as a tool.
- No code reads `__todos` from the state.
