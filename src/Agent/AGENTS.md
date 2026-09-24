# Agent Module

`Agent` is a `Workflow` whose graph implements the agentic loop: instructions + history + tools → inference → tool execution → inference again → final response, in chat, streaming and structured-output modes. Study it as the reference composition; extend it, swap its nodes, or use it as a node inside your own workflow.

## Defining an agent

The extension pattern: lazy hooks provide the collaborators, so an agent class is a complete, self-describing unit.

```php
use NeuronAI\Agent\Agent;

class YouTubeAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(key: env('ANTHROPIC_API_KEY'), model: 'claude-sonnet-4-6');
    }

    protected function instructions(): SystemMessage|string
    {
        return new SystemMessage(<<<PROMPT
            You are an AI agent specialized in writing YouTube video summaries.
            Use the tools you have available to retrieve the transcription, then write the summary.
            PROMPT);
    }

    protected function tools(): array
    {
        return [GetTranscriptionTool::make(env('SUPADATA_API_KEY'))];
    }
}

$state = YouTubeAgent::make()->chat(new UserMessage('Summarize this: https://youtube.com/watch?v=...'));
echo $state->getMessage()->getContent();
```

Every hook has a setter twin for fluent definition (`setAiProvider()`, `setInstructions()`, `setTools()`, `setMessageStore()`, `setContextWindow()`, `setPersistence()`; `toolErrorHandler()` for the `resolveToolErrorHandler()` hook), and an explicit setter wins over the hook. `setTools([...])` replaces the entire tool set, including defaults from `tools()` and earlier additions; `setTools([])` clears it. `addTool()` appends to the chosen set, retaining hook defaults only when `setTools()` has never been called. Tool changes apply to the next execution segment. A plain string from `instructions()` is wrapped in a `SystemMessage`; `->cache()` marks its blocks for provider-side prompt caching. `SystemPrompt` is a small helper to compose a structured prompt (background, steps, output).

| Verb | Nature |
|---|---|
| `chat($messages)` | Eager: runs to completion and returns `AgentState` |
| `stream($messages)` | Always returns a lazy `Generator`; iteration also delivers to a configured channel. `getReturn()` is the `AgentState`. |
| `structured($messages, $class)` | Eager: returns the typed output |
| `run()` / `events()` | Execute an explicit `ExecutionRequest`; without one, start or recover a failed execution |
| `ExecutionRequest::resume($payload = null, ...)` | Build a generic continuation or inputless recovery request |
| `submitApprovalDecisions($decisions)` | Return `PendingExecution<AgentState>`; chain `->run()` or `->events()` |
| `submitToolResults($results)` | Return `PendingExecution<AgentState>`; chain `->run()` or `->events()` |

`AgentState::getMessage()` reads the final assistant message off the stored provider response; `isInterrupted()` / `getInterruptRequest()` surface an approval pause on the state itself, like any `WorkflowState`.

Hooks take no argument and run once per execution segment, after admission. Dependencies from an application container arrive through the constructor: resolve the agent from the container, then bind its conversation.

```php
class SupportAgent extends Agent
{
    public function __construct(protected OrderLookupTool $orders, protected RefundTool $refunds)
    {
        parent::__construct();
    }

    protected function tools(): array
    {
        return [$this->orders, $this->refunds];
    }
}

$agent = $container->get(SupportAgent::class)->setThreadId($threadId);
```

A tool instance is a prototype: `ToolNode` clones it for every call and it never enters persisted state, so it can hold injected services.

## The graph is a function of the definition

Default nodes are rebuilt through Workflow's `nodes()` hook at every execution segment, from the current configuration and never from which sugar method was called. Explicitly added nodes and registered middleware stay attached; custom node construction that must read configuration belongs in `nodes()` / `entryNodes()`. Nodes take no collaborators: the `resources()` hook resolves the segment's provider, chat history, instructions (with toolkit guidelines) and tools once into `AgentResources`, which every agent node receives as the third `__invoke()` argument and every middleware as the fourth.

