# Upgrade: To-do planning is a toolkit

## Summary

The `NeuronAI\Agent\Middleware\TodoPlanning` middleware and the state-bound `NeuronAI\Agent\Middleware\WriteTodosTool` are removed. To-do planning is now the toolkit `NeuronAI\Tools\Toolkits\TodoPlanning\TodoPlanningToolkit`, registered with the agent's tools. It provides the `write_todos` tool and adds its guidelines to the agent instructions. The tool name `write_todos` and its `todos` input (a list of `{content, status}` items) are unchanged.

The list is no longer stored in the state. Read it from the latest `write_todos` call in the conversation (Case 4).

| 3.x | 4.x |
|---|---|
| `addMiddleware(ChatNode::class, new TodoPlanning())`, `addGlobalMiddleware(new TodoPlanning())`, an entry in `middleware()` / `globalMiddleware()` | `addTool(TodoPlanningToolkit::make())`, or `TodoPlanningToolkit::make()` in `tools()` |
| `new TodoPlanning($systemPrompt)` | `TodoPlanningToolkit::make($guidelines)` |
| `class X extends TodoPlanning` | `class X extends TodoPlanningToolkit` |
| `new \NeuronAI\Agent\Middleware\WriteTodosTool($state)` | `\NeuronAI\Tools\Toolkits\TodoPlanning\WriteTodosTool::make()` (no arguments) |
| `$state->get('__todos')` | the `todos` input of the latest `write_todos` call in the chat history |

The toolkit is offered in every run of the agent: `chat()`, `stream()` and `structured()`. In 3.x, a registration on `ChatNode::class` alone did not reach `stream()` (`StreamingNode`) or `structured()` (`StructuredOutputNode`). Case 1, step 4 says when to ask the developer.

Stored data: `__todos` lived only in the workflow state, which 3.x persisted only for a paused run. 4.x does not read those runs (guide 14), so there is nothing to migrate. Conversations stored with 3.x keep their `write_todos` calls with the same name and `todos` input, and 4.x reads them as they are. Case 4 therefore also finds lists written before the upgrade. Guide 30 covers the chat history storage itself.

## What to Search For

```bash
grep -rnE 'TodoPlanning|WriteTodosTool|__todos|write_todos' --include='*.php' --exclude-dir=vendor .
```

Follow each hit:

- `TodoPlanning`: find where the instance is registered: `addMiddleware()` / `addGlobalMiddleware()` calls (also through a variable that holds the instance) and entries in the `middleware()` / `globalMiddleware()` hooks. Also find classes that extend it (Cases 1 and 2).
- `WriteTodosTool`: `new WriteTodosTool(...)` and classes that extend it (Case 3).
- `__todos`: every `get()`, `set()` or `delete()` of that key on a state (Case 4).
- `write_todos`: instructions text that mentions the tool (Case 2). Code that matches the tool name by string stays valid.

If nothing is found, this guide does not apply.

After refactoring, this must return nothing:

```bash
grep -rnE 'Agent.Middleware.[^;]*(TodoPlanning|WriteTodosTool)([^A-Za-z0-9_]|$)|(^|[^A-Za-z0-9_\\])TodoPlanning([[:space:];,(){}:]|$)|__todos' --include='*.php' --exclude-dir=vendor .
```

## How to Refactor

### Case 1: The middleware is registered on an agent

1. Delete every `new TodoPlanning(...)` registration, wherever it is:
   - `addMiddleware()` on any node (`ChatNode::class`, `StreamingNode::class`, `StructuredOutputNode::class`) or on an array of nodes;
   - `addGlobalMiddleware()`;
   - an entry in the `middleware()` or `globalMiddleware()` hook.

   When `TodoPlanning` sits in an array with other middleware, remove only that element. Delete a hook method that ends up returning an empty array.
2. Add `TodoPlanningToolkit::make()` to that agent's tools, exactly once even if 3.x registered the middleware on several nodes. Use `addTool()` where the agent is configured on an instance, and the `tools()` hook in an agent class.
3. Remove imports that became unused: `NeuronAI\Agent\Middleware\TodoPlanning`, and `NeuronAI\Agent\Nodes\ChatNode`, `StreamingNode` or `StructuredOutputNode` when nothing else in the file uses them.
4. Ask the developer when both of these hold: the 3.x registration covered only some of `ChatNode`, `StreamingNode` and `StructuredOutputNode` (a global registration covered all of them), and the app also calls the other verbs on that agent. Ask: "Should the `stream()` / `structured()` runs of this agent plan with `write_todos` too?" If the answer is no, add the toolkit with `addTool()` only on the instances whose runs should plan, not in `tools()`.

