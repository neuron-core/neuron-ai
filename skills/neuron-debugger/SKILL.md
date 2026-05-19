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

Neuron uses a static EventBus for monitoring all framework events.

### Event Emission

All components emit events automatically:

```php
use NeuronAI\Observability\EventBus;

// Agents emit events like:
// - 'agent.chat.started'
// - 'agent.llm.inference'
// - 'agent.tool.called'
// - 'agent.response.completed'

// Workflows emit events like:
// - 'workflow.started'
// - 'workflow.node.executed'
// - 'workflow.completed'
```

### Custom Observers

```php
use NeuronAI\Observability\ObserverInterface;

class CustomObserver implements ObserverInterface
{
    public function handle(string $event, object $source, mixed $data): void
    {
        // Handle events
        echo "Event: {$event}\n";
        echo "Source: " . $source::class . "\n";
        var_dump($data);
    }
}
```

### Registering Observers

```php
use NeuronAI\Observability\EventBus;

// Register globally
EventBus::observe(new CustomObserver());

// Or register via workflow/agent
$workflow->observe(new CustomObserver());
```

### Extending InspectorObserver

Create custom observers that maintain all default tracking:

```php
use NeuronAI\Observability\InspectorObserver;

class MyInspectorObserver extends InspectorObserver
{
    protected array $methodsMap = [
        // Include parent mappings
        ...parent::$methodsMap,

        // Add custom event mappings
        'my-custom-event' => 'handleCustomEvent',
    ];

    public function handleCustomEvent(object $source, string $event, mixed $data): void
    {
        $segment = $this->inspector->addSegment('custom', $event);

        // Custom tracking logic
        $this->trackCustomMetrics($data);

        $segment->end();
    }

    private function trackCustomMetrics(mixed $data): void
    {
        // Your custom metrics
    }
}

// Register before any execution
EventBus::setDefaultObserver(MyInspectorObserver::instance());
```

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
    return (string) new SystemPrompt(
        background: [
            "You have access to database tools to query user data.",
            "Use the search_user tool when asked about users.",
        ]
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
   - Use caching for expensive operations
   - Optimize database queries
   - Use smaller/faster embedding models

```php
// Enable parallel tool execution
$agent->setConfig(['parallelToolCalls' => true]);

// Cache vector embeddings
$rag->vectorStore()->setCache($cacheAdapter);
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
    return (string) new SystemPrompt(
        background: [
            "You are a helpful assistant answering questions",
            "about our product using only the provided context.",
        ],
        constraints: [
            "Never make up information.",
            "If you don't know, say so clearly.",
            "Cite sources when possible.",
        ]
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

## Performance Monitoring

### Key Metrics to Track

**LLM Inference**:
- Time per call
- Token usage (input/output)
- Model selection impact

**Tool Execution**:
- Time per tool call
- Success/failure rate
- External API latency

**RAG Retrieval**:
- Retrieval time
- Document count
- Relevance scores

### Viewing Metrics in Inspector

1. Navigate to your dashboard
2. Click on an execution trace
3. Expand segments to see details
4. Use filters to find slow operations

### Creating Custom Performance Segments

```php
use NeuronAI\Observability\InspectorObserver;

$observer = InspectorObserver::instance();

$segment = $observer->addSegment('custom', 'my_operation');

// Your operation here
$result = $this->doSomethingExpensive();

$segment->end();

// Or add custom data
$segment->addContext([
    'items_processed' => count($result),
    'duration_ms' => $segment->getDuration(),
]);
```

## Logging

### PSR-3 Logger Integration

```php
use NeuronAI\Observability\LogObserver;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('neuron');
$logger->pushHandler(new StreamHandler('php://stdout'));

$agent->observe(new LogObserver($logger));
```

### Custom Logger

```php
use NeuronAI\Observability\ObserverInterface;

class FileLogger implements ObserverInterface
{
    private $file;

    public function __construct(string $filepath)
    {
        $this->file = fopen($filepath, 'a');
    }

    public function handle(string $event, object $source, mixed $data): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $log = "[{$timestamp}] {$event} from {$source::class}\n";
        fwrite($this->file, $log);
    }

    public function __destruct()
    {
        fclose($this->file);
    }
}
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

Access via Inspector dashboard → Errors tab.

## Workflow-Specific Debugging

### Tracing Node Execution

```php
use NeuronAI\Workflow\EventBus;
use NeuronAI\Workflow\NodeInterface;

class TracingObserver implements ObserverInterface
{
    public function handle(string $event, object $source, mixed $data): void
    {
        if ($source instanceof NodeInterface) {
            echo "Node {$source::class}: {$event}\n";
        }
    }
}

EventBus::observe(new TracingObserver());
```

### Debugging Workflow State

```php
class DebugNode extends Node
{
    public function __invoke(Event $event, WorkflowState $state): Event
    {
        // Dump current state
        error_log("State: " . json_encode($state->all()));

        // Continue execution
        return $this->process($event, $state);
    }
}
```

### Exporting Workflows for Visualization

```php
use NeuronAI\Workflow\Exporter\MermaidExporter;

$workflow->setExporter(new MermaidExporter());
$diagram = $workflow->export();

file_put_contents('workflow_diagram.mmd', $diagram);
```

## Common Debugging Commands

```bash
# View Inspector logs
tail -f storage/logs/inspector.log

# Check environment
echo $INSPECTOR_INGESTION_KEY

# Test agent with verbose mode
php bin/console neuron:chat --verbose "Your question"

# Run with debugging
export NEURON_DEBUG=1
php bin/console neuron:chat "Your question"
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

## Getting Help

If issues persist:

1. **Check Inspector** - timeline and errors
2. **Review logs** - application and framework logs
3. **Enable debug mode** - `NEURON_DEBUG=1`
4. **Create minimal reproduction** - simplify the agent
5. **Consult documentation** - https://docs.neuron-ai.dev/

## Best Practices

### Production Monitoring
- Always use Inspector in production
- Set up alerts for error rates
- Monitor latency percentiles (p95, p99)
- Track token usage for cost management

### Development Debugging
- Use custom observers for specific events
- Add tracing for complex workflows
- Export workflow diagrams for understanding
- Use mock providers for testing

### Error Handling
- Return errors in LLM-readable format
- Add context to error messages
- Log errors with sufficient detail
- Set up error rate monitoring