```text
AgentStartEvent ─► StartNode ─► AIInferenceEvent ─► ChatNode ─────────────────┐
 (messages+options)                                 or StructuredInferenceEvent ─► StructuredOutputNode ├► ToolNode ⟲
                                                                                                      │ final response
                                                                                     AgentOutputEvent ─► EndNode ─► Stop
```

- Each `chat()` / `stream()` / `structured()` selects a fresh execution internally and builds a new `AgentStartEvent` (`startEvent()` hook) carrying `messages` and `AgentRunOptions` (`stream`, `outputClass`, `maxRetries`), so inference settings never leak between turns. Agent provider, instructions, history, tools, tool limits, error handlers and parallel-tool settings can change during streaming. The active segment keeps its resolved resources and graph; subsequent segments use the new configuration.
- `AgentStartNode` initializes the public typed `AgentState::$request` (`InferenceRequest`: instructions, messages, options) from the start event and a copy of the segment's instructions. **Nodes and middleware read and mutate that one request; events are routing signals and never forward it.** `AIInferenceEvent::fromRequest()` picks the exact routing class from `options->outputClass`.
- Chat vs stream is transport: `ChatNode` reads `options->stream`, and both paths record the same memoized `ProviderResponse`. Structured output keeps its own node with attempt-indexed memos; `maxRetries` counts retries after the first attempt.
- The tool loop replaces `request->messages` with the uncommitted call/result pair and routes the same request back to inference; those messages are combined with stored history, never overwrite it. `parallelToolCalls(true)` swaps `ToolNode` for `ParallelToolNode`.
- The segment's `ToolRegistry` (`$resources->tools`) is the one tool list shared by inference and tool execution. Tools never enter the state, since they may hold closures or connections; a middleware that contributes tools registers them in `before()`, which runs again in every segment. Cloning `AgentState` deep-copies messages, instructions and options, so parallel branches cannot affect each other.

Middleware edits the working request and the segment's tools directly:

```php
$state->request->instructions->addContent($context);
$resources->tools->add($tool);
```

### Agent nodes in your own workflow

Agent nodes run in any workflow that provides `AgentResources`; a workflow that provides none fails when its graph is built.

```php
Workflow::make(state: new AgentState())
    ->setStartEvent(new AgentStartEvent([new UserMessage('Search PHP')]))
    ->setResources(fn (): AgentResources => new AgentResources(
        $provider,
        new ChatHistory($messageStore, $threadId),
        new SystemMessage('Be helpful'),
        new ToolRegistry([new SearchTool()]),
    ))
    ->addNodes([new AgentStartNode(), new ChatNode(), new ToolNode(), new AgentEndNode()]);
```

### Output extension

Every final response converges on `AgentOutputEvent`. The response remains in `AgentState`; the event carries no payload and does not terminate the workflow. Tool calls continue through the inference loop before reaching this boundary.

`exitNodes()` supplies `[new EndNode()]` by default. Override it to replace the default ending with application nodes:

```php
protected function exitNodes(): array
{
    return [new TextToSpeechNode($this->textToSpeech())];
}
```

The first output node handles `AgentOutputEvent` and reads `$state->getMessage()`. It returns `StopEvent` to finish, or an application event handled by the next output node. Event types determine ordering; the array only registers nodes. Do not include the parent's `AgentEndNode` alongside another handler for `AgentOutputEvent`, because a workflow allows only one handler per event class.

Output nodes are ordinary durable steps: if one fails, `run()` recovers the turn without repeating committed inference. History may already contain the final text while output processing is still running or has failed. `chat()` and `stream()` retain their existing contracts; `structured()` still returns the typed object, and extra output artifacts can be read from the Agent state.

### Middleware

