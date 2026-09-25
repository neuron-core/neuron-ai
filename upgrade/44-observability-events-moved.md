# Upgrade: Observability events live in the module that emits them

## Summary

In 3.x every event class lived in `NeuronAI\Observability\Events`, whichever module emitted it. Each event now lives in
the `Observability` namespace of its module, and describes its own data.

- **Event classes moved.** `NeuronAI\Observability\Events` no longer exists. The workflow's lifecycle events are in
  `NeuronAI\Workflow\Observability`, the agent's in `NeuronAI\Agent\Observability` and the RAG's in
  `NeuronAI\RAG\Observability`. Class names don't change, except one.
- **`AgentError` is now `WorkflowError`.** The workflow reports it for a node failure in any workflow, agent or not,
  and for a listener failure it isolated. Its string name is still `error`.
- **Events describe their own data.** `ObservabilityEvent::toArray()` returns an event's own fields, and `LogListener`
  logs them as the record's context. The protected `serialize*` methods of `LogListener` and `LogObserver` are gone:
  override `context()` instead.
- **Three events are removed.** Nothing emitted `InstructionsChanging` or `InstructionsChanged`. `ToolsBootstrapped`
  is gone because the agent resolves its tools for every segment.

| Before (3.x) | After |
|---|---|
| `WorkflowStart`, `WorkflowEnd`, `WorkflowNodeStart`, `WorkflowNodeEnd`, `WorkflowInterrupted`, `MiddlewareStart`, `MiddlewareEnd`, `BranchStart`, `BranchEnd` | `NeuronAI\Workflow\Observability\…` |
| `AgentError` | `NeuronAI\Workflow\Observability\WorkflowError` |
| `InferenceStart`, `InferenceStop`, `ToolCalling`, `ToolCalled`, `MessageSaving`, `MessageSaved`, `SchemaGeneration`, `SchemaGenerated`, `Extracting`, `Extracted`, `Deserializing`, `Deserialized`, `Validating`, `Validated` | `NeuronAI\Agent\Observability\…` |
| `Retrieving`, `Retrieved`, `PreProcessing`, `PreProcessed`, `PostProcessing`, `PostProcessed` | `NeuronAI\RAG\Observability\…` |
| `InstructionsChanging`, `InstructionsChanged`, `ToolsBootstrapped` | Removed |

## How to Refactor

### Case 1: Importing an event class

Before:

```php
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\Retrieved;
use NeuronAI\Observability\Events\WorkflowEnd;
```

After:

```php
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\RAG\Observability\Retrieved;
use NeuronAI\Workflow\Observability\WorkflowEnd;
```

Fully qualified references, such as `\NeuronAI\Observability\Events\WorkflowEnd::class`, change the same way.

### Case 2: Listening for failures

Before:

```php
use NeuronAI\Observability\Events\AgentError;

$agent->subscribe(AgentError::class, function (AgentError $event): void {
    $this->reporter->report($event->exception);
});
```

After:

```php
use NeuronAI\Workflow\Observability\WorkflowError;

$agent->subscribe(WorkflowError::class, function (WorkflowError $event): void {
    $this->reporter->report($event->exception);
});
```

Rename `instanceof AgentError` checks the same way. Code that matches the name `'error'` needs no change.

### Case 3: A logger subclass overriding `serialize*`

The methods are no longer called, so an override that stays in place stops taking effect without any error. Move it
into `context()`, which receives every event before it is logged.

Before:

```php
class RedactingLogger extends LogObserver
{
    protected function serializeWithMessage(Extracting|InferenceStart|MessageSaving|MessageSaved $data): array
    {
        return ['message' => '[redacted]'];
    }
}
```

After:

```php
use NeuronAI\Agent\Observability\Extracting;
use NeuronAI\Agent\Observability\InferenceStart;
use NeuronAI\Agent\Observability\MessageSaved;
use NeuronAI\Agent\Observability\MessageSaving;
use NeuronAI\Observability\LogListener;
use NeuronAI\Observability\ObservabilityEvent;

class RedactingLogger extends LogListener
{
    protected function context(ObservabilityEvent $event): array
    {
        return match ($event::class) {
            Extracting::class, InferenceStart::class, MessageSaving::class, MessageSaved::class => ['message' => '[redacted]'],
            default => parent::context($event),
        };
    }
}
```

### Case 4: Logging a custom event's data

In 3.x `LogObserver` logged the array passed to `emit()`. A custom event class (guide 7) is logged with no data unless
it overrides `toArray()`.

Before:

```php
$this->emit('document-scored', ['document' => $document->id, 'score' => $score]);
```

After:

```php
use NeuronAI\Observability\ObservabilityEvent;

class DocumentScored extends ObservabilityEvent
{
    public function __construct(public string $documentId, public float $score)
    {
    }

    public function toArray(): array
    {
        return ['document' => $this->documentId, 'score' => $this->score];
    }
}

$this->emit(new DocumentScored($document->id, $score));
```

### Case 5: Listening for a removed event

Remove listeners for `InstructionsChanging` and `InstructionsChanged`: nothing dispatched them. Remove listeners for
`ToolsBootstrapped` as well; no event replaces it.

## What to Search For

```
grep -rn "Observability.Events" --include="*.php" .
grep -rn "AgentError" --include="*.php" .
grep -rnE "function serialize[A-Z][A-Za-z]*\(" --include="*.php" .
grep -rn "extends ObservabilityEvent" --include="*.php" .
```

A `serialize*` match matters only inside a class that extends `LogListener` or `LogObserver`.

## Checklist

- No code references `NeuronAI\Observability\Events`.
- No code references `AgentError`; failure listeners use `WorkflowError`.
- No `LogListener` or `LogObserver` subclass overrides a `serialize*` method; redaction lives in `context()`.
- Custom events whose data belongs in logs override `toArray()`.
- No code references `InstructionsChanging`, `InstructionsChanged` or `ToolsBootstrapped`.
