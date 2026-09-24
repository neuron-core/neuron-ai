---
name: neuron-agent
description: Create, configure, and extend Neuron AI agents in PHP using providers, tools, chat history, memory, and custom workflow nodes. Use for Neuron Agent implementation, configuration, conversation lifecycle, and input/output extensions.
---

# Neuron AI Agent

`Agent` is a composition built on `Workflow`. Extend it when its defaults fit, use its entry/exit hooks for additional stages, or compose a Workflow from the standalone components when control flow differs substantially. This skill covers the current major-version APIs; use the implementation's class names and signatures rather than older examples.

Use `Agent::submitApprovalDecisions($decisions)` for tool approval and `Agent::submitToolResults($results)` for deferred tool results. Both accept maps keyed by tool call ID and stage a continuation; finish with `run()` for an `AgentState` or `events()` for a stream.

## Core Agent Structure

A Neuron agent extends the `Agent` class and implements key methods:

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;

class MyAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: $_ENV['ANTHROPIC_API_KEY'],
            model: $_ENV['ANTHROPIC_MODEL'],
        );
    }

    protected function instructions(): SystemMessage|string
    {
        return new SystemMessage("You are a helpful AI assistant.");
    }
}
```

## Workflow Boundaries and Custom Nodes

The default path is:

```text
AgentStartEvent → AgentStartNode → inference ⇄ tools
    final response → AgentOutputEvent → AgentEndNode → StopEvent
```

`ChatNode` handles chat and streaming; `StructuredOutputNode` handles structured inference. Tools may pause for approval or deferred results before returning to inference. `AgentOutputEvent` means the final answer is available for output processing; it is not a terminal event.

- `nodes()` rebuilds the graph from the current configuration each execution segment. `entryNodes()` defaults to `AgentStartNode`; `exitNodes()` defaults to `AgentEndNode`.
- A node's `__invoke(EventType $event, AgentState $state)` receives one exact routed event type. `addNode()` registers a handler; array order does not connect nodes, and duplicate handlers are rejected.
- For preprocessing, override `startEvent()` with an `AgentStartEvent` subclass, register a node for it in `entryNodes()`, and return the original `AgentStartEvent` to retain `parent::entryNodes()`. This keeps request initialization and tool-run resets in `AgentStartNode`. Replacing that initialization entirely requires handling those responsibilities yourself.
- For postprocessing, override `exitNodes()` with a node accepting `AgentOutputEvent`. Return an application event to continue through another node, or `StopEvent` to finish. Replace the default ending: including `parent::exitNodes()` alongside another output handler creates a duplicate.
- `AgentState::$request` holds instructions, messages, tools, and run options. Events route execution; collaborators such as providers and history remain node dependencies. `getMessage()` reads the provider response, while extra artifacts can use state keys.

For example, an Agent subclass can own speech synthesis without replacing inference nodes:

```php
protected function exitNodes(\NeuronAI\Workflow\WorkflowExecution $execution): array
{
    return [new TextToSpeechNode($this->textToSpeech())];
}
```

Read [references/workflow-extension.md](references/workflow-extension.md) when implementing custom output nodes. It contains the complete TTS node, protected provider hook, and an executable example with fake providers, plus input-extension and recovery considerations.

Agent provider, history, instructions, tools and tool-execution settings may change while streaming. Changes configure subsequent segments; the active segment retains its resolved resources, graph and conversation address. Resume still restores recorded input and instructions.

Provider/toolkit hooks are lazy; explicit setters take precedence over their corresponding default hooks. Construct graph collaborators in the hooks so reconstructed runs receive live dependencies. Some fluent setters return `AgentInterface` or `Agent`; keep the concrete instance in a separate variable when static analysis needs its Workflow methods or subclass members.

## Agent Execution Methods

Each verb returns the type its nature produces — the same eager/lazy split a plain
Workflow uses:

| Method | Returns |
|--------|---------|
| `chat($messages)` | `AgentState` — starts a new run and consumes it eagerly |
| `stream($messages)` | `Generator` — starts a new run; `getReturn()` is the `AgentState` |
| `structured($messages, $class)` | The typed output — starts a new run and consumes it eagerly |
| `run()` | `AgentState` — inherited eager Workflow terminal |
| `events()` | `Generator` — inherited pull-stream Workflow terminal; `getReturn()` is the `AgentState` |
| `submitApprovalDecisions($decisions)` | Stages tool approval decisions keyed by call ID; finish with `run()` or `events()` |
| `submitToolResults($results)` | Stages deferred tool results keyed by call ID; finish with `run()` or `events()` |

`chat()` runs eagerly and returns the final state directly (no separate `->run()` step).
Read the assistant message off it with `getMessage()`, and read an approval pause with
`isInterrupted()` / `getInterruptRequest()` — the same surface a plain `WorkflowState`
exposes.

The inherited `run()` / `events()` terminals execute staged intent. Without a
staged operation they start or recover a failed run. `resume($payload, ...)` stages
an explicit continuation. Agent new-turn methods select a fresh execution internally. `resume()->run()` is an inputless continuation for due timers
or crash recovery. To recover a failed turn, call `run()` or `events()`;
calling `chat()` instead explicitly starts a new turn and supersedes it. Agent tool approval accepts decisions keyed by tool call ID through
`submitApprovalDecisions($decisions)->run()` or
`submitApprovalDecisions($decisions)->events()`. Deferred tool results use
`submitToolResults($results)->run()` or `submitToolResults($results)->events()`.
Neither method starts a new user turn.

`Agent` specializes the generic `Workflow<AgentState>` contract, so inherited
`run()`, `events()`, and configured `setState()` seeds retain the concrete
`AgentState` type without Agent forwarding methods or local type assertions.

### Chat Mode (Synchronous)

For standard back-and-forth conversations:

```php
$agent = MyAgent::make();