Register with `addMiddleware(NodeClass::class, $middleware)`; matching is `instanceof`, so `InferenceNode::class` (the base of `ChatNode` and `StructuredOutputNode`, both always registered) is the target for mode-agnostic inference middleware such as `Summarization`. Register a middleware that contributes tools, such as `ToolSearchMiddleware`, with `addGlobalMiddleware()`: a continuation can start at `ToolNode`, which must find the tools the model was offered before the pause. Extend `AgentMiddleware` for typed hooks: `beforeAgentNode()` / `afterAgentNode()` receive `AgentNodeInterface`, `AgentState` and `AgentResources`, and `onAgentContextMismatch()` fires on misattachment (empty by default, override it to fail loudly). Middleware read the segment's history, provider, instructions and tools from `AgentResources`, never from their own constructor. `Summarization` calls the segment's provider unless it is given its own (`new Summarization($cheaperProvider)`); it sets its own prompt and clears the provider's tools for that call. Flow control and I/O stay in nodes: tool approval, once a middleware, lives in `ToolNode`, and to-do planning is a toolkit, `TodoPlanningToolkit`.

## Chat history is a service, not state

The Agent receives a message store (`messageStore()` hook, `setMessageStore()`; in-memory by default, retained per instance) and builds a working history over it at the start of every execution segment. Size the conversation sent to the model with the `contextWindow()` hook or `setContextWindow()` (`ChatHistory::DEFAULT_CONTEXT_WINDOW`, 50,000 tokens, by default). The store is the part to share: bind one instance in the container. Each segment loads the conversation fresh, so a long-lived Agent sees the turns other workers added. `getChatHistory()` returns a fresh view on every call and is final, so no override can retain a history across segments; nodes and middleware read the segment's history from `AgentResources`, and writing through the Agent's view while an execution runs is unsupported. See `src/Chat/AGENTS.md` for the store and history contracts.

History is a resource of the segment (`AgentResources::$history`), never carried in `AgentState`, so per-step snapshots stay O(1) instead of embedding the conversation. Consequences:

- Writes go through `addToChatHistory($resources->history, $state, $messages, $memo)`, a durable memo, so a crash-replay skips the write instead of duplicating the tail; the history also skips a message it already holds, covering a write whose memo was lost.
- A message commits only when the step that consumes it succeeds: inference nodes commit their inbound after the provider call lands, and a non-gated tool cycle commits the call/result pair through the *next* inference's write. A tool crash or a failed follow-up call leaves the tail at the last committed message, never at a dangling tool call. Approval-gated and externally executed cycles write their `ToolCallMessage` early, pre-suspend.
- Durable workflow persistence needs a comparably durable store: `InMemoryMessageStore` loses the thread across processes.
- `AgentState::getSteps()` reports the current execution cycle's messages only (transient, available even on an interrupted state).

## Conversation memory

Conversation memory uses RAG's `SemanticMemoryRetrieval`, which builds source/thread filters from an explicit thread-ID allowlist. `CompositeRetrieval` combines it with document retrieval. Creation is opt-in: override `exitNodes()` with `NeuronAI\RAG\Nodes\ConversationIngestionNode`, providing the vector store and embeddings provider; the node reads the chat history from the segment's resources. Agent has no memory collaborator or memory-specific routing.

`resetConversation()` abandons the pending execution and clears chat history. Stored conversation documents have a separate lifecycle and are deleted explicitly through the vector store. See [conversation memory](../../skills/neuron-agent/references/conversation-memory.md) for attachment, retrieval and deletion examples.

## Tool approval

`ToolNode` gates execution: on every call it asks each tool `requiresApproval()` (declaration and attach-time overrides, see `src/Tools/AGENTS.md`), resolves the call against the segment's tool registry (a `ToolException` for anything else), clones the match, binds the inputs, executes under a durable memo, and settles the result on the `ToolCall`. Escaped exceptions are bugs and propagate unless `toolErrorHandler()` converts them.

