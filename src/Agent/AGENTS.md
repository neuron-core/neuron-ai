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

Every hook has a setter twin for fluent definition (`setAiProvider()`, `setInstructions()`, `addTool()`, `setChatHistory()`, `setMemory()`, `setPersistence()`), and an explicit setter wins over the hook. A plain string from `instructions()` is wrapped in a `SystemMessage`; `->cache()` marks its blocks for provider-side prompt caching. `SystemPrompt` is a small helper to compose a structured prompt (background, steps, output).

| Verb | Nature |
|---|---|
| `chat($messages)` | Eager: runs to completion and returns `AgentState` |
| `stream($messages)` | Pull-stream `Generator` of native chunks, or adapter lines; `getReturn()` is the `AgentState` |
| `structured($messages, $class)` | Eager: returns the typed output |
| `run($inputs = null, ...)` / `events(...)` | The Workflow terminals: no input starts a run, an explicit array continues one |
| `toolApprovalDecisions($decisions)` | Stages approval decisions (sugar for `signal('approval', ...)`) for the following `run()` or `events()` |
| `toolResults($results)` | Stages external tool results (sugar for `signal('tool_results', ...)`) for the following `run()` or `events()` |

`AgentState::getMessage()` reads the final assistant message off the stored provider response; `isInterrupted()` / `getInterruptRequest()` surface an approval pause on the state itself, like any `WorkflowState`.

## The graph is a function of the definition

Default nodes are rebuilt through Workflow's `nodes()` hook at every execution segment, from the current configuration and never from which sugar method was called. Explicitly added nodes and registered middleware stay attached; custom node construction that must read configuration belongs in `nodes()` / `entryNodes()`.

```text
AgentStartEvent ─► StartNode ─► [RecallMemoryNode] ─► AIInferenceEvent ─► ChatNode ─────────────────┐
 (messages+options)                                 or StructuredInferenceEvent ─► StructuredOutputNode ├► ToolNode ⟲
                                                                                                      │ final response
                                                                                     [StoreMemoryNode] ─► Stop
```

- Each `chat()` / `stream()` / `structured()` builds a fresh `AgentStartEvent` (`startEvent()` hook) carrying `messages` and `AgentRunOptions` (`stream`, `outputClass`, `maxRetries`, `recallMemory`, `rememberMemory`), so inference settings never leak between turns. Configuration changes during a segment apply to the next one.
- `StartNode` initializes the public typed `AgentState::$request` (`InferenceRequest`: instructions, messages, tools, options) from the start event, cloned instructions and the effective tool list. **Nodes and middleware read and mutate that one request; events are routing signals and never forward it.** `AIInferenceEvent::fromRequest()` picks the exact routing class from `options->outputClass`.
- Chat vs stream is transport: `ChatNode` reads `options->stream`, and both paths record the same memoized `ProviderResponse`. Structured output keeps its own node with attempt-indexed memos; `maxRetries` counts retries after the first attempt.
- The tool loop replaces `request->messages` with the uncommitted call/result pair and routes the same request back to inference; those messages are combined with stored history, never overwrite it. `parallelToolCalls(true)` swaps `ToolNode` for `ParallelToolNode`.
- The effective tool list is shared by inference and tool execution. Executable tools are excluded from request serialization (they may hold closures or connections): `Agent::restoreState()` re-seeds `bootstrapTools()` on recalled state, and tool-contributing middleware reapply their changes in `before()`. Cloning `AgentState` deep-copies messages, instructions and options, so parallel branches cannot affect each other.

Middleware edits the working request directly:

```php
$state->request->instructions->addContent($context);
$state->request->tools[] = $tool;
```

### Middleware

Register with `addMiddleware(NodeClass::class, $middleware)`; matching is `instanceof`, so `InferenceNode::class` (the base of `ChatNode` and `StructuredOutputNode`, both always registered) is the target for mode-agnostic inference middleware such as `Summarization`, `TodoPlanning` and `ToolSearchMiddleware`. Extend `AgentMiddleware` for typed hooks: `beforeAgentNode()` / `afterAgentNode()` receive `AgentNodeInterface` and `AgentState`, and `onAgentContextMismatch()` fires on misattachment (empty by default, override it to fail loudly). Middleware read chat history from the node they wrap (`$node->getChatHistory()`), never from their own constructor. Flow control and I/O stay in nodes: tool approval, once a middleware, lives in `ToolNode`.

## Chat history is a service, not state

History is injected into agent nodes as a constructor dependency (`AgentNodeInterface`), never carried in `AgentState`, so per-step snapshots stay O(1) instead of embedding the conversation. Consequences:

