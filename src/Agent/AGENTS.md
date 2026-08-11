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
AgentStartEvent ──► StartNode ──► AIInferenceEvent ────────► ChatNode ──┐
 (messages+intent)  (births the   or StructuredInferenceEvent           ├─► ToolNode ⟲
                    inference     ────► StructuredOutputNode ───────────┘
                    event)
```

- The start event is **pure run data**: messages plus the inference intent
  fields (`stream`, `outputClass`, `maxTries`). Sugar methods only *record*
  intent (`setStream()` / `setStructuredOutput()`); none of them changes the
  graph or hard-constructs event classes.
- **`StartNode`** (the default `entryNodes()` chain) births the
  `AIInferenceEvent` from the definition (instructions cloned, tools
  injected) plus the start event's data, then derives the routed class via
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
| `stream($messages, $adapter?)` | Pull-stream: a `Generator` yielding chunks; `getReturn()` is the `AgentState`. Pass a `StreamAdapterInterface` (Vercel/AG-UI/SSE) to yield protocol-formatted lines. |
| `structured($messages, $class)` | Eager: returns the typed output |
| `resume($payload)` | Continues a suspended run, whatever its mode → `AgentState` |

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
$state = MyAgent::make()
    ->setChatHistory($history)
    ->setPersistence($persistence)
    ->resume(['call_123' => 'approve']);
echo $state->getMessage()->getContent();
```

**Sugar ignites; `resume()` continues.** A new turn is a new run; an answer
resumes the suspended one. The sugar methods take no resume payload —
continuation goes through `resume($payload)`, the same engine verb a plain
Workflow uses. The run's mode never needs restating on a resume: intent is
persisted in the ignition record (see *Ignition & thread identity* below).

`chat()`/`resume()` are eager and return `AgentState`; `stream()` is the
pull-stream verb (a `Generator`) — the same eager/lazy split as Workflow's
`run()` / `events()`. `AgentState::getMessage()` reads the final assistant
message off the stored provider response; `isInterrupted()` /
`getInterruptRequest()` surface an approval pause on the state itself, just
like a plain `WorkflowState`.

## Middleware (`Middleware/`)

Register via `$workflow->middleware(NodeClass::class, $middleware)`:

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
step-result event through `Workflow::restoreEventNode()` before it re-enters traversal,
and the Agent's override re-seeds `bootstrapTools()` on stripped inference/tool-call
events (idempotent — a live effective set is never touched); tool-contributing middleware
re-supply their own additions in `before()`. The node itself holds no tool registry. **Each tool declares** its
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
use NeuronAI\Workflow\Persistence\FilePersistence;

$agent = YouTubeAgent::make()
    ->setPersistence(new FilePersistence($directory));
    // + a durable ChatHistory
```

A resume delivers decisions as a **cumulative** payload keyed by tool callId — the entire
decision set, restated on every resume:

```php
$agent->resume(['call_123' => 'approve', 'call_456' => ['reject', 'too expensive']]);
```

A tool runs iff explicitly approved; silence is never consent. An incomplete payload
re-suspends, and partial decisions are deliberately **not** persisted anywhere —
accumulation lives with the caller (an app collecting decisions one at a time gathers
them itself before resuming). The latest payload wins, so decisions are revisable until
the set completes. The UI re-renders pending approvals by reading chat history (last
message, tools with `getApprovalState()`) — no workflow boot; final outcomes are read
from the `ToolResultMessage` that follows. The thread must stay **locked** until the
full decision set is delivered — thread integrity is the application's responsibility.
There is no agent-level guard: if a new turn slips through anyway, the chat history's
message-alternation rule rejects the `UserMessage` appended after the pending
`ToolCallMessage` with a `ChatHistoryException` (see `src/Chat/AGENTS.md`).

### Thread-first continuation: the correlation pointer

The Agent declares its **threadId as the run's correlation key**
(`correlationKey()`), so the engine records `threadId → runId` in workflow
persistence when the run ignites. The pointer records the *most recent* run for
the thread and is never removed — a completed thread reads as free because the
run's partition (with its ignition record) was deleted, and liveness is derived
at lookup (see `src/Workflow/AGENTS.md`). A `resume()` with no explicit runId
resolves the pointer — the approve/deny endpoint needs only the thread id, and
nothing about execution identity ever touches chat history:

```php
// New execution cycle: no runId stored anywhere by the application — the
// thread IS the address. The history is constructed WITHOUT identity; the
// framework binds the resolved threadId into it.
$agent = Agent::make(threadId: $threadId)
    ->setChatHistory(new SQLChatHistory($pdo))
    ->setPersistence($persistence);

