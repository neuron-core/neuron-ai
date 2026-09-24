# Upgrade: Graph hooks receive the execution

## Summary

- **`Workflow::nodes()` receives the execution.** Its signature is `nodes(WorkflowExecution $execution): array`. A 3.x
  override declared as `nodes(): array` no longer loads: PHP rejects the incompatible signature. The graph is built
  for every execution segment (a run, or a continuation after a pause), so `nodes()` runs each time and must stay free
  of side effects.
- **Agent and RAG build their graph in hooks.** `Agent::compose()` and `RAG::ragNodes()` are removed. The default
  graph comes from `nodes()`, which registers `entryNodes()`, the inference and tool nodes, and `exitNodes()`. All
  three receive the execution: for an Agent it is an `AgentExecution` holding the segment's provider, chat history,
  instructions and tools.
- **Nodes take the segment's collaborators from the execution.** `resolveProvider()`, `resolveInstructions()` and
  `bootstrapTools()` are removed. Inside a graph hook, `$execution->getProvider()`, `getChatHistory()`,
  `getInstructions()` and `getTools()` return the objects resolved once for the segment, so every node shares one
  history and one tool list.
- **Nodes added with `addNode()` are copied for every segment.** A configured node is a template cloned at the start
  of each segment. `addNode()` also accepts a factory, `fn (): NodeInterface => ...`, for a node that must be built
  from scratch each time.

| Before (3.x) | After |
|---|---|
| `protected function nodes(): array` | `protected function nodes(WorkflowExecution $execution): array` |
| `protected function compose(array\|Node $nodes): void` on Agent | `nodes()`, `entryNodes()` or `exitNodes()` receiving the execution |
| `protected function ragNodes(): array` on RAG | `protected function entryNodes(WorkflowExecution $execution): array` |
| `$this->resolveProvider()` | `$execution->getProvider()` |
| `$this->resolveInstructions()`, `$this->bootstrapTools()` | `$execution->getInstructions()`, `$execution->getTools()` |

## How to Refactor

### Case 1: A workflow that declares its nodes

Add the parameter. A workflow that doesn't need it ignores it; one whose graph depends on the run reads
`$execution->context`: the workflow ID, run ID, execution attempt and original input (`startEvent()`).

Before:

```php
class OrderWorkflow extends Workflow
{
    protected function nodes(): array
    {
        return [new ValidationNode(), new ProcessingNode()];
    }
}
```

After:

```php
use NeuronAI\Workflow\WorkflowExecution;

class OrderWorkflow extends Workflow
{
    protected function nodes(WorkflowExecution $execution): array
    {
        return [new ValidationNode(), new ProcessingNode()];
    }
}
```

### Case 2: An agent that changed its graph through `compose()`

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
use NeuronAI\Agent\AgentExecution;
use NeuronAI\Workflow\WorkflowExecution;

class ReportAgent extends Agent
{
    /**
     * @param AgentExecution $execution
     */
    protected function nodes(WorkflowExecution $execution): array
    {
        return [...parent::nodes($execution), new AuditNode($execution->getProvider())];
    }
}
```

Output processing belongs in `exitNodes()`, which returns `[new AgentEndNode()]` by default. Its first node handles
`AgentOutputEvent`: replace `AgentEndNode` rather than adding a second handler for that event.

### Case 3: A RAG agent that overrode `ragNodes()`

Override `entryNodes()`. Start from `parent::entryNodes($execution)` to add a node, or copy `RAG::entryNodes()` to
replace one: the default chain's nodes take the segment's history, instructions and tools from the execution.

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
use NeuronAI\Agent\AgentExecution;
use NeuronAI\Workflow\WorkflowExecution;

class DocsRAG extends RAG
{
    /**
     * @param AgentExecution $execution
     */
    protected function entryNodes(WorkflowExecution $execution): array
    {
        return [...parent::entryNodes($execution), new CitationNode()];
    }
}
```

### Case 4: Nodes built with the agent's collaborators

Take them from the execution in the graph hook that builds the node. Calling the agent's getters there would build a
second provider when the `provider()` hook creates one, and open a second view of the conversation that doesn't see
what the other nodes write.

Before:

```php
new SummaryNode($this->resolveProvider(), $this->resolveInstructions());
```

After:

```php
new SummaryNode($execution->getProvider(), $execution->getInstructions());
```

### Case 5: A node added with `addNode()` that keeps state between runs

In 3.x the Workflow reused the node instance on every run, so values a node stored in its own properties carried over
to the next run. The configured node is now cloned for each segment, and what a segment stores on its copy is gone in
the next one. Keep data that a run needs across its segments in the workflow state, and data that must outlive the run
in an injected service. The clone is shallow: objects the node holds are shared by every copy, as the reused instance
shared them before. Register a factory when each segment needs its own instances of them.

## What to Search For

```
grep -rn "function nodes()" --include="*.php" .
grep -rn "function compose(" --include="*.php" .
grep -rn "function ragNodes(" --include="*.php" .
grep -rn "resolveProvider()\|resolveInstructions()\|bootstrapTools()" --include="*.php" .
grep -rn "addNode(\|addNodes(" --include="*.php" .
```

## Checklist

- Every `nodes()` override declares `WorkflowExecution $execution` and has no side effects.
- No `compose()` or `ragNodes()` override remains; custom nodes are registered in `nodes()`, `entryNodes()` or `exitNodes()`.
- Nodes built in graph hooks take the provider, chat history, instructions and tools from `$execution`.
- Nodes added with `addNode()` keep no data in their own properties between segments: a run's data lives in the workflow state, longer-lived data in an injected service.