```php
protected function tools(): array
{
    return [
        DeleteFileTool::make()->requireApproval(),
        RiskyThirdPartyTool::make()->suppressApproval(),
        TransferMoneyTool::make()->withApprovalPolicy(
            fn (ToolInterface $tool): bool|string => ($tool->getInputs()['amount'] ?? 0) > 100
                ? 'Transfers above $100 require a human sign-off'
                : false
        ),
    ];
}
```

When a gated tool is requested, `chat()` returns suspended. A continuation submits decisions keyed by call ID with `submitApprovalDecisions()`:

```php
$agent->submitApprovalDecisions([
    'call_123' => 'approve',
    'call_456' => ['reject', 'too expensive'],
])->run();
```

A tool runs iff explicitly approved: silence is never consent, an incomplete payload re-suspends, and ToolNode durably accumulates delivered decisions through step memos, regardless of the continuation entry point (explicit updates to the still-open batch win).

To reconstruct the approval UI after a page refresh, rebuild the Agent with the same thread identity and persistence, then call `pendingApprovals()`. It reads the current persisted `ApprovalRequest` through `inspect()` and returns only pending `Action` objects, or an empty array when there is no approval request or the run is not suspended: an answered request stays attached while its tools execute, and after they fail. Inspection does not execute the workflow. For other interruptions, including deferred tool results, use `inspect()` to read the run's status and current interruption; it returns `null` when no persisted run exists.

The persisted interruption is authoritative for the current UI request. The pre-suspend `ToolCallMessage` in history is an initial approval snapshot and can remain pending after decisions have been submitted or the workflow has advanced to awaiting tool results. Final tool outcomes are read from the following `ToolResultMessage`. Inside `ToolNode`, approval execution continues through `interrupt()` and durable step memos; `inspect()` serves external readers. Cross-process flows need workflow persistence **and** a durable chat history.

`submitApprovalDecisions()` and `submitToolResults()` validate against the current persisted request and return a `PendingExecution` holding the bound Agent and run/attempt fences. Chain `->run()` or `->events()` to consume the response. Each submission owns its immutable request; creating another submission cannot overwrite it. They require neither an event name nor an interruption ID. Missing runs, unmatched call IDs and invalid payloads fail before execution. A concurrent continuation invalidates that snapshot. For raw AG-UI, Vercel or custom transport payloads, use inherited `submitInputs($payload, $translator)`; see `Frontend/README.md`.

## Tool run limits

`toolMaxRuns()` bounds logical tool calls across one complete agent run, including approval pauses and external execution waits. A tool's `getMaxRuns()` overrides the agent limit, and `getRunKey()` selects which counter it consumes. Rejected approvals consume no slot; a failed execution retried during recovery remains the same logical call.

`AgentState` persists `__tool_runs`. `AgentStartNode` and RAG's `PreProcessNode` reset counters when initializing a new run; completed entry steps are skipped on resume. Custom entry nodes that replace these should reset counters when starting their new run as well.

`ToolNode::checkToolRuns()` records each call's run key, incremented count and effective limit in a step-scoped memo. It restores the count outside the memo using the maximum of the current and recorded values, then enforces the recorded limit. This repairs an older state snapshot after an incomplete step without consuming another slot. Accounting runs independently of execution-result memos, including before `ParallelToolNode` forks, so cached results still restore their counters. A recorded call keeps its limit on recovery; current configuration applies to new calls.

## Deferred execution