$agent->resume(['call_123' => 'approve']);
// (a pre-bound history — new SQLChatHistory($pdo, $threadId) — declares the
// same identity by adoption and works identically)
```

This works for **every** suspension type — approval, `awaitEvent()`, `sleepUntil()` —
because it is an engine mechanism, not an approval one. An **explicit runId wins**
over the pointer: a non-null id is authoritative, and the lookup fires only when none
was given. A continuation that can address no run at all (no runId, no pointer bound)
throws a `WorkflowException` rather than running against the wrong one.

The correlation key is `null` while no thread identity has been declared — the run
must then be addressed by an explicit runId (run-first resume), and the threadId
arrives from the ignition record. See `src/Workflow/AGENTS.md` for the pointer's
lifecycle and the identity truth table.

## Ignition & thread identity

The **Agent owns its thread identity**: `Agent::getThreadId(): ?string` is a
nullable slot assigned exactly once through a single door (`adoptThreadId()`,
mirroring the engine's runId phase). The framework **never generates** a
thread identity — it is always a developer statement, and a run without one
is simply not thread-addressable (`correlationKey()` null, no pointer, no
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
SupportAgent::make(threadId: $threadId)->resume(['call_123' => 'approve']);

// Run-first resume (background wake): the record supplies the identity —
// the developer writes nothing.
SupportAgent::make(runId: $ticket->runId)->resume($ticket->payload);
```

Identity sources, in precedence order — any two disagreeing non-null claims
**throw** (`AgentException`):

1. **Explicit**: `Agent::make(threadId: 'thread-42')`.
2. **Adoption from a pre-bound history**: `setChatHistory(new SQLChatHistory($pdo, 'thread-42'))`
   declares the history's key as the agent's identity.
3. **The ignition record** (run-first resume): `applyIgnitionContext()`
   adopts the recorded threadId — adoption validates, so a record
   contradicting an explicitly claimed identity throws (a mis-addressed
   continuation).

**Addressability requires identity declared before the run starts** (the two
sources above, or the record on a resume). A pre-bound *hook* history (the
in-memory default self-keying, or a subclass hook choosing a fixed key) is
adopted and validated when it materializes during bootstrap — but that is
after the ignition record and pointer are written, so it does not make the
run thread-addressable. `getThreadId()` is a pure read of the slot; hooks may
consult it freely (null on anonymous runs).

The moment identity resolves, the Agent binds it into an unbound history
(`setThreadId()`, itself conflict-guarded: re-pointing a bound history at a
different thread always throws). A durable history *used* while unbound
fails loudly ("thread-scoped and no thread identity was given").

Every durable run persists its **ignition record** at first execution: the
start event (messages + intent) plus the Agent's context bag
(`['threadId' => ...]`, read from the identity slot; omitted when anonymous).
That is what makes a suspended run continuable from a **blank process** — a
factory that knows only the runId.

- `setChatHistory()` accepts a `ChatHistoryInterface` (pre-bound = identity
  declaration; unbound = the framework binds). `setChannel()` accepts a
  concrete channel or null.
- **Adapted push delivery**: a `StreamingChannelInterface` has two ports —
  `send(object)` for native chunks and `sendLine(string)` for protocol lines. With no
  adapter attached, the channel receives native chunks via `send()`. Attach a stream
  adapter via `setStreamAdapter($adapter)` and the workflow runs each yielded chunk through
  it, delivering the resulting lines (plus the adapter's `start()`/`end()` framing) to the
  channel via `sendLine()`. The adapter and the channel are independent — the adapter
  decides the output shape, the channel decides the destination (Pusher, websocket, …).
  The adapter is stateful; never share one instance between `setStreamAdapter()` and a pull
  consumer (`stream($message, $adapter)`). This is the push path only; the pull path keeps
  its per-call `stream($message, $adapter)` argument.
- **Persisted-wins**: on a resume the recorded start event and context win over
  the factory's defaults. Editing `instructions()` between suspend and resume
  does not affect the resumed run — the record is the run's contract; the
  factory supplies capability (provider, tools, history), the record supplies
  intent.

**Security note — threadId is untrusted input used as a storage key.** The
frontend-supplied threadId selects which conversation is read, written, and
resumed. Authorize user ↔ thread ownership BEFORE opening a history with it;
the framework performs no access control.
