# Agent Module

AI agent built on Workflow. Provides chat, streaming, and structured output modes.

**Dependencies**: `src/Workflow/AGENTS.md`, `src/Chat/AGENTS.md`, `src/Providers/AGENTS.md`, `src/Tools/AGENTS.md`

## Extension Pattern (Recommended)

Create a custom agent class extending `Agent`:

```php
use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\SystemPrompt;

class YouTubeAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: env('ANTHROPIC_API_KEY'),
            model: 'claude-sonnet-4-6',
        );
    }

    public function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ['You are an AI agent specialized in writing YouTube video summaries.'],
            steps: [
                'Get the URL of a YouTube video, or ask the user to provide one.',
                'Use the tools you have available to retrieve the transcription of the video.',
                'Write the summary.',
            ],
            output: [
                'Write a summary in a paragraph without using lists.',
                'After the summary add a list of three sentences as the most important takeaways.',
            ]
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

## Execution Modes

`Agent.php` composes a Workflow internally with specialized nodes:

| Method | Node | Description |
|--------|------|-------------|
| `chat()` | `ChatNode` | Standard inference, returns full response |
| `stream()` | `StreamingNode` | Yields chunks via generator |
| `structured()` | `StructuredOutputNode` | Extracts typed output via JSON schema |

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
```

## Middleware (`Middleware/`)

Register via `$workflow->middleware(NodeClass::class, $middleware)`:

| Middleware | Purpose |
|------------|---------|
| `ToolApproval` | Human-in-the-loop for tool execution |
| `TodoPlanning` | Injects todo planning capabilities |
| `Summarization` | Adds conversation summarization |

Node matching is subclass-aware (`instanceof`), so middleware registered for a
class also applies to its subclasses. `ChatNode`, `StreamingNode`, and
`StructuredOutputNode` are siblings — the Agent instantiates exactly one per
mode (`chat()` / `stream()` / `structured()`). They all share the
`InferenceNode` base class, so register mode-agnostic inference middleware
(`Summarization`, `TodoPlanning`, `ToolSearchMiddleware`) against
`InferenceNode::class` to have it fire in **all three** modes rather than only
the one whose node class you named.

### `AgentMiddleware` — typed hooks for the agent context

The generic `WorkflowMiddleware` contract receives `NodeInterface`/`WorkflowState`
(the Workflow layer cannot know agent types, and PHP parameter contravariance
forbids narrowing in a subclass). `AgentMiddleware` centralizes the runtime
narrowing once (template method): extend it and implement
`beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state)` /
`afterAgentNode(...)` with real types. When the middleware is attached outside
the agent context, `onAgentContextMismatch($node, $event, $state)` fires instead —
empty by default; middleware whose silent skip would be a safety hazard override
it to throw (`ToolApproval` does).

Agent middleware access the chat history through the node they wrap
(`$node->getChatHistory()`), **never** through their own constructor — so they can
only ever see the exact instance the node reads and writes.

## Chat history is a service, not state

The chat history is bound to its own storage (SQL, file, memory) and is injected
into agent nodes as a **constructor dependency** (`AgentNodeInterface` exposes it).
It never travels through the durable workflow state: `AgentState` is pure
serializable data, so per-step snapshots stay O(1) instead of embedding the whole
conversation on every step (and PDO-backed histories never meet the serializer).
Consequences:

- Nodes and middleware read/write the **live** history, so a history write is a
  side effect like tool execution: `addToChatHistory()` wraps it in `memoize()`
  with a stable per-site name (`history.inbound`, `history.response`, ...), so a
  crash-replay recalls the memo and skips the write instead of duplicating the
  tail. (`ToolApproval`'s suspend-time write stays unmemoized by design — its
  per-pass re-writes converge via the ADR 0003 replace-last rule.)
- Durable workflow persistence requires a comparably durable chat history — an
  `InMemoryChatHistory` loses the thread across processes, so a cross-process
  resume reconstructs an incomplete prompt (the documented ADR 0003 requirement,
  now load-bearing).
- The final message of a run is read from the history (`AgentHandler::getMessage()`),
  not from state.
- `AgentState::getSteps()` reports the messages generated during the **current
  execution cycle** (available on the final state even when interrupted). The
  accumulator is transient — excluded from durable snapshots and reset when a
  replayed snapshot is restored — so a resumed run reports only the messages
  produced since the resume; the full thread lives in the chat history.

## Persistence & Tool Approval

`ToolApproval` gates tool execution behind human approval. Attach it to `ToolNode::class`
(subclass-aware matching covers `ParallelToolNode` too):

```php
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;

$agent->addMiddleware(ToolNode::class, new ToolApproval());
```

With no constructor config, **each tool decides** via its own `requiresApproval()`
declaration (ADR 0004); middleware config overrides that in both directions. Both the
declaration and a config callback return `bool|string` — a string counts as `true` and
doubles as the approval reason shown to the approver (persisted as `approvalReason` on the
tool entry in chat history, exposed on the `ApprovalRequest` actions as `reason`). When a gated
tool is requested, `chat()` returns suspended instead of completed — this requires **workflow
persistence AND a durable chat history** (chat history is the system of record for approval
state — ADR 0003). `InMemoryChatHistory` keeps the safety property but loses progress across
processes.

```php
use NeuronAI\Workflow\Persistence\FilePersistence;

$agent = YouTubeAgent::make()
    ->setPersistence(new FilePersistence($directory))
    // + a durable ChatHistory
    ->addMiddleware(ToolNode::class, new ToolApproval());
```

Resume delivers decisions as an **incremental** payload keyed by tool callId — only NEW
decisions, never a restatement:

```php
$agent->chat(payload: ['call_123' => 'approve', 'call_456' => ['reject', 'too expensive']]);
```

A tool runs iff explicitly approved; silence is never consent. Decisions are revisable
(last-write-wins) until the full set completes. The UI re-renders pending approvals by
reading chat history (last message, tools with `getApprovalState()`) — no workflow boot.
The thread must stay **locked** until every decision is delivered — thread integrity is the
application's responsibility (ADR 0003). There is no agent-level guard: if a new turn slips
through anyway, the chat history's message-alternation rule rejects the `UserMessage` appended
after the pending `ToolCallMessage` with a `ChatHistoryException` (see `src/Chat/AGENTS.md`).

### Resume token lives in chat history (ADR 0005)

At suspend, `ToolApproval` stamps the workflowId onto the annotated `ToolCallMessage`
(`ToolCallMessage::getResumeToken()`), so history alone is sufficient to **resume**, not just
to render. A payload-carrying `chat()`/`stream()`/`structured()` call with no explicit
resumeToken **adopts** the token from the history tail — the approve/deny endpoint needs only
the thread id:

```php
// New execution cycle: no workflowId stored anywhere by the application.
$agent = Agent::make()
    ->setChatHistory(new SQLChatHistory($threadId, $pdo))
    ->setPersistence($persistence)
    ->addMiddleware(ToolNode::class, new ToolApproval());

$agent->chat(payload: ['call_123' => 'approve']);
```

The stamp wins even over a `resumeToken` passed to `make()` — history is the system of
record. With no stamp on the tail the agent keeps its current workflowId, so explicit-token
resumes of non-approval suspensions work unchanged. The token is stamped on every middleware
pass and never stripped: once the message is no longer the tail it is inert.