$response = $agent->chat(
    new UserMessage("Hello!")
)->getMessage();

echo $response->getContent();
```

### Stream Mode (Real-time)

`stream()` returns a `Generator` that yields live output as the run progresses: provider
chunks (`TextChunk`, `ReasoningChunk`, `ToolCallChunk`, ...) plus portable progress
events yielded by workflow nodes. Read the final `AgentState` from `getReturn()`.

```php
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;

$stream = $agent->stream(new UserMessage("Hello"));

foreach ($stream as $item) {
    if ($item instanceof TextChunk) {
        echo $item->content;
    }
}

$state = $stream->getReturn();
```

To shape the output for a UI protocol (Vercel AI SDK, AG-UI) attach an adapter with
`setStreamAdapter()`; to push output to a websocket or queue consumer instead of the
HTTP response attach a channel with `setChannel()`. Both are covered in full by the
**neuron-streaming** skill.

### Structured Output Mode

For extracting structured data from a natural language:

```php
use NeuronAI\StructuredOutput\SchemaProperty;

class Person
{
    #[SchemaProperty(description: 'The user name', required: true)]
    public string $name;

    #[SchemaProperty(description: 'What the user loves to eat')]
    public string $preference;
}

$person = $agent->structured(
    new UserMessage("I'm John and I like pizza!"),
    Person::class
);
```

## Providers

`provider()` returns any `AIProviderInterface`; `setAiProvider()` on an instance takes precedence over the hook. Key-based vendors share the shape `new OpenAI(key: $_ENV['OPENAI_API_KEY'], model: $_ENV['OPENAI_MODEL'])` under their own class; Ollama takes a `url` instead of a key. Shipped: Anthropic, OpenAI (Chat Completions and Responses API), Azure OpenAI, Gemini, Anthropic and Gemini on Vertex AI, AWS Bedrock, Mistral, Ollama, Cohere, Deepseek, Grok, HuggingFace, ZAI, Alibaba DashScope, any OpenAI-compatible endpoint through `OpenAILike`, and speech-to-text, text-to-speech, and image providers behind the same interface.

Read [references/providers.md](references/providers.md) when the user names a vendor other than Anthropic or OpenAI, needs vendor request parameters (thinking, temperature, response options), a self-hosted or OpenAI-compatible endpoint, cloud-platform credentials, a custom HTTP client, or a speech or image provider. It lists every class with its namespace and constructor arguments.

## Tools Integration

### Adding Built-in Toolkits

```php
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;