`ToolNode` filters by `ToolCall::isDeferred()` after the approval gate; rejected deferred calls stay in the locally settled group. It executes local calls first (only local calls enter `ParallelToolNode`'s fork), then returns `AwaitToolResultsEvent` when external results are pending. Otherwise it returns directly to inference. `executeLocalTools()` accepts the complete `ToolCall[]` and the ID of the `ToolCallMessage` holding them, which every `ToolCallChunk` carries, filters out runnable deferred calls, and returns the locally settled calls (including rejections) with their original indexes. It yields stream chunks but creates no messages; `__invoke()` selects the deferred batch by its flag and approval state and constructs the final result message. `ParallelToolNode` overrides the same array-based contract. The default graph always registers `AwaitToolResultsNode`, including when the current tool list has no deferred tools.

```text
ToolCallEvent → ToolNode → AIInferenceEvent / StructuredInferenceEvent
                    └─→ AwaitToolResultsEvent → AwaitToolResultsNode
                                                    └─→ AIInferenceEvent / StructuredInferenceEvent
```

The handoff carries separate `completedCalls` and `filterDeferredCalls` arrays, retaining their original batch indexes. Local outcomes, rejections and handled dispatch-limit errors belong to the completed group; only calls dispatched externally enter the deferred group. `AwaitToolResultsNode` resolves that explicit group, then merges and sorts both groups into the final result message. Executable tools never travel in the event. `ToolNode` is a completed durable step before the new node suspends; resuming external execution therefore does not repeat the approval gate or completed local execution. Result correlation relies on the call IDs supplied by the provider, as with normal tool calls. Existing tool run limits apply before dispatch.

`AwaitToolResultsNode` suspends with `NeuronAI\Agent\Interrupt\ToolResultsRequest`, a `WaitForEventRequest` on the `tool_results` channel. `getToolCalls()` and the serialized `toolCalls` metadata describe the calls still awaiting results. The persisted request is the authority for pending execution; an earlier approval snapshot in chat history does not describe this later phase.

```php
$state = $agent->chat(new UserMessage('Read the page title'));
$request = $state->getInterruptRequest();
// Expose the pending ToolResultsRequest to the external executor.

// A later request reconstructs the agent with the same thread, persistence and history.
$state = $agent->submitToolResults([
    'call_123' => ['result' => ['title' => 'Example']],
    'call_456' => ['error' => 'User cancelled the browser operation'],
])->run(); // Or ->events() to stream the continuation.
```

Each entry has exactly one `result` (a JSON-compatible value) or `error` (a string). Error outcomes become `ToolOutput::error()`; strings pass through and other results are JSON-encoded, preserving `false`, `0` and `null`. Partial deliveries are durably accumulated. The waiting node restores accepted results, tracks pending calls by call ID and removes each one as its result arrives. It builds a request only while calls remain pending; the request receives those calls plus accepted results for validating repeat submissions. An identical result can be restated while the batch is pending; conflicting, unknown or malformed results reject before input acceptance. Workflow's run and interrupt identity rules still apply; this does not provide deduplication across completed runs.

The dispatched batch remains valid even if its definitions are absent from the resumed agent. Re-supply dynamically offered tools only when they should remain available for **future** model calls. Client schemas and results are untrusted inputs to the model context; applications must authorize the thread and the capabilities they expose.

The default wait has no deadline. A customized `AwaitToolResultsNode::buildRequest()` can supply a `ToolResultsRequest` deadline (memoize its initial value so partial resumes do not extend it). Workflow's normal expiry continuation settles only the outstanding calls as error results; scheduling that continuation remains the application's responsibility. `abandonRun()` refuses an unanswered tool call; submit error outcomes or explicitly reset the conversation.

Use `submitToolResults($results)` for native result maps, including results sent by a custom frontend. Raw AG-UI and Vercel envelopes use their protocol translators through `submitInputs()`; do not pass a messages/parts envelope to the native methods. Use the durable suspension request as the dispatch boundary; a live stream chunk alone does not prove suspension has committed.

## The thread IS the workflow ID

The Agent's `threadId` is the Workflow instance identity: `setThreadId()` delegates to `setWorkflowId()`, and `getThreadId()` reads `getWorkflowId()`. There is one stored address, so a run's durable records live in the partition named by the thread. No pointer, no index: the approve endpoint needs only the thread ID, one read answers "is a run in flight here", and execution identity never touches chat history.

```php
// Fresh turn (controller): identity enters through the one front door.
SupportAgent::make(workflowId: $threadId)->chat(new UserMessage($input));

// Thread-first resume (approve endpoint): same statement.
$agent = SupportAgent::make(workflowId: $threadId);
$agent->submitApprovalDecisions(['call_123' => 'approve'])->run();

// WorkflowId-first resume (background wake): the configured workflow address is the thread.
SupportAgent::make(workflowId: $ticket->workflowId)
    ->run(ExecutionRequest::resume($ticket->payload, expectedRunId: $ticket->runId, expectedExecutionAttempt: $ticket->executionAttempt));
```

Agent inherits Workflow's constructor directly, accepting optional `workflowId`
and initial state. Without an explicit identity it remains unbound. Framework applications resolve
Agents through their container, then call `setThreadId($threadId)`; `make()` remains
an independent direct-construction helper. Subclasses injecting application
services still call `parent::__construct()`.

Configure conversation identity through the constructor or `setThreadId()`.
The first execution generates and retains an identity for an unbound new
conversation. Continuations require an already bound Agent. Later executions reuse that
identity with separate run IDs. Repeating the same identity is allowed; setters
cannot switch a bound instance to another conversation.
Use a fresh Agent for another conversation. Creating a lazy stream or inspecting the Agent does not bind the instance.
Execution requests and per-operation methods do not accept address overrides.

Histories are opened for the Agent's identity, and message stores carry none, so
they cannot conflict with it. `getChatHistory()` throws while identity is unset:
bind before accessing history or using history-dependent operations such as reset
or graph export.

One live run per thread has these consequences:

- A new `chat()` while a run is suspended on the thread is refused with `RunInFlightException`, carrying the pending `ApprovalRequest`; settle it first. The thread stays locked until the full decision set is delivered.
- A *failed* turn does not lock the thread: the inbound message was never written, so the next `chat()` supersedes the dead generation, while plain `run()` or `events()` recovers it reusing every memoized step (a long tool loop is not re-billed).
- Every Agent run holds a ten-minute lease (`leaseTimeout()` hook, `setLeaseTimeout()`, `null` disables), so a process killed mid-turn stops refusing the thread once the deadline passes. Raise it above your slowest provider or tool call.
- `abandonRun()` dismisses a dead turn but refuses while history ends with an unanswered `ToolCallMessage` (approval or external execution); `resetConversation()` frees the thread unconditionally.

**Persisted wins.** Every durable run writes an ignition record at first execution: run ID and start event (messages + intent). On resume the record's intent and instructions win over the factory's current defaults: the factory supplies capability (provider, tools, history), the record supplies intent. `setMessageStore()` may replace the store between interactions. Replacing it during an active execution configures subsequent segments without redirecting the current segment's history or durable writes.

**Security.** The threadId is untrusted input used as a storage key: it selects which conversation is read, written and resumed. Authorize user ↔ thread ownership before opening a history with it; the framework performs no access control.


## Caller-managed execution

Build an `ExecutionRequest::start(new AgentStartEvent($messages, $options),
runId: $reservedRunId)` and call `run($request)` for eager
execution or `events($request)` for lazy output. Both use the same engine as chat,
stream and structured conveniences. The thread is bound before resources are
constructed, so hooks read it from `getThreadId()`.

`provider()`, `tools()` and `instructions()` take no argument; their explicit fluent
instance overrides still win. Graph hooks `nodes()`, `entryNodes()` and `exitNodes()`
take no argument either: nodes read the segment's provider, history, instructions and
tools from `AgentResources`. Toolkit guidelines are appended to the segment's copy of
the instructions, never to the definition's.

Use the `streamAdapter()` / `channel()` hooks or fluent factories returning
adapters/channels for application-managed push output.
There is no preparation callback or Cloud trait. Saved results do not construct
resources or replay chunks. Returned states keep their own metadata and data when
the same definition runs again. `getRunId()` and `getState()` are runtime/result
operations, not definition getters.
