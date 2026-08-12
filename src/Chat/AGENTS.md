# Chat Module

Unified messaging layer. Used by Agent, RAG, and Providers.

## Messages (`Messages/`)

Base `Message` class manages content as `ContentBlock[]`:

```php
$message = new UserMessage([
    new TextContent('Analyze this:'),
    new ImageContent('https://...', SourceType::URL, MediaType::JPEG),
]);
```

| Class | Role |
|-------|------|
| `Message.php` | Base, manages `ContentBlock[]` |
| `SystemMessage` | System instructions, carries `SystemContent` blocks (cacheable via `->cache()`) |
| `UserMessage` | User input |
| `AssistantMessage` | AI response |
| `ToolCallMessage` | Tool invocation request — carries `ToolCall[]` (conversation data) |
| `ToolResultMessage` | Tool execution result — the same `ToolCall[]`, settled with results |

**Key methods**: `getContent()` (text only), `getContentBlocks()`, `addContentBlock()`

## Content Blocks (`Messages/ContentBlocks/`)

All implement `ContentBlock` interface:

| Block | Usage |
|-------|-------|
| `TextContent` | Plain text |
| `ImageContent` | Images (URL or base64) |
| `FileContent` | Documents (PDF, etc.) |
| `AudioContent` | Audio files |
| `VideoContent` | Video files |
| `ReasoningContent` | AI reasoning traces |

Source types: `SourceType::URL` or `SourceType::BASE64`

## Chat History (`History/`)

Implementations of `ChatHistoryInterface`:

| Class | Storage |
|-------|---------|
| `InMemoryChatHistory` | Array (testing) |
| `FileChatHistory` | JSON files |
| `SQLChatHistory` | PDO database (one row per message, keyed by `thread_id`) |
| `EloquentChatHistory` | Laravel Eloquent (one row per message, keyed by `thread_id`) |

**Base**: `AbstractChatHistory` provides common logic. Subclasses persist through one
protected no-op hook per primitive history mutation — append (`onNewMessage`), head-trim
(`onTrimHistory`), clear (`clear`) — or ignore the granular hooks and rewrite the whole
state via `setMessages()` (File, InMemory).

**Identity — histories are bound, not identity-constructed**:
`ChatHistoryInterface::setThreadId(string)` / `getThreadId(): ?string` (null until
bound). A history is thread-scoped by nature but constructible *without* its thread —
loading is **lazy** (deferred to first read/write), so the Agent can bind the resolved
identity into an unbound history before it is ever touched. The rules, implemented once
in `AbstractChatHistory`:

- `setThreadId()` is assign-once in effect: same id → no-op; a *different* id →
  `ChatHistoryException` (re-pointing a conversation at another thread is never
  legitimate).
- A durable backend **used** while unbound throws loudly ("thread-scoped and no thread
  identity was given") — never a silent read of a wrong, empty thread.
- Constructor identity is optional and positioned after required dependencies
  (`new SQLChatHistory($pdo, 'thread-1')`, `new EloquentChatHistory(Model::class, 't')`,
  `new FileChatHistory($dir, 'key')`): passing it *pre-binds* the history, which the
  Agent treats as an identity declaration (adoption). `InMemoryChatHistory` self-keys
  via `uniqid()` when none is given (its own storage default, not framework identity
  fabrication).

The framework's thread identity lives on the **Agent** (`Agent::getThreadId()`, see
`src/Agent/AGENTS.md`); the history's key is validated against it, never authoritative.

### Message alternation

A pure `UserMessage` can never directly follow a `ToolCallMessage` — the tool calls must be
answered by a `ToolResultMessage` first (which itself extends `UserMessage` and is the expected
continuation). Enforced by `HistoryTrimmer::validateAlternation()` (`ChatHistoryException`),
which runs on every `addMessage()` append and therefore also covers sequences loaded from
storage. This is pure sequence validation, independent of tool approval; note that a custom
`HistoryTrimmerInterface` implementation takes over this responsibility.

### Append-only history & tool approval

`addMessage()` always appends — the history has no update or replace operation, so a
direct `ChatHistoryInterface` implementation that appends is fully conformant.
Write-once convergence lives with the single writer, not the store: when approval-gated
tools are present, `ToolNode` writes the annotated `ToolCallMessage` (pending states)
exactly once, through a durable memoized write, **before** any approval suspend —
a resume or crash-replay pass skips the write instead of duplicating the
tail. With no gated tools nothing is written there at all: the call/result pair travels
as the next inference's inbound messages and commits together only after that provider
call succeeds, so a tool crash or a failed follow-up call can never leave a
dangling `tool_call` at the tail.

The `tool_call` message keeps its pending snapshot forever; the **final approval
outcomes** (approved/rejected + feedback + results) are recorded on the
`ToolResultMessage` that follows it. "Is approval pending?" = the thread tail is a
`tool_call` whose tools are pending.

(Replay convergence for message writes is handled by the agent nodes' memoized
history writes — see `src/Agent/AGENTS.md`.)

Tool entries are `ToolCall` value objects (`NeuronAI\Tools\ToolCall`) — pure
conversation data; executable tools never appear in messages. Serialized entries carry
three approval fields: `approval` (`pending`|`approved`|
`rejected`, or absent for a non-gated tool), `approvalReason` (outbound — why the tool is
asking for approval, declared by the tool or its attach-time policy), and `rejectReason`
(inbound — the approver's feedback, rejection-only). Old stored histories without these
keys deserialize as `null` (not gated).

A tool entry's `result` is a string, a content block array when the tool returned a
multimodal `ToolOutput`, or `{is_error: true, blocks: [...]}` when it returned an
error output (`ToolOutput::error()` — see `src/Tools/AGENTS.md`).
`AbstractChatHistory::deserializeToolResult()` discriminates on shape — the `is_error`
marker rebuilds an error `ToolOutput`, an array whose first element has a `type` key
rebuilds a plain `ToolOutput` through the shared content block deserializer, anything
else stays a string — so legacy stored histories deserialize unchanged (never carrying
the marker, they come back as non-error).

Chat history carries **no execution identity**. A suspended `ToolCallMessage` records
the pending approval snapshot — enough to *render* a pending approval from history
alone — and nothing about the workflow run that produced it. Reattaching to that run is
the engine's job: the Agent declares its threadId as the run's address, so the
run's durable records live under the thread itself (see
`src/Workflow/AGENTS.md`). Old stored histories may still carry a `run_id` /
`resume_token` metadata key; it deserializes into the generic metadata bag and is never
read.

### History Trimming

`HistoryTrimmer` reduces token count when history exceeds limits:
- Uses `TokenCounter` to estimate tokens
- Preserves system messages
- Removes oldest messages first

## Enums (`Enums/`)

- `ContentBlockType` - TEXT, IMAGE, FILE, AUDIO, VIDEO
- `SourceType` - URL, BASE64
- `MediaType` - common MIME types (JPEG, PNG, PDF, MP3, MP4, ...). Content blocks accept `string|MediaType` for `mediaType` and normalize to `string`, so custom MIME strings always work
- `MessageRole` - USER, ASSISTANT, SYSTEM, TOOL

## Stream (`Messages/Stream/`)

Streaming message chunks for real-time responses.

## Dependencies

None. Chat is self-contained.
