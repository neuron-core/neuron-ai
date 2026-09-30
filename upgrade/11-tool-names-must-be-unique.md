# Upgrade: Tool names must be unique, hidden toolkit tools are dropped

## Summary

3.x never checked tool names. When an agent had two tools with the same name, 3.x sent both definitions to the
provider, and whenever the model called that name, the tool registered first ran. Registration order is: tools passed
to `addTool()` first, then the tools returned by `tools()`. A toolkit's tools take the toolkit's position in that
order, in the order its `provide()` lists them.

4.x checks the tool list of every Agent and RAG when a run starts, and again when a paused run continues. The check
runs before any request reaches the provider. A repeated name fails the run with `NeuronAI\Exceptions\ToolException`:
`Tool names must be unique: "web_search" is registered twice.`

The check compares:

- every tool from `tools()` and `addTool()`;
- the tools each toolkit provides, after its `only()`/`exclude()`/`with()`;
- MCP connector tools;
- provider tools that have a name (the second argument of `ProviderTool::make()`). Provider tools without a name are
  not compared.

Registering the same instance twice also counts as a repeat. Tools hidden with `visible(false)` are removed before
the check.

The second change: 3.x ignored `visible(false)` on a tool inside a toolkit, so the tool was still offered and
executed. 4.x honours it: the tool is not offered, cannot be executed, and its name is left out of the toolkit's
guidelines header. `visible(false)` on a tool registered directly behaves as it did in 3.x.

| 3.x | 4.x |
|---|---|
| Repeated tool names are all sent to the provider, and the tool registered first runs | The run fails with `ToolException` before the first provider request |
| `visible(false)` on a toolkit's tool is ignored | The tool is dropped from the agent |

Common collisions:

- `TavilyToolkit` and `JinaToolkit` both provide `web_search` (`TavilySearchTool` / `JinaWebSearch`) and
  `url_reader` (`TavilyExtractTool` / `JinaUrlReader`).
- Two application tools share a name. A toolkit is registered together with one of its own tools (for example
  `CalculatorToolkit` plus the standalone `EvaluateTool` that guide 8 put in place of an arithmetic tool, both
  `evaluate`). An application tool uses one of the calculator
  names guide 8 introduced (such as `evaluate` or `mean`) next to `CalculatorToolkit`.
- The same tool is registered in both `tools()` and `addTool()`, or `addTool()` is called again on an agent instance
  that is reused.
- Two MCP servers expose the same tool name, or an MCP tool has the same name as a local tool.
- A named provider tool shares its name with another tool.

Guide 18 later has you build a `NeuronAI\Tools\ToolRegistry` for workflows that run agent nodes. Its constructor
applies the same check to the tools you pass it.

Stored data is not affected. If you rename a tool, tool calls already saved in chat histories keep the name they were
recorded with.

## What to Search For

Run from the application root:

```bash
# Agents, RAG classes and toolkits that register tools
grep -rnE -e 'function (tools|provide)[(]' -e '->addTool[(]' --include='*.php' --exclude-dir=vendor .
# Toolkits, MCP connectors and provider tools that often collide
grep -rnE 'TavilyToolkit|JinaToolkit|TavilySearchTool|TavilyExtractTool|JinaWebSearch|JinaUrlReader|McpConnector|ProviderTool::make|new ProviderTool' --include='*.php' --exclude-dir=vendor .
# Tool names declared more than once in the application
grep -rhoE 'protected string \$name *= *[^;]+' --include='*.php' --exclude-dir=vendor . | sort | uniq -d
# Renamed and hidden tools
grep -rnE -e '->(setName|visible)[(]' --include='*.php' --exclude-dir=vendor .
# Names of the built-in toolkit tools
grep -rn 'protected string \$name' vendor/neuron-core/neuron-ai/src/Tools/Toolkits
```

For each value that `uniq -d` prints, grep the application for it to find the classes that declare it. Two tools with
the same name only collide when they are registered on the same agent.

For each Agent and RAG class, and for each `addTool()` call site, write down its final tool names in
registration order:

- application tool: its `protected string $name`, a name assigned in its constructor, or a `setName()` applied to it;
- toolkit: the names of the tools it provides after `only()`/`exclude()`/`with()` (for built-in toolkits, read them
  from the last command above);
- `McpConnector`: the names the server lists. If you cannot tell whether two servers, or a server and a local tool,
  share a name, ask the developer;
- `ProviderTool::make($type, $name)`: `$name`, when given.

A `visible(false)` call on a tool a toolkit provides, whether in a `with()` callback or in a toolkit's `provide()`, is
Case 6: resolve it first, because the developer may keep that tool. Then leave out of the list every tool that stays
hidden with `visible(false)`. A name that still appears twice is Cases 1-5.

If no agent has a repeated name and no toolkit tool is hidden, this guide does not apply.

