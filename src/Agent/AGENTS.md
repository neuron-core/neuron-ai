# Agent Module

AI agent built on Workflow. Provides chat, streaming, and structured output modes.

**Dependencies**: `src/Workflow/AGENTS.md`, `src/Chat/AGENTS.md`, `src/Providers/AGENTS.md`, `src/Tools/AGENTS.md`

## Extension Pattern (Recommended)

Create a custom agent class extending `Agent`:

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;

class YouTubeAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: env('ANTHROPIC_API_KEY'),
            model: 'claude-sonnet-4-6',
        );
    }

    protected function instructions(): SystemMessage|string
    {
        return new SystemMessage(
            <<<PROMPT
            You are an AI agent specialized in writing YouTube video summaries.

            Get the URL of a YouTube video, or ask the user to provide one.
            Use the tools you have available to retrieve the transcription of the video,
            then write the summary.

            Write the summary in a paragraph without using lists.
            After the summary add a list of three sentences as the most important takeaways.
            PROMPT
        );
    }

    protected function tools(): array
    {
        return [
            GetTranscriptionTool::make(env('SUPADATA_API_KEY')),
        ];
    }
}

// Usage
use NeuronAI\Chat\Messages\UserMessage;

$response = YouTubeAgent::make()
    ->chat(
        new UserMessage('Summarize this: https://youtube.com/watch?v=...')
    )
    ->getMessage();
```

`instructions()` can also return a plain string — it gets wrapped in a `SystemMessage`
automatically. Call `->cache()` on the `SystemMessage` to mark its system content blocks
for provider-side prompt caching.

## Fluent Definition (Alternative)

For quick prototyping or simple use cases:

```php
use NeuronAI\Agent;
use NeuronAI\Providers\Anthropic\Anthropic;

$agent = Agent::make()
    ->setAiProvider(new Anthropic(key: '...', model: '...'))
    ->setInstructions('You are a helpful assistant.')
    ->addTool($tool);

$response = $agent->chat(new UserMessage('Hello'))->getMessage();
```

## The static graph & execution intent

The Agent composes the SAME node set on every run — the graph is a pure
function of the agent definition, never of which sugar method was called:

```
AgentStartEvent ─► StartNode ─► [RecallMemoryNode] ─► AIInferenceEvent ─► ChatNode ─┐
 (messages+intent)                when configured     or StructuredInferenceEvent     ├► ToolNode ⟲
                                                       ─► StructuredOutputNode ───────┘
                                                                     │ final response
                                                                     ▼
                                                       [StoreMemoryNode] ─► Stop
                                                          when configured
```

- The start event is **pure run data**: messages plus the inference intent
  fields (`stream`, `outputClass`, `maxTries`). Sugar methods only *record*
  intent (`setStream()` / `setStructuredOutput()`); none of them changes the
  graph or hard-constructs event classes.
- **`StartNode`** (the default `entryNodes()` chain) births the
  `AIInferenceEvent` from the definition (instructions cloned, tools
  injected) plus the start event's data, then sends it through
  `RecallMemoryNode`. That node derives the routed class via
  `AIInferenceEvent::routed()` — recorded structured intent yields a
  `StructuredInferenceEvent`, which exact-class routing sends to
  `StructuredOutputNode`. RAG overrides `entryNodes()` with its retrieval
  chain, whose `InstructionsNode` births the inference event the same way.
- Chat vs stream is **transport, not control flow**: `ChatNode` handles both,
  branching on the event's `stream` flag; both record the same memoized
  `ProviderResponse`, so a wrong flag can never corrupt a replay.
- The tool loop returns to the right inference node by data, not topology:
  `ToolCallEvent` embeds its originating inference event, and the returned
  instance's runtime class routes back.

| Method | Effect |
|--------|--------|
| `chat($messages)` | Eager: ignites and runs to completion → `AgentState` (buffered transport) |
| `stream($messages)` | Pull-stream: a `Generator` yielding native chunks or lines from the Workflow's configured adapter; `getReturn()` is the `AgentState`. |
| `structured($messages, $class)` | Eager: returns the typed output |
| `resume($inputs?, $expectedRunId?, $expectedAttempt?)` | Continues a suspended, failed, or crashed run, optionally delivering addressed inputs → `AgentState` |

```php
// Chat (eager → AgentState)
$state = YouTubeAgent::make()->chat(new UserMessage('Hello'));
echo $state->getMessage()->getContent();

// Streaming (Generator → AgentState via getReturn())
foreach (YouTubeAgent::make()->stream(new UserMessage('Hello')) as $chunk) {
    echo $chunk->content;
}