Before (3.x):

```php
use NeuronAI\Agent\Middleware\TodoPlanning;
use NeuronAI\Agent\Nodes\ChatNode;

$agent->addMiddleware(ChatNode::class, new TodoPlanning());
```

After (4.x):

```php
use NeuronAI\Tools\Toolkits\TodoPlanning\TodoPlanningToolkit;

$agent->addTool(TodoPlanningToolkit::make());
```

In a fluent chain, replace the `->addMiddleware(...)` link with `->addTool(TodoPlanningToolkit::make())`.

Before (3.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\TodoPlanning;
use NeuronAI\Agent\Nodes\ChatNode;

class PlannerAgent extends Agent
{
    protected function tools(): array
    {
        return [new CreateSchemaTool(), new RunTestsTool()];
    }

    protected function middleware(): array
    {
        return [
            ChatNode::class => [new TodoPlanning()],
        ];
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Tools\Toolkits\TodoPlanning\TodoPlanningToolkit;

class PlannerAgent extends Agent
{
    protected function tools(): array
    {
        return [new CreateSchemaTool(), new RunTestsTool(), TodoPlanningToolkit::make()];
    }
}
```

Do not also register a `WriteTodosTool`. The toolkit already provides it, and a second `write_todos` fails the run (guide 11). If the 3.x `ToolApproval` middleware gated `write_todos`, guide 28 covers that.

### Case 2: A custom planning prompt or a TodoPlanning subclass

The prompt becomes the toolkit's guidelines and replaces the default ones. The constructor parameter is now `$guidelines` (3.x `$systemPrompt`), so rename it in a named argument.

Before (3.x):

```php
$agent->addMiddleware(ChatNode::class, new TodoPlanning($planningPrompt));
```

After (4.x):

```php
$agent->addTool(TodoPlanningToolkit::make($planningPrompt));
```

- 4.x sends the guidelines under its own `# write_todos` heading inside `<TOOLS-GUIDELINES>`. If the text was copied from the 3.x default prompt, delete its leading `---` line and its ``## `write_todos` `` heading.
- 3.x skipped its planning prompt when the agent's instructions already mentioned `write_todos`, but 4.x always sends the guidelines. If the instructions mention `write_todos`, keep the 3.x result with `TodoPlanningToolkit::make('')`: empty guidelines are not sent.
- A `TodoPlanning` subclass becomes a `TodoPlanningToolkit` subclass that returns its text from `guidelines()`. You can also pass the text to `make()` and delete the subclass. Register it as in Case 1. If the subclass overrode `before()` or `after()` to do more than add the prompt and the tool, the toolkit has nowhere to put that logic: report it to the developer and ask where it should go.

Before (3.x):

```php
use NeuronAI\Agent\Middleware\TodoPlanning;

class MigrationPlanning extends TodoPlanning
{
    public function __construct()
    {
        parent::__construct('Plan every schema migration with `write_todos` before running it.');
    }
}
```

After (4.x), registered with `MigrationPlanning::make()`:

```php
use NeuronAI\Tools\Toolkits\TodoPlanning\TodoPlanningToolkit;

class MigrationPlanning extends TodoPlanningToolkit
{
    public function guidelines(): ?string
    {
        return 'Plan every schema migration with `write_todos` before running it.';
    }
}
```

### Case 3: WriteTodosTool is built directly or extended

The tool moved to `NeuronAI\Tools\Toolkits\TodoPlanning\WriteTodosTool`. It takes no constructor arguments and cannot reach the agent state.

- Code that builds the tool only to give it to an agent registers `TodoPlanningToolkit::make()` instead (Case 1), so the guidelines are sent too. If that code is a copy of the 3.x `TodoPlanning` (it appends a prompt and pushes the tool in `before()`), delete it and migrate its registration as in Cases 1 and 2.
- Any other `new WriteTodosTool($state)` becomes `WriteTodosTool::make()` with the new import.

For a subclass:

1. Switch the parent import to `NeuronAI\Tools\Toolkits\TodoPlanning\WriteTodosTool`.
2. Delete `parent::__construct($state)` and the `AgentState` parameter, because 4.x `Tool` has no constructor. Delete the constructor if nothing is left in it.
3. If the subclass used `$this->state` or `$this->todos`, ask the developer where that data should come from or go now.
4. Register the subclass through a `TodoPlanningToolkit` subclass that returns it from `provide()`, so the guidelines still go with it.

Before (3.x):

```php
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Middleware\WriteTodosTool;

class AuditedWriteTodosTool extends WriteTodosTool
{
    public function __construct(AgentState $state, protected AuditLog $audit)
    {
        parent::__construct($state);
    }

    public function __invoke(array $todos): string
    {
        $this->audit->record($todos);

        return parent::__invoke($todos);
    }
}
```

After (4.x), registered with `new AuditedTodoPlanning($audit)`:

```php
use NeuronAI\Tools\Toolkits\TodoPlanning\TodoPlanningToolkit;
use NeuronAI\Tools\Toolkits\TodoPlanning\WriteTodosTool;

class AuditedWriteTodosTool extends WriteTodosTool
{
    public function __construct(protected AuditLog $audit)
    {
    }

    public function __invoke(array $todos): string
    {
        $this->audit->record($todos);

        return parent::__invoke($todos);
    }
}

class AuditedTodoPlanning extends TodoPlanningToolkit
{
    public function __construct(protected AuditLog $audit)
    {
        parent::__construct();
    }

    public function provide(): array
    {
        return [new AuditedWriteTodosTool($this->audit)];
    }
}
```

### Case 4: Code reads or writes `__todos`

Nothing writes `__todos` any more. A leftover `$state->get('__todos')` still compiles and silently returns `null`, so convert every hit.

Before (3.x):

```php
$todos = $state->get('__todos');
```

After (4.x):

```php
use NeuronAI\Chat\Messages\ToolCallMessage;

$todos = null;
foreach ($agent->getChatHistory()->getMessages() as $message) {
    if (!$message instanceof ToolCallMessage) {
        continue;
    }
    foreach ($message->getToolCalls() as $call) {
        if ($call->getName() === 'write_todos') {
            $todos = $call->getInput('todos');
        }
    }
}
```

- `$agent` is the agent whose conversation holds the plan. In 4.x, `getChatHistory()` needs the agent's thread ID, which guide 13 binds. Read it after the run has returned. Inside a `stream()` loop the latest call is not in the history yet, so take it from the chunk: `if ($chunk instanceof ToolResultChunk && $chunk->tool->getName() === 'write_todos') { $todos = $chunk->tool->getInput('todos'); }` (`use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;`).
- In a middleware or node, only the state is in scope. Loop over the history that code can reach today, `$state->getChatHistory()->getMessages()`, and leave that call for guide 26 (nodes) or guide 27 (middleware). In 4.x the history holds only committed messages: a `write_todos` call from the current tool cycle waits in `$state->request->messages` until the next inference succeeds. So after guide 26 or 27 converts this code, the loop must run over `[...$resources->history->getMessages(), ...$state->request->messages]`. `$resources->history->getMessages()` alone returns the previous list in `afterAgentNode()` on `ToolNode` and `beforeAgentNode()` on `ChatNode`.
- Delete every `$state->set('__todos', ...)` and `$state->delete('__todos')`, because nothing reads that key. If the app wrote it to seed or reset the plan, ask the developer how the model should receive that list now (for example, in the user message).

## Checklist

- The "must return nothing" grep above returns nothing.
- Every agent that planned with the middleware lists `TodoPlanningToolkit` (or its subclass) among its tools exactly once, and registers no separate `WriteTodosTool`.
- Custom guidelines do not start with the 3.x `---` / ``## `write_todos` `` heading.
- The questions from Case 1 step 4, Case 2 (overridden `before()` / `after()`), Case 3 step 3 and Case 4 (seeded lists) were put to the developer wherever they apply.
- Static analysis reports no error that mentions `TodoPlanning`, `TodoPlanningToolkit` or `WriteTodosTool`.