## How to Refactor

To keep the 3.x behaviour, keep the tool registered first (the one 3.x ran) and remove the later duplicate. Rename a
tool only when the model should really get both. When the intent is unclear, ask the developer: "Agent `<Agent>` has
two tools named `<name>` (`<First>` and `<Second>`). 3.x always ran `<First>`. Should I keep only `<First>`, or rename
`<Second>` so the model can use both?"

`exclude()` and `only()`, on toolkits and on `McpConnector`, replace any list set before. If the code already calls one
of them, add to its existing list instead of chaining a second call. `with()` keeps one callback per tool class (per
tool name on `McpConnector`), so a second `with()` for the same tool replaces the first. If the code already has a
`with()` for the tool you rename, call `setName()` inside that callback, for example
`->with(JinaWebSearch::class, fn (ToolInterface $tool): ToolInterface => $tool->setMaxRuns(1)->setName('jina_web_search'))`.

After renaming a tool, update application code that compares the old name: `toolErrorHandler` callbacks, approval
configuration, tests, frontend code that displays tool names, and prompts that mention the tool.

### Case 1: TavilyToolkit and JinaToolkit on one agent

Before (3.x):

```php
use NeuronAI\Tools\Toolkits\Jina\JinaToolkit;
use NeuronAI\Tools\Toolkits\Tavily\TavilyToolkit;

protected function tools(): array
{
    return [
        TavilyToolkit::make($this->tavilyKey),
        JinaToolkit::make($this->jinaKey),
    ];
}
```

After (4.x), Tavily registered first. 3.x ran Tavily's tools, and Jina's two tools never ran, so remove `JinaToolkit`:

```php
use NeuronAI\Tools\Toolkits\Tavily\TavilyToolkit;

protected function tools(): array
{
    return [
        TavilyToolkit::make($this->tavilyKey),
    ];
}
```

After (4.x), Jina registered first. Keep Jina and exclude Tavily's two colliding tools. Tavily's `url_crawl` stays:

```php
use NeuronAI\Tools\Toolkits\Jina\JinaToolkit;
use NeuronAI\Tools\Toolkits\Tavily\TavilyExtractTool;
use NeuronAI\Tools\Toolkits\Tavily\TavilySearchTool;
use NeuronAI\Tools\Toolkits\Tavily\TavilyToolkit;

protected function tools(): array
{
    return [
        JinaToolkit::make($this->jinaKey),
        TavilyToolkit::make($this->tavilyKey)
            ->exclude([TavilySearchTool::class, TavilyExtractTool::class]),
    ];
}
```

After (4.x), the model should get both. Rename one toolkit's tools:

```php
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\Jina\JinaToolkit;
use NeuronAI\Tools\Toolkits\Jina\JinaUrlReader;
use NeuronAI\Tools\Toolkits\Jina\JinaWebSearch;
use NeuronAI\Tools\Toolkits\Tavily\TavilyToolkit;

protected function tools(): array
{
    return [
        TavilyToolkit::make($this->tavilyKey),
        JinaToolkit::make($this->jinaKey)
            ->with(JinaWebSearch::class, fn (ToolInterface $tool): ToolInterface => $tool->setName('jina_web_search'))
            ->with(JinaUrlReader::class, fn (ToolInterface $tool): ToolInterface => $tool->setName('jina_url_reader')),
    ];
}
```

### Case 2: Two tools with the same name, or a toolkit plus one of its own tools

Before (tool classes as guide 3 left them):

```php
class SearchCustomersTool extends Tool
{
    protected string $name = 'search';
    // ...
}

class SearchOrdersTool extends Tool
{
    protected string $name = 'search';
    // ...
}

protected function tools(): array
{
    return [
        new SearchCustomersTool(),
        new SearchOrdersTool(),
    ];
}
```

After (4.x), to keep the 3.x behaviour: remove `new SearchOrdersTool()` from the list, because 3.x always ran
`SearchCustomersTool`. If the model should get both, give the second tool its own name:

```php
class SearchOrdersTool extends Tool
{
    protected string $name = 'search_orders';
    // ...
}
```

If another agent still needs the old name, rename only the instance on this agent:
`SearchOrdersTool::make()->setName('search_orders')`.

For a toolkit plus one of its own tools registered separately, keep the one registered first. If the toolkit comes
first, remove the separate tool. If the separate tool comes first, exclude its class from the toolkit, for example
`CalculatorToolkit::make()->exclude([EvaluateTool::class])` (`use NeuronAI\Tools\Toolkits\Calculator\EvaluateTool;`).
Exclude only when the toolkit keeps at least one other tool: a toolkit left with no tools also stops sending its
guidelines. Otherwise remove the separate tool, and move any configuration it carried onto the toolkit's copy with
`->with(ToolClass::class, fn (ToolInterface $tool): ToolInterface => $tool->setMaxRuns(3))`.

