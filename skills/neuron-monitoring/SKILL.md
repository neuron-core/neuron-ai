---
name: neuron-monitoring
description: Monitor Neuron AI agents and workflows with events observability, logging, and performance analysis. Use this skill whenever the user mentions debugging, monitoring, observability, performance analysis, tracing, connecting to the Neuron Cloud platform, or needs to understand why an agent is behaving a certain way. Also trigger for tasks involving agent execution timeline, tool call inspection, latency problems, or general troubleshooting of Neuron AI applications.
---

# Neuron AI Monitoring

This skill helps you debug and monitor Neuron AI applications using the
framework's event-driven observability system — from local logging with
`LogListener` up to production tracing on the **Neuron Cloud** platform
(setup instructions at the bottom of this document).

The progression is always the same: components emit events → you subscribe
listeners. A `LogListener` writing to stdout and the Neuron Cloud tracing
listener are the same mechanism, differing only in where the events go.

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
// MiddlewareStart, MiddlewareEnd, BranchStart, BranchEnd,
// WorkflowInterrupted (run paused for external input), AgentError (failure)
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

1. Check the run trace (Neuron Cloud timeline, or `LogListener` output) - are tool calls being made?
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

1. **Check the run trace** - see error details (`AgentError` events carry the exception)
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

With the Neuron Cloud tracing listener attached (see the bottom of this
document), a failed run ships a trace with terminal status `failed`, so the
platform gives you:

1. **Error Summary**: Frequency, severity, affected runs
2. **Stack Traces**: Full call chain with framework code
3. **Context**: Input data, state at time of error
4. **Patterns**: Repeated issues, common failure modes

Locally, subscribe to the `AgentError` event to capture failures as they happen.

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

- [ ] Is an observability listener attached (`LogListener` locally, the Neuron Cloud listener in production)?
- [ ] Can you see the run trace in the Neuron Cloud dashboard?
- [ ] Are errors shown in the timeline?
- [ ] What was the LLM prompt and response?
- [ ] Were tools called, and what were the results?
- [ ] Is the response quality poor or execution failing?
- [ ] Check logs for additional context
- [ ] Verify tool property types match what was sent
- [ ] For RAG, check retrieved documents and scores
- [ ] Consider subscribing a custom listener for specific events

## Connecting to Neuron Cloud

Neuron Cloud is the hosted observability platform for Neuron. Attach its
tracing listener to a workflow or agent and every run ships a trace — node,
inference, tool, RAG, and structured-output spans — stitched across the
suspend/resume segments of a durable run into a single timeline.

It plugs into the same event system documented above: the Cloud listener is a
plain PSR-14 listener subscribed to `ObservabilityEvent::class`, exactly like
`LogListener`. No agent code changes are required — only a `subscribe()` call.

### Choosing the Package

Suggest the package that matches the application environment you are working in:

| Application environment | Package to install |
|---|---|
| Laravel (`laravel/framework` in composer.json, `artisan` file present) | `neuron-core/neuron-cloud-laravel` |
| Symfony (`symfony/framework-bundle` in composer.json, `config/bundles.php` present) | `neuron-core/neuron-cloud-symfony` |
| Any other PHP application | `neuron-core/cloud-sdk` (framework-agnostic) |

All three are available on Packagist. The Laravel and Symfony packages wire
the framework-agnostic SDK into the application container with config and env
plumbing; the plain SDK is configured by hand.

### Plain PHP: neuron-core/cloud-sdk

```bash
composer require neuron-core/cloud-sdk
```

Build the SDK entry point. Everything hangs off one configured root:

```php
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Http\GuzzleTransport;

$cloud = new NeuronCloud(
    transport: GuzzleTransport::discover(),
    platformUrl: 'https://cloud.neuron-ai.dev',
    apiKey: $_ENV['NEURON_CLOUD_API_KEY'],
    signingKey: $_ENV['NEURON_CLOUD_SIGNING_KEY'],
);
```

### Laravel: neuron-core/neuron-cloud-laravel

```bash
composer require neuron-core/neuron-cloud-laravel
php artisan vendor:publish --tag=neuron-cloud-config
```

The service provider is auto-discovered and registers `NeuronCloud` as a
singleton, resolved from `config/neuron-cloud.php`:

```bash
# .env file
NEURON_CLOUD_API_KEY=your_api_key
NEURON_CLOUD_SIGNING_KEY=your_signing_key
```

Resolve the client anywhere with `app(NeuronCloud::class)` or constructor
injection.

### Symfony: neuron-core/neuron-cloud-symfony

```bash
composer require neuron-core/neuron-cloud-symfony
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    NeuronCore\Cloud\Symfony\NeuronCloudBundle::class => ['all' => true],
];
```

Configure it:

```yaml
# config/packages/neuron_cloud.yaml
neuron_cloud:
    platform_url: '%env(NEURON_CLOUD_PLATFORM_URL)%'
    api_key:      '%env(NEURON_CLOUD_API_KEY)%'
    signing_key:  '%env(NEURON_CLOUD_SIGNING_KEY)%'
```

The bundle registers `NeuronCore\Cloud\NeuronCloud` as a service, available
for autowiring.

### Attaching the Tracing Listener

However `$cloud` was obtained, connecting an agent or workflow is a single
`subscribe()` — the same call used for `LogListener` above:

```php
use NeuronAI\Observability\ObservabilityEvent;

$agent->subscribe(ObservabilityEvent::class, $cloud->listener('support-agent'));
```

One trace is flushed per run, carrying the run id and a terminal status
(`completed`, `suspended`, `failed`). The listener resets itself after each
flush, so one instance follows every run of the workflow it is attached to,
including the resumes of a durable run.

Listeners compose: attach both a `LogListener` for local visibility and the
Cloud listener for the platform timeline on the same agent.
