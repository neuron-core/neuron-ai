---
name: neuron-debugger
description: Debug and monitor Neuron AI applications with Inspector APM, event observability, logging, and performance analysis. Use this skill whenever the user mentions debugging, monitoring, observability, performance analysis, tracing, Inspector, or needs to understand why an agent is behaving a certain way. Also trigger for tasks involving agent execution timeline, tool call inspection, response quality issues, latency problems, or general troubleshooting of Neuron AI applications.
---

# Neuron AI Debugger

This skill helps you debug and monitor Neuron AI applications using Inspector APM and the framework's observability system.

## Inspector APM Integration

Inspector provides deep insights into agent execution, helping you understand:

- Why the model took certain decisions
- What data the model reacted to
- Timeline of all operations (LLM calls, tool usage, vector search)
- Performance bottlenecks and latency

### Setup

1. **Get an Inspector account** at https://inspector.dev
2. **Set the ingestion key** in your environment:

```bash
# .env file
INSPECTOR_INGESTION_KEY=your_ingestion_key_here
```

3. **Agent execution is automatically tracked** - no code changes needed!

### Viewing Execution Timeline

After running an agent, visit your Inspector dashboard to see:

```
[AI Inference] → [Tool Call: search_database] → [AI Inference] → [Response]
     ↓                    ↓                        ↓
  850ms               1.2s                     920ms
```

Each segment shows:
- Duration and timing
- Input/output data
- Errors if any occurred
- Metadata (model used, tokens, etc.)

## Event System Observability

Neuron uses a **PSR-14 event dispatcher owned by each Workflow/Agent instance**
for monitoring all framework events. There is no global state: listeners are
registered on the instance and observe every run of it.

### Event Emission

All components emit event objects automatically. Every event class lives in
`NeuronAI\Observability\Events` and extends
`NeuronAI\Observability\ObservabilityEvent`:

```php
// Lifecycle (emitted by the executor):
// WorkflowStart, WorkflowEnd, WorkflowNodeStart, WorkflowNodeEnd,
// MiddlewareStart, MiddlewareEnd, BranchStart, BranchEnd, AgentError
//
// Domain (emitted by nodes):
// InferenceStart, InferenceStop, ToolCalling, ToolCalled,
// MessageSaving, MessageSaved, Retrieving, Retrieved,
// PreProcessing, PreProcessed, PostProcessing, PostProcessed,
// SchemaGeneration, SchemaGenerated, Extracting, Extracted, ...
```

Each event carries its own data plus the emission context stamped by the
framework: `->source` (the emitting component) and `->branchId` (the parallel
branch, or null). `->name()` returns the string name ('inference-start').

### Subscribing Listeners

Listeners are class-keyed with instanceof matching — subscribe to a specific
event class, or to `ObservabilityEvent::class` to receive everything:

```php
use NeuronAI\Observability\Events\InferenceStop;
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
payload. Subclass `ObservabilityEvent` to get `source`/`branchId` stamped:

```php
use NeuronAI\Observability\ObservabilityEvent;

class DocumentScored extends ObservabilityEvent
{
    public function __construct(public string $documentId, public float $score)
    {
    }
}

// Inside a node
$this->emit(new DocumentScored($doc->id, $score));

// Anywhere
$workflow->subscribe(DocumentScored::class, fn (DocumentScored $e) => $metrics->gauge('score', $e->score));
```

### Integrating a Host Framework

Forward every event to an application-wide PSR-14 dispatcher (Symfony,
Laravel's PSR bridge, League\Event) — Neuron events become regular application
events:

```php
$agent->setEventDispatcher($appEventDispatcher);
```

### Legacy Observers (deprecated)

`ObserverInterface` and `$workflow->observe()` still work through an internal
adapter but are deprecated and will be removed in the next major. Migrate
observers to listeners registered via `subscribe()`.

## Common Debugging Scenarios

### Agent Not Using Tools

**Symptoms**: Agent ignores available tools

**Diagnosis Steps**:

1. Check Inspector timeline - are tool calls being made?
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

### Slow Agent Responses

**Symptoms**: High latency, slow responses

**Diagnosis Steps**:

1. **Check Inspector timeline** - identify slow segments
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

### Poor Response Quality

**Symptoms**: Hallucinations, irrelevant responses

**Diagnosis Steps**:

1. **Check Inspector** - what context was provided?
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
$rag->addPostProcessor(new RerankProcessor(
    reranker: new CohereReranker($apiKey),
    topK: 5
));

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

1. **Check Inspector timeline** - see error details
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

`LogListener` logs every event's name with per-event-class serialized context
(override its protected `serialize*` methods to customize):

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
        $agent = new MyAgent(new FakeAIProvider([
            'expected_response' => 'Helpful answer here'
        ]));

        $response = $agent->chat(
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

Inspector provides detailed error analysis:

1. **Error Summary**: Frequency, severity, affected code
2. **Stack Traces**: Full call chain with framework code
3. **Context**: Input data, state at time of error
4. **Patterns**: Repeated issues, common failure modes

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

- [ ] Is Inspector configured with valid ingestion key?
- [ ] Can you see the execution in Inspector dashboard?
- [ ] Are errors shown in the timeline?
- [ ] What was the LLM prompt and response?
- [ ] Were tools called, and what were the results?
- [ ] Is the response quality poor or execution failing?
- [ ] Check logs for additional context
- [ ] Verify tool property types match what was sent
- [ ] For RAG, check retrieved documents and scores
- [ ] Consider adding a custom observer for specific events
