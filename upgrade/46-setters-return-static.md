# Upgrade: Agent and RAG setters return `static`

## Summary

In 3.x the fluent setters of `Agent` and `RAG` returned `AgentInterface`, `Agent`, `RAG` or `self`, so a chained call
lost the type of the object it was called on: `Agent::make()->setAiProvider($provider)` gave back an
`AgentInterface`, without the methods of `Agent` or of your subclass. Every one of them now returns `static`, and the
interfaces match their implementations.

- **Setters return `static`.** On `Agent`: `setAiProvider()`, `setInstructions()`, `setTools()`, `addTool()`,
  `setMessageStore()`, `setContextWindow()`, `resetConversation()`, `toolErrorHandler()`, `toolMaxRuns()` and
  `parallelToolCalls()`. On `RAG`: `setVectorStore()`, `setEmbeddingsProvider()`, `setRetrieval()`,
  `setRetrievalScope()`, `setPreProcessors()` and `setPostProcessors()`. Calls need no change, and a chain keeps the
  concrete type.
- **`AgentInterface` matches `Agent`.** Its setters return `static`, `addTool()` accepts a `ProviderToolInterface` as
  `setTools()` already did, and `getTools()` is documented to return toolkits and provider tools too.
- **`WorkflowInterface` declares `inspect()`.** Code typed against `WorkflowInterface` or `AgentInterface` can read
  the current run.

| Before (3.x) | After |
|---|---|
| `setAiProvider(…): AgentInterface`, `addTool(…): AgentInterface`, `parallelToolCalls(…): AgentInterface` | `: static` |
| `setInstructions(…): self` on `Agent`, `: AgentInterface` on `AgentInterface` | `: static` |
| `toolErrorHandler(…): Agent`, `toolMaxRuns(…): Agent` | `: static` |
| `setVectorStore(…)`, `setEmbeddingsProvider(…)`, `setRetrieval(…)`, `setPreProcessors(…)`, `setPostProcessors(…)` returning `RAG` | `: static` |
| `AgentInterface::addTool(ToolInterface\|ToolkitInterface\|array $tools)` | `AgentInterface::addTool(ToolInterface\|ToolkitInterface\|ProviderToolInterface\|array $tools)` |
| No `inspect()` on `WorkflowInterface` | `WorkflowInterface::inspect(): ?WorkflowRunSnapshot` |

## How to Refactor

### Case 1: Overriding a setter in your agent or RAG

PHP accepts an override of these setters only if it returns `static`: `AgentInterface`, `Agent`, `RAG` and `self`
all fail with a fatal error when the class loads.

Before:

```php
class SupportAgent extends Agent
{
    public function setAiProvider(AIProviderInterface $provider): AgentInterface
    {
        $this->audit->record('provider', $provider::class);

        return parent::setAiProvider($provider);
    }
}
```

After:

```php
class SupportAgent extends Agent
{
    public function setAiProvider(AIProviderInterface $provider): static
    {
        $this->audit->record('provider', $provider::class);

        return parent::setAiProvider($provider);
    }
}
```

### Case 2: Implementing `AgentInterface` or `WorkflowInterface` yourself

A class that implements an interface without extending `Agent` or `Workflow`, such as a decorator, returns `static`
from the setters of `AgentInterface`, accepts a `ProviderToolInterface` in `addTool()` and implements `inspect()`.

Before:

```php
public function addTool(ToolInterface|ToolkitInterface|array $tools): AgentInterface
{
    $this->agent->addTool($tools);

    return $this;
}
```

After:

```php
public function addTool(ToolInterface|ToolkitInterface|ProviderToolInterface|array $tools): static
{
    $this->agent->addTool($tools);

    return $this;
}

public function inspect(): ?WorkflowRunSnapshot
{
    return $this->agent->inspect();
}
```

## What to Search For

```
grep -rnE "function (setAiProvider|setInstructions|setTools|addTool|setMessageStore|setContextWindow|resetConversation|toolErrorHandler|toolMaxRuns|parallelToolCalls)\(" --include="*.php" .
grep -rnE "function (setVectorStore|setEmbeddingsProvider|setRetrieval|setRetrievalScope|setPreProcessors|setPostProcessors)\(" --include="*.php" .
grep -rnE "implements .*(AgentInterface|WorkflowInterface)" --include="*.php" .
```

A setter match matters only in a class that extends `Agent` or `RAG`, or implements `AgentInterface`.

## Checklist

- Setter overrides in `Agent` and `RAG` subclasses return `static`.
- Classes implementing `AgentInterface` return `static` from its setters and accept `ProviderToolInterface` in
  `addTool()`.
- Classes implementing `WorkflowInterface` or `AgentInterface` implement `inspect()`.