protected function tools(): array
{
    return [
        FileSystemToolkit::make(scope: '/srv/agent-workspace'),
        CalendarToolkit::make(),
        CalculatorToolkit::make(),
    ];
}
```

`FileSystemToolkit` is the exact class spelling and namespace. The scope directory must already exist when the tools are built; provision the example directory or supply an existing application workspace. `scope` confines file-tool paths to a directory; `null` leaves them unrestricted. `BashTool` validates its working directory but cannot confine the command itself. Configure process isolation when shell confinement is required, or exclude `BashTool` when shell execution is not needed.

Toolkits contribute their `guidelines()` to Agent instructions. `only()` and `exclude()` take arrays of tool class names; `with()` customizes a provided tool instance:

```php
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use NeuronAI\Tools\Toolkits\FileSystem\DeleteFileTool;

$files = FileSystemToolkit::make(scope: '/srv/agent-workspace')
    ->exclude([BashTool::class])
    ->with(DeleteFileTool::class, fn (DeleteFileTool $tool): ToolInterface => $tool->requireApproval());
```

### Available Toolkits

- **FileSystemToolkit** — read, write, edit, delete, glob, grep, parse documents, and execute shell commands.
- **CalendarToolkit** — date/time formatting, arithmetic, comparisons, period boundaries, and timezone conversion. It does not connect to a calendar service or schedule workflow wakeups.
- **CalculatorToolkit** — expression evaluation, exact integer arithmetic, and statistics. Exact integer tools require `ext-bcmath`.
- **MySQLToolkit** / **PGSQLToolkit** — schema inspection, selects, and writes for MySQL/PostgreSQL.
- **TavilyToolkit** — web search, extraction, and crawling.
- **JinaToolkit** — web search and URL reading; reranking is a separate RAG component.
- **SupadataYouTubeToolkit** — video metadata/transcripts and channel/playlist lookup.
- **ZepLongTermMemoryToolkit** — Zep graph search and ingestion exposed as tools; available independently of RAG conversation retrieval.

`NeuronAI\Tools\Toolkits\AWS\SESTool` is a standalone email tool, not a `SESToolkit`.

### Creating Custom Tools

Use **neuron-tool** for full tool schemas, deferred execution, and multimodal results. Define tool metadata as properties; the base `Tool` has no constructor:

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

class WeatherTool extends Tool
{
    protected string $name = 'get_weather';

    protected ?string $description = 'Get the current weather for a location';

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'location',
                type: PropertyType::STRING,
                description: 'The city name',
                required: true,
            ),
        ];
    }

    public function __invoke(string $location): string
    {
        // Call weather API and return result
        return "The weather in {$location} is sunny, 72°F";
    }
}
```

## Agent Instructions

Agent instructions are a `SystemMessage` (`instructions()` returns `SystemMessage|string` — a plain string is wrapped automatically). A `SystemMessage` carries one or more `SystemContent` blocks; mark a block with `->cache()` to enable provider prompt caching on it:

```php
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;

protected function instructions(): SystemMessage|string
{
    return new SystemMessage([
        // Large static context: cache it to reduce cost and latency.
        (new SystemContent("You are a data analyst expert in creating reports. ..."))->cache(),
        // Dynamic part, left uncached.
        new SystemContent("Today is " . date('Y-m-d')),
    ]);
}
```

`SystemMessage::cache()` marks all of the message's blocks as cached at once.

Instructions can also be set fluently:

```php
$agent->setInstructions('You are a helpful assistant.');
```

## Chat History & Thread Identity

Conversations are stored through a **message store**, and the Agent opens a working history over it for every execution segment. Declare the conversation identity with `make(workflowId:)` or `setThreadId()`, and give the Agent a store:

```php
use NeuronAI\Chat\History\SQLMessageStore;

$agent = MyAgent::make(workflowId: $threadId);
$agent->setMessageStore(new SQLMessageStore($pdo));
$state = $agent->chat(new UserMessage($input));
```

A store names the thread on every call and keeps no conversation state, so one instance can serve the whole application: bind it once in the container, or return it from the `messageStore()` hook. `getThreadId(): ?string` reads the resolved conversation identity; an Agent without one generates it at its first execution.

Size the conversation sent to the model to the provider's model with the `contextWindow()` hook (50,000 tokens by default), or with `setContextWindow()` when the Agent is configured from outside:

```php
protected function contextWindow(): int
{
    return 190_000;
}

// Or, without a subclass:
$agent->setContextWindow(190_000);
```

`getChatHistory()->getMessages()` returns the model context. To render a whole conversation, read its transcript from the store; message IDs are stable, so they serve as UI keys and page cursors:

```php
$page = $store->loadAll($threadId, limit: 50);
$older = $store->loadAll($threadId, limit: 50, before: $page[0]->getId());
```

The default in-memory store lives as long as the Agent instance. For later-process continuation, declare the thread before ignition and configure a durable store and workflow persistence.

### Conversation memory

Conversation memory is a RAG composition: `SemanticMemoryRetrieval` accepts the authorized thread IDs and builds its filters; `CompositeRetrieval` combines it with ordinary document retrieval. Attach `ConversationIngestionNode` through `exitNodes()` to create conversation documents after a completed response. Creation and recall are independent opt-ins.

See [conversation memory](references/conversation-memory.md) for complete retrieval, ingestion, recovery and deletion examples. `resetConversation()` clears the working history and pending execution; delete conversation documents explicitly through the vector store.

### Message Stores
- `InMemoryMessageStore` - Default, process memory
- `FileMessageStore` - One JSON file per thread
- `SQLMessageStore` - Database-backed (PDO)
- `EloquentMessageStore` - Laravel Eloquent integration

## Content Blocks (Multi-modal)

Agents support multiple content types:

```php
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Enums\MediaType;
use NeuronAI\Chat\Enums\SourceType;

$message = new UserMessage([
    new TextContent('Analyze this image:'),
    new ImageContent(
        content: 'https://example.com/image.jpg',
        sourceType: SourceType::URL,
        mediaType: MediaType::JPEG
    ),
]);
```

Use the `MediaType` enum for common MIME types (images, documents, audio, video). The `mediaType` parameter also accepts a plain string (`'image/x-custom'`) for types not covered by the enum.

## CLI Generation

Use the Neuron CLI to generate an agent boilerplate:

```bash
vendor/bin/neuron make:agent MyCustomAgent
```

## Common Patterns

### Tool Approval
Human oversight of tool execution is built into `ToolNode` — nothing to attach.
Each tool declares its own risk via the protected `approvalPolicy(): bool|string`
hook (a string counts as `true` and doubles as the approval reason shown to the approver),
and you override the declaration per tool where you attach it:

```php
protected function tools(): array
{
    return [
        DeleteFileTool::make()->requireApproval(),        // force the gate
        RiskyThirdPartyTool::make()->suppressApproval(),  // waive a declared gate
    ];
}
```

Use the **neuron-tool-approval** skill for the complete flow: rendering the approve/deny
UI from chat history, submitting decisions, and building a single endpoint that handles
both conversation turns and approval resumes.

### Observability

Subscribe through the PSR-14 event system; a `LogListener` adapts an application-provided PSR-3 logger:

```php
use NeuronAI\Observability\LogListener;
use NeuronAI\Observability\ObservabilityEvent;

$agent->subscribe(ObservabilityEvent::class, new LogListener($logger));
```