// Structured output
$report = MyAgent::make()->structured($message, ReportSchema::class);

// Resume a suspended run (approval endpoint) — mode-agnostic → AgentState
use NeuronAI\Workflow\Resume\ResumeInput;

$suspensionId = $state->getSuspensions()[0]->id;
$state = MyAgent::make()
    ->setChatHistory($history)
    ->setPersistence($persistence)
    ->resume([ResumeInput::event($suspensionId, ['call_123' => 'approve'])]);
echo $state->getMessage()->getContent();
```

**Sugar ignites; `resume()` continues.** A new turn is a new run; an answer
resumes the suspended one. Calling `resume()` without inputs replays a crashed
or failed attempt without inventing an external answer. The sugar methods take
no resume payload — continuation goes through the same engine verb a plain
Workflow uses. The run's mode never needs restating on a resume: intent is
persisted in the ignition record (see *Ignition & thread identity* below).

`chat()`/`resume()` are eager and return `AgentState`; `stream()` is the
pull-stream verb (a `Generator`) — the same eager/lazy split as Workflow's
`run()` / `events()`. `AgentState::getMessage()` reads the final assistant
message off the stored provider response; `isInterrupted()` /
`getInterruptRequest()` surface an approval pause on the state itself, just
like a plain `WorkflowState`.

## Middleware (`Middleware/`)

Register via `$workflow->addMiddleware(NodeClass::class, $middleware)`:

| Middleware | Purpose |
|------------|---------|
| `TodoPlanning` | Injects todo planning capabilities |
| `Summarization` | Adds conversation summarization |

Middleware shapes events before a node acts; flow control and I/O belong to the nodes
themselves (tool approval, previously a middleware, now lives in `ToolNode`).

Node matching is subclass-aware (`instanceof`), so middleware registered for a
class also applies to its subclasses. `ChatNode` (chat + stream transport) and
`StructuredOutputNode` are both always registered — the event's exact class
selects the route at traversal time. They share the `InferenceNode` base
class, so register mode-agnostic inference middleware (`Summarization`,
`TodoPlanning`, `ToolSearchMiddleware`) against `InferenceNode::class` to have
it fire on whichever inference route the run takes.

### `AgentMiddleware` — typed hooks for the agent context

Extend `AgentMiddleware` and implement `beforeAgentNode(AgentNodeInterface $node,
Event $event, AgentState $state)` / `afterAgentNode(...)`. On misattachment
outside the agent context `onAgentContextMismatch()` fires instead — empty by
default, override it to fail loudly when a silent skip would be a hazard.
Middleware read the chat history from the node they wrap
(`$node->getChatHistory()`), never from their own constructor.

## Chat history is a service, not state

The chat history is injected into agent nodes as a constructor dependency
(`AgentNodeInterface`), never carried in `AgentState` — per-step snapshots stay
O(1) instead of embedding the conversation. Consequences:

- History writes go through `addToChatHistory($messages, $memo)`, which wraps
  the write in a durable memo so a crash-replay skips it instead of duplicating
  the tail.
- A message commits only when the step that consumes it succeeds: inference
  nodes commit their inbound after the provider call lands, and a non-gated
  tool cycle commits the call/result pair together through the *next*
  inference's inbound write — a tool crash or failed follow-up call
  leaves the tail at the last committed message, never at a dangling tool call.
  Only an approval-gated cycle writes its `ToolCallMessage` early (pre-suspend).
- Durable workflow persistence requires a comparably durable chat history
  (`InMemoryChatHistory` loses the thread across processes).
- `AgentState::getMessage()` reads the final message off the stored provider
  response; `AgentState::getSteps()` reports the current execution cycle's
  messages only (transient, available even on an interrupted final state).

### Memory-aware history wiring

`SemanticMemory` is the ready-to-use vector-backed implementation. It reuses
the RAG vector-store and embeddings interfaces. Give it a dedicated collection
or index using the default `DocumentSchema`: the built-in `sourceType` and
`sourceName` fields isolate memory documents by type and thread, so no custom
schema is needed. A shared store whose schema declares other required metadata
fields will reject memory documents that do not contain them.

```php
use NeuronAI\Agent\Memory\SemanticMemory;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\VectorStore\FileVectorStore;

class MyAgent extends Agent
{
    protected function provider(): AIProviderInterface {...}

    protected function memory(): ?MemoryInterface
    {
        return new SemanticMemory(
            vectorStore: new FileVectorStore(
                directory: storage_path('app/agent-memory'),
            ),
            embeddings: new OpenAIEmbeddingsProvider(
                key: env('OPENAI_API_KEY'),
                model: 'text-embedding-3-small',
            ),
            topK: 5,
            recallThreadIds: [...]
        );
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return new FileChatHistory('path/directory');
    }
}

