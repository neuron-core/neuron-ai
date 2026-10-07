---
name: neuron-monitoring
description: Monitor Neuron AI agents and workflows with events observability, logging, and performance analysis. Use this skill whenever the user mentions debugging, monitoring, observability, performance analysis, tracing, or needs to understand why an agent is behaving a certain way. Also trigger for tasks involving agent execution timeline, tool call inspection, latency problems, or general troubleshooting of Neuron AI applications.
---

# Neuron AI Monitoring

This skill helps you debug and monitor Neuron AI applications using the
framework's event-driven observability system.

The progression is always the same: components emit events → you subscribe
listeners. A `LogListener` writing to stdout and a custom tracing listener
are the same mechanism, differing only in where the events go.

## Event System Observability

Neuron uses a **PSR-14 event dispatcher owned by each Workflow/Agent instance**
for monitoring all framework events. There is no global state: listeners are
registered on the instance and observe every run of it.

### Event Emission

All components emit event objects automatically. Every event class extends
`NeuronAI\Observability\ObservabilityEvent` and lives in the `Observability`
namespace of the module that emits it:

```php
// NeuronAI\Workflow\Observability — the lifecycle, emitted by the workflow engine:
// WorkflowStart, WorkflowEnd, WorkflowNodeStart, WorkflowNodeEnd,
// MiddlewareStart, MiddlewareEnd, BranchStart, BranchEnd, ChannelError,
// WorkflowInterrupted (run paused for external input), WorkflowError (failure)
//
// NeuronAI\Agent\Observability — emitted by agent nodes:
// InferenceStart, InferenceStop, ToolCalling, ToolCalled,
// MessageSaving, MessageSaved, SchemaGeneration, SchemaGenerated,
// Extracting, Extracted, Deserializing, Deserialized, Validating, Validated
//
// NeuronAI\RAG\Observability — emitted by RAG nodes:
// Retrieving, Retrieved, PreProcessing, PreProcessed, PostProcessing, PostProcessed
```

Each event carries its own data plus the emission context stamped by the
framework: `->source` (the emitting component), `->execution` (the run identity)
and `->branchId` (the parallel branch, or null). `->name()` returns the string
name ('inference-start') and `->toArray()` the event's own data.

Every node and middleware start is followed by its end, whatever the outcome:
`WorkflowNodeEnd` and `MiddlewareEnd` carry `->outcome`, a `NodeOutcome`
(`Completed`, `Suspended` or `Failed`), so a span can be closed and marked as
soon as its node ends.

### Subscribing Listeners

Listeners are class-keyed with instanceof matching — subscribe to a specific
event class, or to `ObservabilityEvent::class` to receive everything:

```php
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Observability\ObservabilityEvent;

// React to one event type
$agent->subscribe(InferenceStop::class, function (InferenceStop $event): void {
    echo "Inference finished\n";
});

// Catch-all firehose
$agent->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event): void {
    echo "Event: {$event->name()}\n";
    echo "Source: " . ($event->source !== null ? $event->source::class : 'n/a') . "\n";
});
```

### Custom Events

Emit your own events from nodes with `Node::emit()` — the event object is the
payload. Subclass `ObservabilityEvent` to get `source`/`branchId` stamped, and
override `toArray()` so `LogListener` records the event's data:

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

// Inside a node
$this->emit(new DocumentScored($doc->id, $score));

// Anywhere
$workflow->subscribe(DocumentScored::class, fn (DocumentScored $e) => $metrics->gauge('score', $e->score));
```

### Integrating a Host Framework

Forward every event to an application-wide PSR-14 dispatcher (Symfony's
EventDispatcher, League\Event) — Neuron events become regular application
events:

```php
$agent->setEventDispatcher($appEventDispatcher);
```

Laravel's dispatcher is not PSR-14: it needs a small adapter, shown in the
**neuron-laravel-integration** skill. Framework dispatchers match listeners by
exact class, so a listener on `ObservabilityEvent` never fires there; listen to
concrete event classes, or use `subscribe(ObservabilityEvent::class, ...)`.

### Legacy Observers (deprecated)

`ObserverInterface` and `$workflow->observe()` still work through an internal
adapter but are deprecated and will be removed in the next major. Migrate
observers to listeners registered via `subscribe()`.

## Common Debugging Scenarios

### Agent Not Using Tools

**Symptoms**: Agent ignores available tools

**Diagnosis Steps**:

1. Check the run trace (`LogListener` output) - are tool calls being made?
2. Verify tool descriptions are clear and specific
3. Check if tool properties are correctly defined
4. Review agent instructions - are tools mentioned?

```php
// Add explicit tool instruction
protected function instructions(): string
{
    return new SystemMessage(
        "You have access to database tools to query user data. ".
        "Use the search_user tool when asked about users."
    );
}
```

### Workflow interruptions and Agent continuations

A suspended execution segment emits one `WorkflowInterrupted`, carrying
`$event->state->getInterruptRequest()`, followed by `WorkflowEnd`. Parallel branch
requests are reported sequentially as each becomes current. Reading an unchanged
wait without execution does not emit another interruption.

`submitApprovalDecisions($decisions)` and `submitToolResults($results)` stage input;
the following `run()` or `events()` emits the normal execution lifecycle. Use the
status on `WorkflowEnd::state` to distinguish `suspended`, `completed` and `failed`.
A `WorkflowError` may report a listener error that the workflow isolated, so count
failed runs from their terminal status rather than from error events alone.

The default interruption and end logs include `workflowId`, `runId`,
`executionAttempt` and `status`. An interruption log has one `interrupt` object;
end logs keep application data under `state`. Correlate these records by run and
attempt. `WorkflowStart` listeners see the current attempt's running state.

For deferred tools, `ToolCalling` marks handoff after approval and `ToolCalled`
reports results once the waiting batch is settled. This pair may span several
segments and includes frontend waiting time; partial result submissions do not
redispatch completed tool calls. Execution events can repeat after a failed step
is replayed, so they are not an exactly-once audit trail.

### Slow Agent Responses

**Symptoms**: High latency, slow responses

**Diagnosis Steps**:

1. **Check the trace timeline** - identify slow segments (`InferenceStart`/`InferenceStop` and `ToolCalling`/`ToolCalled` pairs bracket each operation)
2. **Common bottlenecks**:
   - LLM inference: Check model choice, token count
   - Tool execution: Database queries, API calls
   - Vector search: Large collections, slow embeddings

3. **Optimization strategies**:
   - Enable parallel tool calls
   - Optimize database queries
   - Use smaller/faster embedding models

```php
// Enable parallel tool execution
$agent->parallelToolCalls(true);
```

### Slow or failing conversation memory

Conversation retrieval uses RAG's `Retrieving` / `Retrieved` events. Observe `WorkflowNodeStart` / `WorkflowNodeEnd` for `ConversationIngestionNode` to measure optional ingestion. A failed step emits `WorkflowError`. These use the existing retrieval and workflow monitoring paths; there are no separate memory events.

### Poor Response Quality

**Symptoms**: Hallucinations, irrelevant responses

**Diagnosis Steps**:

1. **Check the trace** - what context was provided to the LLM?
2. **Review LLM calls**:
   - System prompt quality
   - Context window usage
   - RAG retrieval results

3. **Common fixes**:
   - Improve system prompt
   - Increase RAG k value (retrieve more documents)
   - Add reranking
   - Use better embedding models

```php
// Improve retrieval quality
$rag->setPostProcessors([
    new CohereRerankerPostProcessor(key: $apiKey, topN: 5),
]);

