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

## The static graph & execution intent (ADR 0015)

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
| `chat()` | Ignites a run (buffered transport) |
| `stream()` | Ignites a run with stream intent (live chunks) |
| `structured()` | Ignites a run with structured intent; eager — returns the typed output |
| `wake($payload)` | Continues a suspended run, whatever its mode |

```php
// Chat
$response = YouTubeAgent::make()->chat(new UserMessage('Hello'))->getMessage();

// Streaming
$handler = YouTubeAgent::make()->stream(new UserMessage('Hello'));
foreach ($handler->events() as $chunk) {
    echo $chunk->content;
}
$response = $handler->getMessage();

// Structured output
$report = MyAgent::make()->structured($message, ReportSchema::class);

// Wake a suspended run (approval endpoint) — mode-agnostic
$message = MyAgent::make()
    ->setChatHistory($history)
    ->setPersistence($persistence)
    ->wake(['call_123' => 'approve'])
    ->getMessage();
```

**Sugar ignites; `wake()` resumes.** A new turn is a new run; an answer wakes
the suspended one. The sugar methods take no resume payload — continuation
goes through `wake($payload)` (handler ergonomics) or the engine verb
`resume($payload)`. The run's mode never needs restating on a wake: intent is
persisted in the ignition record (see *Ignition & thread identity* below).

## Middleware (`Middleware/`)

Register via `$workflow->middleware(NodeClass::class, $middleware)`:

| Middleware | Purpose |
|------------|---------|
| `TodoPlanning` | Injects todo planning capabilities |
| `Summarization` | Adds conversation summarization |

Middleware shapes events before a node acts; flow control and I/O belong to the nodes
themselves (ADR 0009 — tool approval, previously a middleware, now lives in `ToolNode`).

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
  inference's inbound write (ADR 0012) — a tool crash or failed follow-up call
  leaves the tail at the last committed message, never at a dangling tool call.
  Only an approval-gated cycle writes its `ToolCallMessage` early (pre-suspend,
  ADR 0009).
- Durable workflow persistence requires a comparably durable chat history
  (`InMemoryChatHistory` loses the thread across processes).
- `AgentHandler::getMessage()` reads the final message from the history;
  `AgentState::getSteps()` reports the current execution cycle's messages only
  (transient, available even on an interrupted final state).

## Persistence & Tool Approval

`ToolNode` gates tool execution behind human approval (ADR 0009) — there is no middleware
to attach; the gate runs on every tool call and asks each tool. Messages carry `ToolCall`
value objects (ADR 0010): the node resolves every call against ONE source — the inference
event's tool list, the cycle's effective set (agent base plus middleware additions, minus
middleware removals) — clones the match, binds the call's inputs, executes, and settles
the result back onto the call. A call naming a tool outside that set throws a
`ToolException`. Exceptions escaping tool execution are bugs and propagate (a
*conversational* failure is a returned `ToolOutput::error()`, ADR 0013 — see
`src/Tools/AGENTS.md`); `toolErrorHandler(fn (Throwable $e, ToolCall $call):
string|ToolOutput|null)` is the cross-cutting override — a returned string or
`ToolOutput` settles as the call's result, `null` declines and the exception
propagates. Event capability is transient in persistence: the executor passes every
step-result event through `Workflow::restoreEventNode()` before it re-enters traversal,
and the Agent's override re-seeds `bootstrapTools()` on stripped inference/tool-call
events (idempotent — a live effective set is never touched); tool-contributing middleware
re-supply their own additions in `before()`. The node itself holds no tool registry. **Each tool declares** its
intrinsic risk via the protected `approvalPolicy(array $inputs)` hook (ADR 0004's
"tools declare" survives), and the agent developer overrides the declaration per tool at
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
approval — ADR 0006).

```php
use NeuronAI\Workflow\Persistence\FilePersistence;

$agent = YouTubeAgent::make()
    ->setPersistence(new FilePersistence($directory));
    // + a durable ChatHistory
```

A wake delivers decisions as a **cumulative** payload keyed by tool callId — the entire
decision set, restated on every wake (ADR 0006):

```php
$agent->wake(['call_123' => 'approve', 'call_456' => ['reject', 'too expensive']]);
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

### The runId lives in chat history (ADR 0005)

Before suspending, `ToolNode` stamps the runId onto the annotated `ToolCallMessage`
(`ToolCallMessage::getRunId()`), so history alone is sufficient to **resume**, not just
to render. A `wake()` (or `resume()`) with no explicit runId **adopts** the id from
the history tail — the approve/deny endpoint needs only the thread id:

```php
// New execution cycle: no runId stored anywhere by the application.
$agent = Agent::make()
    ->setChatHistory(new SQLChatHistory($threadId, $pdo))
    ->setPersistence($persistence);

$agent->wake(['call_123' => 'approve']);
```

The stamp wins even over a `runId` passed to `make()` — history is the system of
record. With no stamp on the tail the agent keeps its current runId, so explicit-id
wakes of non-approval suspensions work unchanged. The id is stamped on every gated
pass and never stripped: once the message is no longer the tail it is inert.
Tail-adoption is skipped while a chat-history *resolver* is still pending (no thread
identity yet, so no tail to read) — there the explicit runId governs and the threadId
arrives from the ignition record.

## Ignition & thread identity (ADR 0015)

Every durable run persists its **ignition record** at first execution: the start
event (messages + intent) plus the Agent's context bag (`['threadId' => ...]`).
That is what makes a suspended run continuable from a **blank process** — a
factory that knows only the runId:

```php
// Ignition (a web request): identity set, resolvers materialize eagerly.
$agent = MyAgent::make()
    ->setPersistence($persistence)
    ->setChatHistory(fn (string $threadId) => new SQLChatHistory($threadId, $pdo))
    ->setChannel(fn (string $threadId) => new PusherChannel($threadId))
    ->setThreadId($threadId);

$agent->stream(new UserMessage($input));   // suspends on a gated tool

// Wake (another process, later): same factory, runId only — NO threadId.
$handler = MyAgent::make(runId: $runId)
    ->setPersistence($persistence)
    ->setChatHistory(fn (string $threadId) => new SQLChatHistory($threadId, $pdo))
    ->setChannel(fn (string $threadId) => new PusherChannel($threadId))
    ->wake($payload);
// The threadId is adopted from the ignition record before nodes are built;
// the resolvers materialize with it; the run continues in its recorded mode.
```

- `setThreadId()` / `getThreadId()` — one identity, three appearances:
  frontend threadId = chat history thread id = channel name.
- `setChatHistory()` / `setChannel()` accept a **resolver form** (a closure
  receiving `string $threadId`) for factories wired before the identity is
  known. Resolvers materialize exactly once, in either wiring order; a
  concrete instance clears a pending resolver.
- **Fail-loud guards**: with a resolver wired and no threadId,
  `getChatHistory()` throws (never silently falls back to the in-memory
  default — that would read and write a wrong, empty thread) and
  `bootstrap()` backstops the channel resolver.
- **Persisted-wins**: on a wake the recorded start event and context win over
  the factory's defaults. Editing `instructions()` between suspend and wake
  does not affect the woken run — the record is the run's contract; the
  factory supplies capability (provider, tools, history), the record supplies
  intent.

**Security note — threadId is untrusted input used as a storage key.** The
frontend-supplied threadId selects which conversation is read, written, and
resumed. Authorize user ↔ thread ownership BEFORE calling `setThreadId()` or
opening a history with it; the framework performs no access control.
