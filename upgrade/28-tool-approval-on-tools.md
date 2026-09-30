# Upgrade: ToolApproval middleware removed: approval is configured on each tool

## Summary

`NeuronAI\Agent\Middleware\ToolApproval` and its helper `NeuronAI\Agent\Tools\ToolRejectionHandler` are deleted, with no alias. The approval gate is now built into `ToolNode` and `ParallelToolNode`: before running a call, they ask the tool `requiresApproval(): bool|string`. No tool is gated unless the application configures it, and no built-in tool gates itself. If you delete a registration without moving its configuration onto the tools, the tools it gated run without asking.

| 3.x `ToolApproval` configuration | 4.x, on the tool |
|---|---|
| `new ToolApproval()` / `new ToolApproval([])`: every tool gated | every tool the agent offers gets `->requireApproval()` (Case 6) |
| entry `DeleteFile::class` or `'delete_file'` | `DeleteFile::make()->requireApproval()` (Case 1) |
| entry `MoneyTransfer::class => fn (array $inputs): bool => ...` | `->withApprovalPolicy(fn (ToolInterface $tool): bool => ...)` (Case 2) |
| entry naming a toolkit tool | `$toolkit->with(ToolClass::class, fn (Tool $tool): ToolInterface => $tool->requireApproval())` (Case 3) |
| entry naming an MCP tool | `$connector->with('tool_name', fn (Tool $tool): ToolInterface => $tool->requireApproval())` (Case 4) |
| entry naming a class that implements `ToolInterface` directly | its `requiresApproval()` returns `true` or the condition (Case 5) |
| `class X extends ToolApproval`, `ToolRejectionHandler` | no counterpart (Case 7) |

The 4.x API on `NeuronAI\Tools\Tool`:

- `requireApproval(bool $require = true): ToolInterface` always gates the tool. `suppressApproval()` is `requireApproval(false)`.
- `withApprovalPolicy(callable $policy): ToolInterface` gates a call when `$policy(ToolInterface $tool): bool|string` answers `true`. `$tool` is a copy of the tool holding this call's arguments: read them with `$tool->getInputs()` or `$tool->getInput('name')`.
- `protected function approvalPolicy(): bool|string` is the class's own answer (default `false`). `requireApproval()` and `withApprovalPolicy()` override it, and the last of them called on an instance wins.
- A returned string counts as `true`. The approver sees it as the action's reason (`Action::$reason`).
- Both setters return `ToolInterface`. In a chain that also calls `Tool`-only methods such as `setParameters()`, call them last.

Stored data: this guide changes no stored data. Runs that paused for approval under 3.x cannot be continued by 4.x (guide 14). Guide 3 already added `requiresApproval()` to direct `ToolInterface` implementers and renamed app members that collided with the new approval members. Leave the pause and resume code for guide 29: `catch (WorkflowInterrupt ...)`, `chat(interrupt: ...)`, `Action::approve()` / `reject()`, and the rejection text.

## What to Search For

```bash
grep -rnE 'ToolApproval([^A-Za-z0-9_]|$)|ToolRejectionHandler' --include='*.php' --include='*.yaml' --include='*.yml' --include='*.xml' --include='*.neon' --exclude-dir=vendor .
```

If nothing is found, this guide does not apply.

Follow each hit:

- `new ToolApproval(...)`: the argument is the configuration (none, or `[]`, means zero-config). Find the agent it is registered on: an `addMiddleware()` / `addGlobalMiddleware()` call, an entry in the `middleware()` / `globalMiddleware()` hook, a variable that holds the instance, or a factory or container definition (the YAML/XML/NEON hits). Write down each pair of agent and configuration.
- `extends ToolApproval`, or `ToolRejectionHandler`: Case 7. Also find where the subclass is registered.

For each agent you wrote down, find where its tools are attached, and the classes that implement `ToolInterface` directly:

