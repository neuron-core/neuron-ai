# Upgrade: Agent graph hooks and node collaborators

## Summary

- **Agent and RAG build their graph in hooks.** `Agent::compose()` and `RAG::ragNodes()` are removed. The default
  graph comes from `nodes()`, which registers `entryNodes()`, the inference and tool nodes, and `exitNodes()`. The
  hooks take no argument and run at every execution segment (a run, or a continuation after a pause), so they must
  stay free of side effects.
- **Agent nodes take no collaborators.** `resolveProvider()`, `resolveInstructions()` and `bootstrapTools()` are
  removed. The Agent resolves the provider, chat history, instructions (with toolkit guidelines) and tools once per
  segment into `NeuronAI\Agent\AgentResources`, and every node that declares it as the third `__invoke()` parameter
  receives it. The built-in nodes lose their provider parameter.
- **Nodes added with `addNode()` are copied for every segment.** A configured node is a template cloned at the start
  of each segment. `addNode()` also accepts a factory, `fn (): NodeInterface => ...`, for a node that must be built
  from scratch each time.

| Before (3.x) | After |
|---|---|
| `protected function compose(array\|Node $nodes): void` on Agent | `nodes()`, `entryNodes()` or `exitNodes()` |
| `protected function ragNodes(): array` on RAG | `protected function entryNodes(): array` |
| `$this->resolveProvider()` passed to a node | `$resources->provider` inside the node |
| `$this->resolveInstructions()`, `$this->bootstrapTools()` passed to a node | `$resources->instructions`, `$resources->tools` inside the node |
| `new ChatNode($provider)`, `new StreamingNode($provider)` | `new ChatNode()`: it streams when the run asks for it |
| `new StructuredOutputNode($provider, Output::class, $maxTries, $extractor)` | `new StructuredOutputNode($extractor)`: the output class and retries come from the run, `structured($messages, Output::class, $maxRetries)` |
| `new InstructionsNode($instructions, $tools)` on RAG | `new InstructionsNode()` |

`ToolNode`, `ParallelToolNode`, `PreProcessNode`, `RetrievalNode` and `PostProcessNode` keep their 3.x constructors.

## How to Refactor

### Case 1: An agent that changed its graph through `compose()`

Override the hook for the part of the graph you change: `entryNodes()` for the nodes before the first inference,
`exitNodes()` for the nodes after the final response, `nodes()` for anything else. Start from the parent's nodes to
add to them. The array only registers nodes: each node runs for the event class it handles, and a workflow allows one
handler per event class.

Before:

```php
class ReportAgent extends Agent
{
    protected function compose(array|Node $nodes): void
    {
        parent::compose([
            ...(is_array($nodes) ? $nodes : [$nodes]),
            new AuditNode($this->resolveProvider()),
        ]);
    }
}
```

After:

```php
class ReportAgent extends Agent
{
    protected function nodes(): array
    {
        return [...parent::nodes(), new AuditNode()];
    }
}
```

`AuditNode` takes the provider from its resources (Case 3). Output processing belongs in `exitNodes()`, which returns
`[new AgentEndNode()]` by default. Its first node handles `AgentOutputEvent`: replace `AgentEndNode` rather than adding
a second handler for that event.

### Case 2: A RAG agent that overrode `ragNodes()`

Override `entryNodes()`. Start from `parent::entryNodes()` to add a node, or copy `RAG::entryNodes()` to replace one.

Before:

```php
class DocsRAG extends RAG
{
    protected function ragNodes(): array
    {
        return [...parent::ragNodes(), new CitationNode()];
    }
}
```

After:

```php
class DocsRAG extends RAG
{
    protected function entryNodes(): array
    {
        return [...parent::entryNodes(), new CitationNode()];
    }
}
```

### Case 3: A node built with the agent's collaborators

Declare the segment's resources as the third `__invoke()` parameter and read the collaborators there. Every node and
middleware of the segment receives the same object, so they share one provider, one view of the conversation, and
the tools the model was offered. Services of your own, which the Agent does not resolve, stay constructor
dependencies.

Before:

```php
class SummaryNode extends Node
{
    public function __construct(protected AIProviderInterface $provider, protected string $instructions)
    {
    }

    public function __invoke(AgentOutputEvent $event, AgentState $state): StopEvent
    {
        $summary = $this->provider->systemPrompt($this->instructions)->chat(/* ... */);
        // ...
    }
}

new SummaryNode($this->resolveProvider(), $this->resolveInstructions());
```

After:

```php
use NeuronAI\Agent\AgentResources;

class SummaryNode extends Node
{
    public function __invoke(AgentOutputEvent $event, AgentState $state, AgentResources $resources): StopEvent
    {
        $summary = $resources->provider->systemPrompt($resources->instructions)->chat(/* ... */);
        // ...
    }
}

new SummaryNode();
```

A node that asks for `AgentResources` in a workflow that does not provide them fails when the graph is built. Agent
and RAG provide them; guide 39 shows how a workflow of your own does.

### Case 4: A node added with `addNode()` that keeps state between runs

In 3.x the Workflow reused the node instance on every run, so values a node stored in its own properties carried over
to the next run. The configured node is now cloned for each segment, and what a segment stores on its copy is gone in
the next one. Keep data that a run needs across its segments in the workflow state, and data that must outlive the run
in an injected service. The clone is shallow: objects the node holds are shared by every copy, as the reused instance
shared them before. Register a factory when each segment needs its own instances of them.

## What to Search For

```
grep -rn "function compose(" --include="*.php" .
grep -rn "function ragNodes(" --include="*.php" .
grep -rn "resolveProvider()\|resolveInstructions()\|bootstrapTools()" --include="*.php" .
grep -rnE "new (ChatNode|StreamingNode|StructuredOutputNode|InstructionsNode)\(" --include="*.php" .
grep -rn "addNode(\|addNodes(" --include="*.php" .
```

## Checklist

- No `compose()` or `ragNodes()` override remains; custom nodes are registered in `nodes()`, `entryNodes()` or `exitNodes()`, which take no argument and have no side effects.
- Nodes read the provider, chat history, instructions and tools from `AgentResources`, not from constructor arguments.
- Built-in nodes are constructed with their new signatures, and no `StreamingNode` remains.
- Nodes added with `addNode()` keep no data in their own properties between segments: a run's data lives in the workflow state, longer-lived data in an injected service.