// Better system prompt
protected function instructions(): string
{
    return new SystemMessage(
        "You are a helpful assistant answering questions ".
        "about our product using only the provided context. ".
        "Never make up information. If you don't know, say so clearly. ".
        "Cite sources when possible."
    );
}
```

### Tools Not Executing

**Symptoms**: Agent tries to call tools but fails

**Diagnosis Steps**:

1. **Check the run trace** - see error details (`WorkflowError` events carry the exception)
2. **Verify tool configuration**:
   - Property types match
   - Required parameters provided
   - Dependencies injected

3. **Add error handling**:

```php
class MyTool extends Tool
{
    public function execute(array $arguments): mixed
    {
        try {
            // Tool logic
            return $result;
        } catch (\Exception $e) {
            // Return error in a format the LLM can understand
            return [
                'error' => $e->getMessage(),
                'type' => get_class($e),
                'hint' => 'Check your parameters and try again.',
            ];
        }
    }
}
```

## Logging

### PSR-3 Logger Integration

`LogListener` logs every event's name with the event's `toArray()` as context
(override its protected `context()` method to redact or enrich it):

```php
use NeuronAI\Observability\LogListener;
use NeuronAI\Observability\ObservabilityEvent;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('neuron');
$logger->pushHandler(new StreamHandler('php://stdout'));

$agent->subscribe(ObservabilityEvent::class, new LogListener($logger));
```

(`LogObserver` registered via `observe()` is the deprecated equivalent.)

### Custom Logger

Any callable works as a listener:

```php
use NeuronAI\Observability\ObservabilityEvent;

$file = fopen($filepath, 'a');

$agent->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event) use ($file): void {
    $timestamp = date('Y-m-d H:i:s');
    $source = $event->source !== null ? $event->source::class : 'n/a';
    fwrite($file, "[{$timestamp}] {$event->name()} from {$source}\n");
});
```

## Testing Debugging Scenarios

### Test Response with Mock Provider

```php
use PHPUnit\Framework\TestCase;

class AgentTest extends TestCase
{
    public function testAgentResponseQuality(): void
    {
        // Use fake provider for deterministic testing
        $agent = new MyAgent(new FakeAIProvider(
            new AssistantMessage('Helpful answer here')
        ));

        $response = $agent->setThreadId('test-thread')->chat(
            new UserMessage('Test question')
        )->getMessage();

        $this->assertStringContainsString('key information', $response->getContent());
    }
}
```

### Test Tool Execution

```php
public function testToolExecution(): void
{
    $tool = new MyTool();
    $result = $tool->execute(['param' => 'value']);

    $this->assertIsArray($result);
    $this->assertArrayHasKey('result', $result);
}
```

## Production Error Analysis

Subscribe to the `WorkflowError` event to capture failures as they happen.

### Tracing Node Execution

```php
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\NodeInterface;

$workflow->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event): void {
    if ($event->source instanceof NodeInterface) {
        echo "Node " . $event->source::class . ": {$event->name()}\n";
    }
});
```

### Exporting Workflows for Visualization

```php
use NeuronAI\Workflow\Exporter\MermaidExporter;

$workflow->setExporter(new MermaidExporter());
$diagram = $workflow->export();

file_put_contents('workflow_diagram.mmd', $diagram);
```

## Debugging Checklist

When troubleshooting:

- [ ] Is an observability listener attached (`LogListener`, or your own)?
- [ ] Are errors shown in the run trace?
- [ ] What was the LLM prompt and response?
- [ ] Were tools called, and what were the results?
- [ ] Is the response quality poor or execution failing?
- [ ] Check logs for additional context
- [ ] Verify tool property types match what was sent
- [ ] For RAG, check retrieved documents and scores
- [ ] Consider subscribing a custom listener for specific events