- Writes go through `addToChatHistory($messages, $memo)`, a durable memo, so a crash-replay skips the write instead of duplicating the tail.
- A message commits only when the step that consumes it succeeds: inference nodes commit their inbound after the provider call lands, and a non-gated tool cycle commits the call/result pair through the *next* inference's write. A tool crash or a failed follow-up call leaves the tail at the last committed message, never at a dangling tool call. Approval-gated and externally executed cycles write their `ToolCallMessage` early, pre-suspend.
- Durable workflow persistence needs a comparably durable history: `InMemoryChatHistory` loses the thread across processes.
- `AgentState::getSteps()` reports the current execution cycle's messages only (transient, available even on an interrupted state).

## Memory

`MemoryInterface` (`recall(query)`, `remember(threadId, user, assistant)`, `forget(threadId)`) is the customization boundary: each implementation owns its retrieval scope. `SemanticMemory` is the vector-backed one, reusing the RAG store and embeddings interfaces with the default `DocumentSchema` (`sourceType` / `sourceName` isolate memory documents by type and thread, so give it a dedicated collection). Its `recallThreadIds` is an explicit allowlist, the current thread is not added implicitly, and it must come from trusted application data: never accept thread IDs from a client without an ownership check.

Memory attaches independently of history (`memory()` hook or `setMemory()`), and `getChatHistory()` always returns the developer's exact instance; memory never wraps or proxies it. When attached, `RecallMemoryNode` runs once per turn before the first provider call (memoized; recalled strings are appended as a trailing `<CONVERSATION-MEMORIES>` system block and never enter history) and `StoreMemoryNode` stores the plain user/assistant exchange after the final response (tool traffic excluded; failed or interrupted turns store nothing). Both yield `memory.recall` / `memory.store` step events and emit count-only observability events. Inference nodes know nothing about memory, and a memory-free agent keeps its original graph.

`setMemoryUsage(recall:, remember:)` sets per-run intent for the two branches (a disabled branch is not traversed), and a suspended run resumes with its original choices. Working history and long-term memory share thread identity but have separate lifecycles: `flushAll()` clears only history (so `Summarization` can compact the context window), while `resetConversation()` forgets memory first and then clears history, leaving history untouched if forgetting fails.

## Tool approval

`ToolNode` gates execution: on every call it asks each tool `requiresApproval(inputs)` (declaration and attach-time overrides, see `src/Tools/AGENTS.md`), resolves the call against the request's tool list (a `ToolException` for anything else), clones the match, binds the inputs, executes under a durable memo, and settles the result on the `ToolCall`. Escaped exceptions are bugs and propagate unless `toolErrorHandler()` converts them.

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

When a gated tool is requested, `chat()` returns suspended. A continuation delivers decisions as a **cumulative** payload keyed by call ID, restated in full on every continuation:

```php
$agent->toolApprovalDecisions([
    'call_123' => 'approve',
    'call_456' => ['reject', 'too expensive'],
])->run();
```

A tool runs iff explicitly approved: silence is never consent, an incomplete payload re-suspends, and partial decisions are deliberately not persisted anywhere (accumulation lives with the caller, and the latest payload wins). A UI re-renders pending approvals from chat history alone (last message, tools with `getApprovalState()`) with no workflow boot; final outcomes are read from the following `ToolResultMessage`. Cross-process flows need workflow persistence **and** a durable chat history.

## Tool run limits

`toolMaxRuns()` bounds logical tool calls across one complete agent run, including approval pauses and external execution waits. A tool's `getMaxRuns()` overrides the agent limit, and `getRunKey()` selects which counter it consumes. Rejected approvals consume no slot; a failed execution retried during recovery remains the same logical call.

`AgentState` persists `__tool_runs`. `StartNode` and RAG's `PreProcessNode` reset counters when initializing a new run; completed entry steps are skipped on resume. Custom entry nodes that replace these should reset counters when starting their new run as well.

`ToolNode::checkToolRuns()` records each call's run key, incremented count and effective limit in a step-scoped memo. It restores the count outside the memo using the maximum of the current and recorded values, then enforces the recorded limit. This repairs an older state snapshot after an incomplete step without consuming another slot. Accounting runs independently of execution-result memos, including before `ParallelToolNode` forks, so cached results still restore their counters. A recorded call keeps its limit on recovery; current configuration applies to new calls.

## Deferred execution