Use **neuron-monitoring** for event selection, external dispatchers, and Neuron Cloud integration.

### Parallel Tool Calls
Execute local tools in parallel (requires `pcntl` and `spatie/fork`):

```php
$agent->parallelToolCalls(true);
```

Each child process opens its own HTTP connections. Use the `beforeChild` and `afterChild` callbacks for the application's own process-bound resources, such as database connections.

## Persistence and Durability

Agent is built on Workflow, so it inherits the same persistence system. Enable persistence to make agent executions **survive crashes** and **continue after interruptions** (e.g., a tool-approval suspension).

```php
use NeuronAI\Workflow\Persistence\FilePersistence;

$response = MyAgent::make()
    ->setPersistence(new FilePersistence('/path/to/storage'))
    ->chat(new UserMessage('Hello'))
    ->getMessage();
```

When the agent suspends (e.g., waiting for tool approval), no exception is thrown —
`chat()` returns an `AgentState` marked interrupted. The **thread is the workflow ID**:
the run's durable records live in the partition named by the threadId itself, so a
later continuation built from the threadId alone finds the pending run — no
workflow coordination ID needs to be stored by the application:

```php
$state = MyAgent::make(workflowId: $threadId)
    ->setMessageStore(new SQLMessageStore($pdo))
    ->setPersistence(new FilePersistence('/path/to/storage'))
    ->chat(new UserMessage('Delete file /tmp/old.log'));

if ($state->isInterrupted()) {
    // Render the current request/history; a paused run has no final answer yet.
    // ... user approves/rejects ...

    // A new execution cycle (e.g. the approve endpoint): the thread alone
    // identifies the run; decisions are keyed by tool callId.
    $state = MyAgent::make(workflowId: $threadId)
        ->setMessageStore(new SQLMessageStore($pdo))
        ->setPersistence(new FilePersistence('/path/to/storage'))
        ->submitApprovalDecisions(['call_123' => 'approve'])
        ->run();
}

$response = $state->getMessage();
```

`submitApprovalDecisions()` stages decisions for the current approval request,
so application code needs only the thread ID
and decisions keyed by tool call ID. Call `events()` instead of `run()` when the
continued segment must stream.

Deferred tools continue through the same thread identity:

```php
$state = MyAgent::make(workflowId: $threadId)
    ->submitToolResults([
        'call_123' => ['result' => ['title' => 'Example']],
        'call_456' => ['error' => 'Browser operation cancelled'],
    ])->run();
```

Reconstruct the same durable history and persistence as for the original turn.
Each entry contains exactly one JSON-compatible `result` or string `error`.
Partial results accumulate; the returned state may be interrupted again.
Use `events()` to stream the continuation. Raw AG-UI or Vercel payloads use
`submitInputs($payload, $translator)` as described in **neuron-frontend-integration**.

Other interruption types use the generic Workflow API: application-controlled
event waits use `signal($name, $payload)->run()`, due timers and inputless
recovery use `resume()->run()`, and durable platform SDKs pass run and execution-attempt fences to
`resume($payload, expectedRunId: $runId, expectedExecutionAttempt: $attempt)->run()` or `->events()`. A background, workflow-ID-first
continuation uses `make(workflowId:)`; the Agent's thread ID then arrives from
the ignition record and is bound into history by the framework.

Available backends: `FilePersistence`, `DatabasePersistence`, `EloquentPersistence`, and `RedisPersistence` (optional `ext-redis`, using a connected `\Redis` client). See the **neuron-workflow** skill for full details on persistence and continuation, and the **neuron-tool-approval** skill for the complete approval flow (UI rendering, decision payloads, unified endpoint).

### Failed turns, pending approvals, and the lease

A failed first inference does not commit its inbound message. Later failures can
leave earlier successful work in history: tool-loop inputs may be committed,
and a failed output node can leave the completed text exchange in history.
Failure does not roll back committed writes.