$state = SupportAgent::make(threadId: $threadId)->chat(...);
```

`recallThreadIds` is the exact, non-empty recall allowlist owned by that
`SemanticMemory` instance; the current thread is not added implicitly. Resolve
the list from trusted application data, such as conversations owned by the
authenticated user, and never accept thread IDs from a client without an
ownership check. Duplicate IDs are removed.

Recall searches the allowed threads together and applies `topK` globally.
`remember()` and `forget()` remain scoped to one explicit thread, so completed
exchanges are always stored in the current conversation and
`resetConversation()` deletes only that conversation.

`MemoryInterface` is the customization boundary: `recall()` receives only the
query and returns relevant conversation excerpts, so each implementation owns
its retrieval scope. `remember()` stores a completed exchange in one thread,
and `forget()` removes one thread. Implement it directly for a non-vector
backend.

`setMemory(MemoryInterface $memory)` attaches memory independently from chat
history, like every other Agent component. `getChatHistory()` always returns
the exact developer-provided history instance; memory does not wrap, replace,
or proxy it. Calling `setMemory()` before or after `setChatHistory()` produces
the same result.

Subclasses may provide the dependency through the lazy hook instead:

```php
protected function memory(): ?MemoryInterface
{
    return new ProjectMemory(/* ... */);
}
```

An explicit `setMemory()` call wins over the hook. As with providers, tools,
and other graph dependencies, configure memory before execution so composition
can inject it into the memory nodes.

`RecallMemoryNode` runs after instructions are complete and before the first
provider call. Recall is durably memoized there. Recalled strings are appended to a trailing
`<CONVERSATION-MEMORIES>` system block; they never enter chat history. This
works for chat, stream, structured output, and RAG without route-specific
configuration. Tool iterations return directly to inference, so recall runs
once per turn rather than once per tool call.

The node yields `StepStartedStreamEvent('memory.recall')` and a matching
`StepFinishedStreamEvent` around that work. The finished event reports only the
number of recalled memories, never their content or thread IDs.

After the final inference writes its response to chat history, a
`StoreMemoryEvent` routes the completed message slice through
`StoreMemoryNode`. The node extracts the plain user-assistant exchange and
memoizes `remember()` as its own durable side effect. Tool-assisted turns are
stored only after their final assistant response; `ToolCallMessage` and
`ToolResultMessage` remain protocol traffic and are excluded. Failed
inference, incomplete streams, and interrupted tool loops produce no memory.
The store node similarly yields `memory.store` step started/finished events.
These portable events are visible in a native stream and are translated by
AG-UI or Vercel adapters without either adapter depending on memory classes.

Memory operations also emit PSR-14 observability pairs around the actual memory
boundary: `MemoryRecalling` / `MemoryRecalled` and `MemoryStoring` /
`MemoryStored`. The recall completion event exposes only the recalled-memory
count; it never exposes queries, recalled content, retrieval scope, or thread
IDs. The existing `WorkflowNodeStart` / `WorkflowNodeEnd` events still describe
generic node execution. If a memory operation throws, its starting event is
followed by the existing `AgentError` and no successful completion event.

Inference nodes do not depend on `MemoryInterface` and perform no recall,
prompt injection, pair extraction, or storage. They only route final responses
to the store phase when memory is enabled. The Agent adds the recall and store
nodes only when memory is configured, so a memory-free Agent keeps its original
graph and execution cost. Implement `MemoryInterface` to customize recall,
redaction, or persistence.

Chat history and long-term memory share thread identity, but they have separate
lifecycles. `ChatHistoryInterface::flushAll()` clears only working history.
This allows `Summarization` and custom middleware to compact or rewrite the
context window without deleting durable semantic memories.

Use the explicit aggregate operation when the user intends to permanently
delete the entire conversation:

```php
$agent->resetConversation();
```

`resetConversation()` calls `MemoryInterface::forget($threadId)` first, when
memory is configured, and then clears chat history. If forgetting fails, chat
history remains untouched and the exception propagates. Without configured
memory, it simply clears chat history.

## Persistence & Tool Approval

`ToolNode` gates tool execution behind human approval — there is no middleware
to attach; the gate runs on every tool call and asks each tool. Messages carry `ToolCall`
value objects: the node resolves every call against ONE source — the inference
event's tool list, the cycle's effective set (agent base plus middleware additions, minus
middleware removals) — clones the match, binds the call's inputs, executes, and settles
the result back onto the call. A call naming a tool outside that set throws a
`ToolException`. Exceptions escaping tool execution are bugs and propagate (a
*conversational* failure is a returned `ToolOutput::error()` — see
`src/Tools/AGENTS.md`); `toolErrorHandler(fn (Throwable $e, ToolCall $call):
string|ToolOutput|null)` is the cross-cutting override — a returned string or
`ToolOutput` settles as the call's result, `null` declines and the exception
propagates. Event capability is transient in persistence: the executor passes every
event recalled from persistence through `Workflow::restoreEvent()` before it re-enters
traversal, and the Agent's override re-seeds `bootstrapTools()` on recalled
inference/tool-call events (live results never pass through restore, so a live
effective set — middleware additions and removals included — is never touched);
tool-contributing middleware re-supply their own additions in `before()`. The node itself holds no tool registry. **Each tool declares** its
intrinsic risk via the protected `approvalPolicy(array $inputs)` hook, and the agent
developer overrides the declaration per tool at
attach time, in both directions:

```php
protected function tools(): array
{
    return [
        DeleteFileTool::make()->requireApproval(),        // force, even if it declares false
        RiskyThirdPartyTool::make()->suppressApproval(),  // waive a declared gate
        TransferMoneyTool::make()->withApprovalPolicy(    // replace the policy
            fn (ToolInterface $t): bool|string => ($t->getInputs()['amount'] ?? 0) > 100
                ? 'Transfers above $100 require a human sign-off'
                : false
        ),
    ];
}
```

Both the declaration and a policy callback return `bool|string` — a string counts as `true`
and doubles as the approval reason shown to the approver (persisted as `approvalReason` on
the tool entry in chat history, exposed on the `ApprovalRequest` actions as `reason`). When
a gated tool is requested, `chat()` returns suspended instead of completed — cross-process
flows require **workflow persistence AND a durable chat history** (the suspend-time
`ToolCallMessage` in chat history is what lets a cold process render and resume the pending
approval).

```php
use NeuronAI\Workflow\Persistence\DatabasePersistence;