`ToolNode` filters by `ToolCall::isDeferred()` after the approval gate; rejected deferred calls stay in the locally settled group. It executes local calls first (only local calls enter `ParallelToolNode`'s fork), then returns `AwaitToolResultsEvent` when external results are pending. Otherwise it returns directly to inference. `executeLocalTools()` accepts the complete `ToolCall[]`, filters out runnable deferred calls, and returns the locally settled calls (including rejections) with their original indexes. It yields stream chunks but creates no messages; `__invoke()` selects the deferred batch by its flag and approval state and constructs the final result message. `ParallelToolNode` overrides the same array-based contract. The default graph always registers `AwaitToolResultsNode`, including when the current tool list has no deferred tools.

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
$state = $agent->toolResults([
    'call_123' => ['result' => ['title' => 'Example']],
    'call_456' => ['error' => 'User cancelled the browser operation'],
])->run(); // Or events() to stream the continuation.
```

Each entry has exactly one `result` (a JSON-compatible value) or `error` (a string). Error outcomes become `ToolOutput::error()`; strings pass through and other results are JSON-encoded, preserving `false`, `0` and `null`. Partial deliveries are durably accumulated. The waiting node restores accepted results, tracks pending calls by call ID and removes each one as its result arrives. It builds a request only while calls remain pending; the request receives those calls plus accepted results for validating repeat submissions. An identical result can be restated while the batch is pending; conflicting, unknown or malformed results reject before input acceptance. Workflow's run and interrupt identity rules still apply; this does not provide deduplication across completed runs.

The dispatched batch remains valid even if its definitions are absent from the resumed agent. Re-supply dynamically offered tools only when they should remain available for **future** model calls. Client schemas and results are untrusted inputs to the model context; applications must authorize the thread and the capabilities they expose.

The default wait has no deadline. A customized `AwaitToolResultsNode::buildRequest()` can supply a `ToolResultsRequest` deadline (memoize its initial value so partial resumes do not extend it). Workflow's normal expiry continuation settles only the outstanding calls as error results; scheduling that continuation remains the application's responsibility. `abandonRun()` refuses an unanswered tool call; submit error outcomes or explicitly reset the conversation.

This is the native workflow contract. Translating AG-UI or Vercel schemas, execution requests and inbound results belongs in protocol integrations. Use the durable suspension request as the dispatch boundary; a live stream chunk alone does not prove suspension has committed.

## The thread IS the workflow ID

The Agent declares its `threadId` as the run's workflow ID (`workflowId()`), so a run's durable records live in the partition named by the thread. No pointer, no index: the approve endpoint needs only the thread ID, one read answers "is a run in flight here", and execution identity never touches chat history.

```php
// Fresh turn (controller): identity enters through the one front door.
SupportAgent::make(threadId: $threadId)->chat(new UserMessage($input));

// Thread-first resume (approve endpoint): same statement.
SupportAgent::make(threadId: $threadId)
    ->toolApprovalDecisions(['call_123' => 'approve'])
    ->run();

// WorkflowId-first resume (background wake): the ignition record supplies the thread.
SupportAgent::make(workflowId: $ticket->workflowId)
    ->run([ResumeInput::fromArray($ticket->input)], expectedRunId: $ticket->runId);
```

Identity is **always a developer statement; the framework never generates one**. It resolves from `make(threadId:)`, from adoption of a pre-bound history passed to `setChatHistory()` (which selects that conversation), or from the ignition record on a workflowId-first resume. Disagreeing non-null claims throw `AgentException`: a record contradicting an explicit claim is a misidentified continuation. Once resolved, the Agent binds the identity into an unbound history (`setThreadId()`, itself assign-once). A run without identity lives under an engine-generated workflow ID and is simply not findable by its thread; a hook-provided history that self-keys materializes after the ignition record is written, so it does not make a run thread-findable either.

One live run per thread has these consequences:

- A new `chat()` while a run is suspended on the thread is refused with `RunInFlightException`, carrying the pending `ApprovalRequest`; settle it first. The thread stays locked until the full decision set is delivered.
- A *failed* turn does not lock the thread: the inbound message was never written, so the next `chat()` supersedes the dead generation, while `run([])` replays it reusing every memoized step (a long tool loop is not re-billed).
- Every Agent run holds a ten-minute lease (`leaseTimeout()` hook, `setLeaseTimeout()`, `null` disables), so a process killed mid-turn stops refusing the thread once the deadline passes. Raise it above your slowest provider or tool call.
- `abandonRun()` dismisses a dead turn but refuses while history ends with an unanswered `ToolCallMessage` (approval or external execution); `resetConversation()` frees the thread unconditionally.

**Persisted wins.** Every durable run writes an ignition record at first execution: run ID, start event (messages + intent) and the context bag (`threadId`). On resume the record's intent and instructions win over the factory's current defaults: the factory supplies capability (provider, tools, history), the record supplies intent. `setChatHistory()` may replace history between interactions; a different thread clears local run identity and staged signals while persisted runs stay intact, and replacing it during an active execution throws.

**Security.** The threadId is untrusted input used as a storage key: it selects which conversation is read, written and resumed. Authorize user ↔ thread ownership before opening a history with it; the framework performs no access control.
