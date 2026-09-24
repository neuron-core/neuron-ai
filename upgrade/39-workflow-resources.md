# Upgrade: Nodes and middleware receive the workflow resources

## Summary

A workflow separates what a run knows from what it can use. `WorkflowState` is persisted with every step.
`NeuronAI\Workflow\WorkflowResources` holds the services of one execution segment (clients, connections, a
conversation): the workflow builds it once per segment through the `resources()` hook or a `setResources()` factory,
never persists it, and hands it to nodes and middleware.

- **`WorkflowMiddleware::before()` and `after()` take a fourth parameter**, `WorkflowResources $resources`. A 3.x
  implementation no longer loads until it declares it.
- **A node may declare the resources as the third `__invoke()` parameter**, typed `WorkflowResources` or a subclass.
  The parameter is optional. A node asking for a subclass the workflow does not provide fails when the graph is built.
- **`NodeInterface::run()` passes them on.** Only relevant if you implement `NodeInterface` directly instead of
  extending `Node`.

| Before (3.x) | After |
|---|---|
| `before(NodeInterface $node, Event $event, WorkflowState $state): void` | `before(NodeInterface $node, Event $event, WorkflowState $state, WorkflowResources $resources): void` |
| `after(NodeInterface $node, Event $result, WorkflowState $state): void` | `after(NodeInterface $node, Event $result, WorkflowState $state, WorkflowResources $resources): void` |
| `NodeInterface::run(Event $event, WorkflowState $state)` | `NodeInterface::run(Event $event, WorkflowState $state, WorkflowResources $resources)` |

## How to Refactor

### Case 1: A middleware implementing `WorkflowMiddleware`

Add the parameter to both methods. A middleware that does not need the resources ignores it.

Before:

```php
class LoggingMiddleware implements WorkflowMiddleware
{
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        $this->logger->info('Executing: ' . $node::class);
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
    }
}
```

After:

```php
use NeuronAI\Workflow\WorkflowResources;

class LoggingMiddleware implements WorkflowMiddleware
{
    public function before(NodeInterface $node, Event $event, WorkflowState $state, WorkflowResources $resources): void
    {
        $this->logger->info('Executing: ' . $node::class);
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state, WorkflowResources $resources): void
    {
    }
}
```

A middleware extending `AgentMiddleware` receives them typed as `AgentResources` in `beforeAgentNode()` and
`afterAgentNode()` (guide 8).

### Case 2: A workflow of your own that runs agent nodes

Agent nodes read the provider, chat history, instructions and tools from `NeuronAI\Agent\AgentResources` (guide 36).
Agent and RAG provide them; any other workflow running agent nodes must provide them too, or its graph fails to build
with "needs NeuronAI\Agent\AgentResources, but the workflow provides NeuronAI\Workflow\WorkflowResources".

Before:

```php
$workflow = Workflow::make(state: new AgentState())
    ->addNodes([new ChatNode($provider), new ToolNode()]);
```

After:

```php
use NeuronAI\Agent\AgentResources;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Tools\ToolRegistry;

$workflow = Workflow::make(state: new AgentState())
    ->setResources(fn (): AgentResources => new AgentResources(
        $provider,
        new ChatHistory($messageStore, $threadId),
        new SystemMessage('Be helpful'),
        new ToolRegistry($tools),
    ))
    ->addNodes([new ChatNode(), new ToolNode()]);
```

A workflow subclass overrides the `resources()` hook instead, returning `AgentResources`. The factory and the hook run
once per execution segment, so a continuation gets live services again.

### Case 3: A class implementing `NodeInterface` directly

Declare the third parameter of `run()` and pass the resources to the logic that needs them. `setWorkflowContext()`
changed as well (guide 7).

## What to Search For

```
grep -rn "implements WorkflowMiddleware" --include="*.php" .
grep -rnE "function (before|after)\(NodeInterface" --include="*.php" .
grep -rn "implements NodeInterface" --include="*.php" .
grep -rnE "new (ChatNode|StructuredOutputNode|ToolNode|ParallelToolNode)\(" --include="*.php" .
```

Follow each node construction to the workflow that registers it: inside an Agent or RAG subclass it needs no change.

## Checklist

- Every `before()` and `after()` of a `WorkflowMiddleware` declares `WorkflowResources $resources`.
- Every workflow running agent nodes, other than an Agent or RAG, provides `AgentResources`.
- Direct `NodeInterface` implementations declare `run(Event $event, WorkflowState $state, WorkflowResources $resources)`.