$agent = YouTubeAgent::make()
    ->setPersistence(new DatabasePersistence($pdo));
    // + a durable ChatHistory
```

A resume delivers decisions as a **cumulative** payload keyed by tool callId — the entire
decision set, restated on every resume:

```php
$agent->resume([ResumeInput::event($suspensionId, [
    'call_123' => 'approve',
    'call_456' => ['reject', 'too expensive'],
])]);
```

A tool runs iff explicitly approved; silence is never consent. An incomplete payload
re-suspends, and partial decisions are deliberately **not** persisted anywhere —
accumulation lives with the caller (an app collecting decisions one at a time gathers
them itself before resuming). The latest payload wins, so decisions are revisable until
the set completes. The UI re-renders pending approvals by reading chat history (last
message, tools with `getApprovalState()`) — no workflow boot; final outcomes are read
from the `ToolResultMessage` that follows. The thread stays effectively
**locked** until the full decision set is delivered — the engine's
one-live-run-per-workflow-ID refusal (see *Thread-first continuation* below)
blocks any new turn until the pending approvals are settled.

### Thread-first continuation: the thread IS the workflow ID

The Agent declares its **threadId as the run's workflow ID** (`workflowId()`), so the
run's durable records live in the partition named by the thread itself. There
is no pointer and no index: the approve/deny endpoint needs only the thread
id, one read answers "is a run in flight here", and nothing about execution
identity ever touches chat history:

```php
// New execution cycle: nothing stored anywhere by the application — the
// thread IS the workflow ID. The history is constructed WITHOUT identity; the
// framework binds the resolved threadId into it.
$agent = Agent::make(threadId: $threadId)
    ->setChatHistory(new SQLChatHistory($pdo))
    ->setPersistence($persistence);