```bash
grep -rnE 'function tools[(]|->(addTool|setTools)[(]|McpConnector|ToolSearchMiddleware|TodoPlanningToolkit' --include='*.php' --exclude-dir=vendor .
grep -rnE 'implements[^{]*ToolInterface' --include='*.php' --exclude-dir=vendor .
```

## How to Refactor

1. Delete each registration and its imports (Case 1). Keep the configuration you wrote down.
2. Move each configuration entry onto the tool it names (Cases 1-6), following the rules below.
3. Put the configuration on every instance the registration covered (see "Where the configuration goes").
4. Migrate subclasses and `ToolRejectionHandler` (Case 7).

Rules for translating a 3.x entry:

- An entry matched a tool whose name (`getName()`, after any `setName()`) equals it, or whose class is exactly the named class. Subclasses of the named class did not match. A `Tool::class` entry matched every MCP tool and every tool built with `Tool::make()` (now the classes guide 3 made from them).
- When several entries matched one tool, the first one in the array decided. Apply only that one.
- An entry that names no tool the agent offers, or a callback that can only return `false`, gated nothing. Drop it.

Where the configuration goes: configure each tool where that agent attaches it. That can be `tools()`, `addTool()`, `setTools()`, a toolkit (Case 3), an MCP connector (Case 4), the pool passed to `ToolSearchMiddleware`, or the `ToolRegistry` of a workflow that runs agent nodes (guide 18). Every instance that the 3.x registration covered needs the same configuration, including the instance that continues a paused run (guide 29). An instance without it runs the call even when the decision was a rejection.

- A registration inside the agent class (`middleware()`, `globalMiddleware()`, the constructor) covered every instance, so configure the tools where the class attaches them.
- A registration added from outside (a call site, a factory, a container) covered only the instances built there. If every place that builds the agent, including the resume endpoint, registered the same configuration, configure the tools in the class as well. Otherwise ask the developer: "`<Agent>` asked for approval only where `<file:line>` registered `ToolApproval`. Should every `<Agent>` ask for approval, or only those instances?" If the answer is "only those", ask how those instances should receive the configured tools (for example, a constructor argument that `tools()` reads).

### Case 1: Name and class entries on application tools

Before (3.x, tool classes as guide 3 left them):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;

class FileAgent extends Agent
{
    protected function tools(): array
    {
        return [
            DeleteFile::make(),
            TransferMoney::make(),
            ListFiles::make(),
        ];
    }