### Case 3: The same tool registered twice

Before (3.x):

```php
class SupportAgent extends Agent
{
    protected function tools(): array
    {
        return [new SearchCustomersTool()];
    }
}

// Runs on every request against a shared SupportAgent instance
$agent->addTool(new SearchCustomersTool());
```

After (4.x): delete the `addTool()` call, because `tools()` already registers the tool. When `addTool()` adds a tool
that `tools()` does not register, call it once, where the instance is created, not on every use of a reused
instance.

### Case 4: Two MCP servers expose the same tool name

Before (3.x):

```php
use NeuronAI\MCP\McpConnector;

protected function tools(): array
{
    return [
        ...McpConnector::make([
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-github'],
        ])->tools(),
        ...McpConnector::make([
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-gitlab'],
        ])->tools(),
    ];
}
```

After (4.x), to keep the 3.x behaviour (GitHub's `create_issue` ran): drop the tool from the later connector with
`->exclude(['create_issue'])` before `->tools()`. If the model should get both, rename it with the connector's
`with()`:

```php
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ToolInterface;

protected function tools(): array
{
    return [
        ...McpConnector::make([
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-github'],
        ])->tools(),
        ...McpConnector::make([
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-gitlab'],
        ])
            ->with('create_issue', fn (ToolInterface $tool): ToolInterface => $tool->setName('gitlab_create_issue'))
            ->tools(),
    ];
}
```

The renamed tool still calls the server's `create_issue`. Handle an MCP tool that has the same name as a local tool
the same way.

### Case 5: A named provider tool shares a name with another tool

Before (3.x):

```php
use NeuronAI\Tools\ProviderTool;
use NeuronAI\Tools\Toolkits\Tavily\TavilyToolkit;

protected function tools(): array
{
    return [
        ProviderTool::make('web_search_20250305', 'web_search'),
        TavilyToolkit::make($this->tavilyKey),
    ];
}
```

The vendor fixes the names of its built-in tools (Anthropic's web search must be called `web_search`), so change the
other tool. Ask the developer whether the model should keep both searches. If it should, rename the other tool. If
not, drop it with `->exclude([TavilySearchTool::class])`.

After (4.x):

```php
use NeuronAI\Tools\ProviderTool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\Tavily\TavilySearchTool;
use NeuronAI\Tools\Toolkits\Tavily\TavilyToolkit;

protected function tools(): array
{
    return [
        ProviderTool::make('web_search_20250305', 'web_search'),
        TavilyToolkit::make($this->tavilyKey)
            ->with(TavilySearchTool::class, fn (ToolInterface $tool): ToolInterface => $tool->setName('tavily_web_search')),
    ];
}
```

### Case 6: A tool hidden inside a toolkit

Before (3.x):

```php
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\Tavily\TavilyCrawlTool;
use NeuronAI\Tools\Toolkits\Tavily\TavilyToolkit;

protected function tools(): array
{
    return [
        TavilyToolkit::make($this->tavilyKey)
            ->with(TavilyCrawlTool::class, fn (ToolInterface $tool): ToolInterface => $tool->visible(false)),
    ];
}
```

3.x ignored this `visible(false)` and the model could call `url_crawl`. 4.x removes the tool. Report each hit and ask
the developer: "`<Toolkit>` hides `<tool name>` with `visible(false)`. 3.x ignored that, so the model could call it. 4.x
removes it. Should the model keep calling it?"

- Yes (keep the 3.x behaviour): remove the `visible(false)` call. When a `with()` entry does nothing else, remove the
  whole entry:

  ```php
  use NeuronAI\Tools\Toolkits\Tavily\TavilyToolkit;

  protected function tools(): array
  {
      return [
          TavilyToolkit::make($this->tavilyKey),
      ];
  }
  ```

- No: leave the code unchanged. 4.x honours it.

The same applies to `visible(false)` inside a custom toolkit's `provide()`.

## Checklist

- For every Agent and RAG class, and every `addTool()` chain, the final tool name list has no name twice. The list
  covers `addTool()` entries, then `tools()` entries, with toolkits expanded after `only()`/`exclude()`/`with()`, MCP
  tools, and named provider tools. Tools that stay hidden with `visible(false)` are left out.
- Where a duplicate was removed, the tool registered first was kept, unless the developer chose otherwise.
- No toolkit or `McpConnector` has a second `exclude()` or `only()` call, or a second `with()` for the same tool, that
  silently replaces the first.
- For every renamed tool, application code no longer compares the old name, unless the name still belongs to the
  tool that kept it.
- No reused agent instance calls `addTool()` with a tool it already registers.
- Every `visible(false)` on a toolkit tool was reported to the developer and resolved as they chose.