$agent->resume([ResumeInput::event($suspensionId, ['call_123' => 'approve'])]);
// (a pre-bound history — new SQLChatHistory($pdo, $threadId) — declares the
// same identity by adoption and works identically)
```

This works for **every** suspension type — approval, `awaitEvent()`,
`sleepUntil()` — because it is an engine mechanism, not an approval one. One
live run per thread, enforced by the engine: a new `chat()` while a run is
suspended on the thread is **refused** loudly ("run in flight for workflow ID");
settle the pending run first — typically `resume()` with decline decisions.
A continuation that can identify no run at all (no workflow ID, nothing in
flight) throws a `WorkflowException` rather than running against the wrong
one.

The declared workflow ID is `null` while no thread identity has been declared —
the run then lives under an engine-generated workflow ID (`getWorkflowId()` after the
first segment) and the threadId, if any, arrives from the ignition record.
See `src/Workflow/AGENTS.md` for the workflow ID model and the identity truth
table.

## Ignition & thread identity

The **Agent owns its thread identity**: `Agent::getThreadId(): ?string` is a
nullable slot assigned exactly once through a single door (`adoptThreadId()`,
mirroring the engine's identity phase). The framework **never generates** a
thread identity — it is always a developer statement, and a run without one
is simply not findable by its thread (`workflowId()` null, generated workflow ID, no
`threadId` in the ignition record).

**Collaborators are bound, not identity-constructed.** Chat histories are
thread-scoped by nature but constructible *without* their thread
(`ChatHistoryInterface::setThreadId()` / `getThreadId(): ?string`; loading is
lazy): the Agent binds the resolved identity into an unbound history before
first use. Identity therefore never appears in wiring code — not in hooks,
not in setters:

```php
class SupportAgent extends Agent
{
    protected function chatHistory(): ChatHistoryInterface
    {
        return new SQLChatHistory($this->pdo, contextWindow: 50000);   // identity: not your job
    }
}

// Fresh turn (a controller): identity enters through the ONE front door.
SupportAgent::make(threadId: $threadId)->chat(new UserMessage($input));

// Thread-first resume (approve endpoint): same statement.
SupportAgent::make(threadId: $threadId)->resume([
    ResumeInput::event($suspensionId, ['call_123' => 'approve']),
]);

// WorkflowId-first resume (background wake): the record supplies the thread
// identity — the developer writes nothing.
SupportAgent::make(workflowId: $ticket->workflowId)->resume(
    [ResumeInput::fromArray($ticket->input)],
    expectedRunId: $ticket->runId,
);
```

Identity sources, in precedence order — any two disagreeing non-null claims
**throw** (`AgentException`):

1. **Explicit**: `Agent::make(threadId: 'thread-42')`.
2. **Adoption from a pre-bound history**: `setChatHistory(new SQLChatHistory($pdo, 'thread-42'))`
   declares the history's key as the agent's identity.
3. **The ignition record** (workflowId-first resume): `applyIgnitionContext()`
   adopts the recorded threadId — adoption validates, so a record
   contradicting an explicitly claimed identity throws (a misidentified
   continuation). The engine's own check fires even earlier: a declared
   threadId disagreeing with an explicit `make(workflowId:)` is refused at
   identity resolution.

**Thread-findability requires identity declared before the run starts** (the two
sources above, or the record on a resume). A pre-bound *hook* history (the
in-memory default self-keying, or a subclass hook choosing a fixed key) is
adopted and validated when it materializes during bootstrap — but that is
after the workflow ID is resolved and the ignition record written, so it does
not make the run findable by its thread. `getThreadId()` is a pure read of the
slot; hooks may consult it freely (null on anonymous runs).

The moment identity resolves, the Agent binds it into an unbound history
(`setThreadId()`, itself conflict-guarded: re-pointing a bound history at a
different thread always throws). A durable history *used* while unbound
fails loudly ("thread-scoped and no thread identity was given").

Every durable run persists its **ignition record** at first execution: the
runId (generation stamp), the start event (messages + intent), plus the
Agent's context bag (`['threadId' => ...]`, read from the identity slot;
omitted when anonymous). That is what makes a suspended run continuable from
a **blank process** — a factory that knows only the workflow ID.

- `setChatHistory()` accepts a `ChatHistoryInterface` (pre-bound = identity
  declaration; unbound = the framework binds). `setChannel()` accepts a
  concrete channel or null.
- **Adapted stream delivery**: a `StreamingChannelInterface` has two ports —
  `send(object)` for native chunks and `sendLine(string)` for protocol lines. With no
  adapter attached, the channel receives native chunks via `send()`. Attach a stream
  adapter via `setStreamAdapter($adapter)` and the workflow runs each yielded chunk through
  it once. Pull iteration yields the resulting lines (including the adapter's
  `start()`/`end()` framing), and an attached channel receives the same lines via
  `sendLine()`. The adapter decides the output shape; the channel independently decides
  the destination (Pusher, websocket, …). An adapter is stateful for one stream.
- **Persisted-wins**: on a resume the recorded start event and context win over
  the factory's defaults. Editing `instructions()` between suspend and resume
  does not affect the resumed run — the record is the run's contract; the
  factory supplies capability (provider, tools, history), the record supplies
  intent.

**Security note — threadId is untrusted input used as a storage key.** The
frontend-supplied threadId selects which conversation is read, written, and
resumed. Authorize user ↔ thread ownership BEFORE opening a history with it;
the framework performs no access control.