`run()` / `events()` recover a failed turn using committed steps and memos;
`resume()->run()` is an explicit continuation. A new `chat()` supersedes the
failed run with a new user turn. A failed node may repeat an external operation
if its result was not durably recorded; use `memoize()` inside custom nodes and
external idempotency where needed.

A **pending approval** does lock the thread: a `chat()` while the run is
suspended throws `RunInFlightException`, whose `interrupt` property carries the
pending `ApprovalRequest`. Catch it
to re-render the pending decision, and settle it with `submitApprovalDecisions($decisions)`
(decline decisions are the cancel path).

`abandonRun()` discards an eligible paused, failed, or dead run without starting
another and returns `false` when none exists. Agent refuses abandonment while
history ends in an unanswered tool call, including approval and deferred-result
waits. Settle that call first, or use `resetConversation()` to abandon the run
and clear chat history. Reset bypasses the unanswered-call check,
but still respects live execution leases and retained-completion guards.

Every Agent run holds a **ten-minute lease** by default. A process killed with
no chance to record its failure (memory limit, `max_execution_time`, an
OOM-killed container) leaves the thread `running`; once the lease deadline
passes, the next `chat()` supersedes the dead run instead of refusing. Raise it
above your slowest provider or tool call with `setLeaseTimeout()` or by
overriding `leaseTimeout()`; `null` disables it, in which case a killed
process strands the thread until `resume()->run()` takes it over. A suspended run holds
no lease. Default tool approval has no deadline; custom wait deadlines still
need an inputless continuation to be evaluated.

Successful runs release their persistence partition by default. For durable
completion delivery, opt into `retainCompletionUntilAcknowledged()`, retrieve
retained state with `resume()->run()`, and release that exact generation with
`acknowledgeCompletion($runId)`. Retained completion blocks a new turn until
acknowledged. File persistence is for controlled single-process use; choose
`DatabasePersistence`, `EloquentPersistence`, or `RedisPersistence` for
multi-process coordination. Redis requires persistence and eviction settings that
preserve active and retained runs. Reconstruct the same history, providers, and
tools on continuation.

## Key Decisions

When helping users build agents:

1. **Choose execution mode** based on requirements:
   - `chat()` for standard conversations
   - `stream()` for real-time streaming
   - `structured()` for data extraction

2. **Add tools** when the agent needs to:
   - Access external systems (databases, APIs)
   - Perform calculations
   - Search the web
   - Send emails

3. **Configure chat history** when:
   - Long-running conversations need persistence
   - Multiple sessions should share history
   - Conversation context needs to be shared across agents

4. **Use middleware** to edit the working request, such as summarization or tool selection. Target `InferenceNode::class` to cover both chat/stream and structured inference; matching is subclass-aware. `AgentMiddleware` offers typed hooks for nodes implementing `AgentNodeInterface`. Ordinary custom `Node`s and the boundary nodes need `WorkflowMiddleware` unless they implement that interface. Middleware `after()` returns `void` and cannot replace the routing event.

5. **Use workflow nodes** for I/O and flow control: speech providers, output processing, and interruptions. Tool approval already lives in `ToolNode`. Prefer `entryNodes()` / `exitNodes()` for boundary extensions and **neuron-workflow** for bespoke graphs.

6. **Control tool execution** with `toolMaxRuns($num)` (default 10 per tool across a run, including resumes), or per-tool limits. Escaped tool exceptions fail the run unless a configured `toolErrorHandler()` returns a conversational result; returned `ToolOutput::error()` lets the model handle an expected failure. See **neuron-tool** for input casting, errors, and deferred tools.

## Related Skills

- Use the **neuron-evaluation** skill to test agents with dataset-driven
  evaluations, assertions, AI judges, and multi-turn conversation testing.
- Use the **neuron-monitoring** skill to debug and monitor agents with the
  observability event system, from local logging to production tracing on
  Neuron Cloud.