    protected function middleware(): array
    {
        return [
            ToolNode::class => new ToolApproval([
                DeleteFile::class,
                'transfer_money',
            ]),
        ];
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;

class FileAgent extends Agent
{
    protected function tools(): array
    {
        return [
            DeleteFile::make()->requireApproval(),
            TransferMoney::make()->requireApproval(),
            ListFiles::make(),
        ];
    }
}
```

A tool with constructor arguments is configured the same way: `(new DeleteFile($filesystem))->requireApproval()`. A tool added from outside the class is configured where it is added: `$agent->addTool(DeleteFile::make()->requireApproval())`.

Delete every form of registration. They all gated the agent's tool calls in the same way:

- `addMiddleware(ToolNode::class, new ToolApproval(...))`, also with `ParallelToolNode::class` or an array of node classes;
- `addGlobalMiddleware(new ToolApproval(...))`;
- a `middleware()` entry `ToolNode::class => new ToolApproval(...)`, or `new ToolApproval(...)` in `globalMiddleware()`;
- a container or factory definition, together with the code that registered the service on the agent.

When `ToolApproval` sits in an array with other middleware, delete only its element. Delete a `middleware()` or `globalMiddleware()` hook that is left returning `[]`. Remove the `ToolApproval` import. Remove the `ToolNode` / `ParallelToolNode` imports only if nothing else in the file uses them.

Some registrations never gated anything, because `ToolApproval` only acted on the tool node:

- a registration only on `ChatNode`, `StreamingNode`, `StructuredOutputNode` or `InferenceNode` (the 3.x agent-builder skill showed one);
- a `ToolApproval` passed as an argument to `$this->middleware(...)`.

Delete these without translating their configuration, and ask the developer: "`<Agent>` registered `ToolApproval` on its inference nodes, so 3.x never asked for approval. Should its tools ask for approval now?" If the answer is yes, translate the configuration as in this guide.

### Case 2: Callback entries

The 3.x callback received the call's arguments. The 4.x policy receives the tool with the arguments bound.

Before (3.x):

```php
protected function middleware(): array
{
    return [
        ToolNode::class => new ToolApproval([
            MoneyTransfer::class => fn (array $inputs): bool => ($inputs['amount'] ?? 0) > 100,
        ]),
    ];
}
```

After (4.x), with the `middleware()` entry deleted:

```php
use NeuronAI\Tools\ToolInterface;

protected function tools(): array
{
    return [
        MoneyTransfer::make()->withApprovalPolicy(
            fn (ToolInterface $tool): bool => ($tool->getInputs()['amount'] ?? 0) > 100
        ),
    ];
}
```

- A callback that is not a closure, such as `[$this, 'needsApproval']` or an invokable object, is kept and called with the arguments: `fn (ToolInterface $tool): bool => $this->needsApproval($tool->getInputs())`.
- `getInputs()` returns the converted values (guide 5). The argument of a class-mapped `ObjectProperty` is an object, so replace array access on it with property access: `$inputs['address']['country']` becomes `$tool->getInput('address')->country`.
- Optional: return a string in place of `true` to tell the approver why (`: bool|string`, `? 'Transfers above 100 need a sign-off' : false`).

Alternative for a tool class the app owns: override `approvalPolicy()` in the class. This gates the class on every agent that registers it, so use it only when all of those agents gated it this way in 3.x:

```php
use NeuronAI\Tools\Tool;

class MoneyTransfer extends Tool
{
    protected function approvalPolicy(): bool|string
    {
        return ($this->getInput('amount') ?? 0) > 100;
    }

    // ...
}
```

### Case 3: Tools provided by a toolkit

Before (3.x):

```php
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;

protected function tools(): array
{
    return [FileSystemToolkit::make()];
}

protected function middleware(): array
{
    return [
        ToolNode::class => new ToolApproval([
            'delete_file',
            BashTool::class => fn (array $inputs): bool => str_contains($inputs['command'] ?? '', 'rm '),
        ]),
    ];
}
```

After (4.x):

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use NeuronAI\Tools\Toolkits\FileSystem\DeleteFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;

protected function tools(): array
{
    return [
        FileSystemToolkit::make()
            ->with(DeleteFileTool::class, fn (Tool $tool): ToolInterface => $tool->requireApproval())
            ->with(BashTool::class, fn (Tool $tool): ToolInterface => $tool->withApprovalPolicy(
                fn (ToolInterface $call): bool => str_contains($call->getInput('command') ?? '', 'rm ')
            )),
    ];
}
```

- `with()` takes the tool's class. For a name entry, find the class that declares that name: `grep -rn 'protected string \$name' vendor/neuron-core/neuron-ai/src/Tools/Toolkits/<Toolkit>`, or the app's own toolkit.
- A toolkit keeps one `with()` callback per class, and a second `with()` for the same class replaces the first. If the toolkit already has a `with()` for that class, add the approval call inside the existing callback.
- Type the callback parameter as `Tool`: the approval setters are declared on `Tool`, not on `ToolInterface`. A tool in an app toolkit that implements `ToolInterface` directly is Case 5.

### Case 4: MCP tools

The 3.x configuration matched an MCP tool by the name its server publishes. 4.x configures the tool on the connector.

Before (3.x):

```php
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\MCP\McpConnector;

protected function tools(): array
{
    return [
        ...McpConnector::make([
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-github'],
        ])->tools(),
    ];
}

protected function middleware(): array
{
    return [
        ToolNode::class => new ToolApproval(['create_issue']),
    ];
}
```

After (4.x):

```php
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;

protected function tools(): array
{
    return [
        ...McpConnector::make([
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-github'],
        ])
            ->with('create_issue', fn (Tool $tool): ToolInterface => $tool->requireApproval())
            ->tools(),
    ];
}
```

`with()` takes the name the server publishes, and the connector keeps one callback per name. If guide 11 added a `with()` that renames this tool, put the approval call inside that callback: `fn (Tool $tool): ToolInterface => $tool->requireApproval()->setName('gitlab_create_issue')`. A callback entry becomes `$tool->withApprovalPolicy(...)` inside the `with()` callback, as in Case 3.

### Case 5: Classes that implement ToolInterface directly

Guide 3 gave these classes a `requiresApproval()` that returns `false`. For a class that a 3.x entry gated, make it return `true`, or the callback's condition computed over `$this->getInputs()`. The framework calls it on a copy of the tool that holds the call's arguments.

Before (as guide 3 left it):

```php
use NeuronAI\Tools\ToolInterface;

class ShellCommand implements ToolInterface
{
    // ...

    public function requiresApproval(): bool|string
    {
        return false;
    }
}
```

After (4.x), for an entry `ShellCommand::class` or `'shell_command'`:

```php
public function requiresApproval(): bool|string
{
    return true;
}
```

For a callback entry, return the callback's expression, for example `return ($this->getInputs()['amount'] ?? 0) > 100;`. The answer applies on every agent that registers the class. If an agent that did not gate it in 3.x also registers it, ask the developer how those agents should tell the tool apart (for example, a constructor argument).

### Case 6: Zero-config `new ToolApproval()`: every tool

`new ToolApproval()` and `new ToolApproval([])` gated every tool call of the agent. Apply the "always gate" form to every tool the agent offers:

- each application tool: `->requireApproval()`;
- each tool of each toolkit: one `with()` per class its `provide()` returns (read the toolkit's `provide()`);
- each MCP connector: wrap its tools, so that tools the server adds later are gated too, as they were in 3.x:

  ```php
  ...array_map(
      fn (ToolInterface $tool): ToolInterface => $tool instanceof Tool ? $tool->requireApproval() : $tool,
      McpConnector::make($config)->tools(),
  ),
  ```

- each class implementing `ToolInterface` directly: Case 5;
- each tool in the pool array passed to `new ToolSearchMiddleware(...)`, for example `ListFiles::make()->requireApproval()`.

Provider tools (`ProviderTool`) run at the vendor and were never gated: leave them.

Before (3.x):

```php
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Tools\Toolkits\MySQL\MySQLToolkit;

protected function tools(): array
{
    return [
        DeleteFile::make(),
        SendEmail::make(),
        MySQLToolkit::make($this->pdo),
    ];
}

protected function globalMiddleware(): array
{
    return [new ToolApproval()];
}
```

After (4.x):

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\MySQL\MySQLSchemaTool;
use NeuronAI\Tools\Toolkits\MySQL\MySQLSelectTool;
use NeuronAI\Tools\Toolkits\MySQL\MySQLToolkit;
use NeuronAI\Tools\Toolkits\MySQL\MySQLWriteTool;

protected function tools(): array
{
    return [
        DeleteFile::make()->requireApproval(),
        SendEmail::make()->requireApproval(),
        MySQLToolkit::make($this->pdo)
            ->with(MySQLSchemaTool::class, fn (Tool $tool): ToolInterface => $tool->requireApproval())
            ->with(MySQLSelectTool::class, fn (Tool $tool): ToolInterface => $tool->requireApproval())
            ->with(MySQLWriteTool::class, fn (Tool $tool): ToolInterface => $tool->requireApproval()),
    ];
}
```

The zero-config middleware also gated two framework helper tools. Do not gate them on your own. Report them to the developer:

- `write_todos`, from `TodoPlanningToolkit` (guide 10). Ask: "Should `write_todos` still ask for approval, as it did in 3.x?" If the answer is yes: `TodoPlanningToolkit::make()->with(WriteTodosTool::class, fn (Tool $tool): ToolInterface => $tool->requireApproval())`, with `NeuronAI\Tools\Toolkits\TodoPlanning\WriteTodosTool`.
- `tool_search`, added by `ToolSearchMiddleware`. 4.x offers no way to configure it, so it runs without approval.

Alternative for application tool classes only: a shared base class that overrides `protected function approvalPolicy(): bool|string { return true; }`. It does not reach toolkit or MCP tools. It also gates those classes on every agent, so use it only when every agent that registers them was zero-config.

### Case 7: ToolApproval subclasses, custom approval middleware and ToolRejectionHandler

`class X extends ToolApproval`:

1. Work out what its `toolRequiresApproval()` / `filterToolsRequiringApproval()` overrides answered for each tool of the agent. A subclass built with an empty configuration gated every tool, unless it overrode `filterToolsRequiringApproval()`.
2. Express that answer on the tools with Cases 1-6. `withApprovalPolicy()` receives the tool with the call's arguments bound, as `toolRequiresApproval()` did, so the body of that method can usually move into the policy callback unchanged.
3. Overrides of `createAction()`, `processDecisions()` and `handleRejectedTool()` have no 4.x hook. The framework builds each `Action`: its description is the arguments as JSON, and its reason is the string the policy returned. The framework also writes the rejection result. A custom text from `createAction()` can be returned by the policy as its string, and the approver then sees it as `Action::$reason`. Delete these overrides and report them to the developer.
4. Delete the subclass and its registration.

Some hand-written middleware threw a `WorkflowInterrupt` carrying an `ApprovalRequest` before the tool node, to gate tool calls. Find it with `grep -rnE 'new (WorkflowInterrupt|ApprovalRequest)[(]' --include='*.php' --exclude-dir=vendor .` (only hits inside middleware classes matter). Migrate it the same way: move its decision onto the tools, then delete the middleware and its registration.

`ToolRejectionHandler`: delete every use and its import (typically `$tool->setCallable(new ToolRejectionHandler($message))` inside a `handleRejectedTool()` override). The framework writes the rejection result itself. If the app used it to make a tool answer a fixed text outside approvals, report it to the developer and ask how that tool should answer now. Usually the tool's `__invoke()` returns `ToolOutput::error($message)` (`NeuronAI\Tools\ToolOutput`).

## Checklist

- The first search in "What to Search For" returns nothing.
- Every recorded entry now sits on the tool it named. For application tools that means `requireApproval()`, `withApprovalPolicy()` or `approvalPolicy()`. For toolkit and MCP tools it goes through `with()`, and for direct implementers through `requiresApproval()`. Entries that gated nothing were dropped.
- Every zero-config agent gates every tool it offers. `write_todos` and `tool_search` were reported to the developer and handled as they chose.
- The configuration reaches every instance the 3.x registration covered, including the one that continues a paused run.
- Registrations on inference nodes, overrides with no 4.x hook, and non-approval uses of `ToolRejectionHandler` were reported to the developer.
- Static analysis reports no error about `ToolApproval`, `ToolRejectionHandler`, `requireApproval()`, `withApprovalPolicy()` or `approvalPolicy()`.
- For each formerly gated tool, the instance the agent attaches answers `$tool->setInputs($arguments)->requiresApproval()` with `true` (or a string) for arguments that 3.x gated, and with `false` for arguments it did not. Use valid arguments: a call whose arguments fail the tool's property validation is never gated and gets an error result instead. Approval tests that pause and resume are migrated by guide 29.
