# Chat Module

Unified messaging layer. Used by Agent, RAG, and Providers.

## Messages (`Messages/`)

Base `Message` class manages content as `ContentBlock[]`:

```php
$message = new UserMessage([
    new TextContent('Analyze this:'),
    new ImageContent('https://...', SourceType::URL, 'image/jpeg'),
]);
```

| Class | Role |
|-------|------|
| `Message.php` | Base, manages `ContentBlock[]` |
| `SystemMessage` | System instructions, carries `SystemContent` blocks (cacheable via `->cache()`) |
| `UserMessage` | User input |
| `AssistantMessage` | AI response |
| `ToolCallMessage` | Tool invocation request |
| `ToolResultMessage` | Tool execution result |

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

### Message alternation

A pure `UserMessage` can never directly follow a `ToolCallMessage` — the tool calls must be
answered by a `ToolResultMessage` first (which itself extends `UserMessage` and is the expected
continuation). Enforced by `HistoryTrimmer::validateAlternation()` (`ChatHistoryException`),
which runs on every `addMessage()` append and therefore also covers sequences loaded from
storage. This is pure sequence validation, independent of tool approval; note that a custom
`HistoryTrimmerInterface` implementation takes over this responsibility.

### Append-only history & tool approval (ADR 0006)

`addMessage()` always appends — the history has no update or replace operation, so a
direct `ChatHistoryInterface` implementation that appends is fully conformant. The
convergence of the approval flow's writers lives with the callers, not the store:
`ToolApproval` writes the annotated `ToolCallMessage` (pending states + resume token)
**once**, at suspend time, and `ToolNode` skips its own write when that message already
sits at the tail. Both use `ToolCallMessage::isSameToolCall()` — same ordered callIds —
as the identity check. Its safety proof is adjacency, not callId uniqueness (Gemini uses
the tool name as callId): two distinct `ToolCallMessage`s can never be adjacent in a
valid thread, so a `ToolCallMessage` at the tail is always this same logical message.

The `tool_call` message keeps its pending snapshot forever; the **final approval
outcomes** (approved/rejected + feedback + results) are recorded on the
`ToolResultMessage` that follows it. "Is approval pending?" = the thread tail is a
`tool_call` whose tools are pending.

(Replay convergence for message writes is handled by the agent nodes' memoized
history writes — see `src/Agent/AGENTS.md`.)

Serialized tool entries carry three approval fields: `approval` (`pending`|`approved`|
`rejected`, or absent for a non-gated tool), `approvalReason` (outbound — why the tool is
asking for approval, declared by the tool or middleware config), and `rejectReason`
(inbound — the approver's feedback, rejection-only). Old stored histories without these
keys deserialize as `null` (not gated).

The suspended `ToolCallMessage` also carries a `resume_token` metadata entry (ADR 0005) —
the handle to reattach to the suspended run, exposed via
`ToolCallMessage::getResumeToken(): ?string`. It is an opaque string here: the Chat module
knows nothing about workflows. Stamped by the `ToolApproval` middleware at suspend, it makes
chat history sufficient to *resume* an approval flow, not just render it. Old histories
deserialize it as `null`.

### History Trimming

`HistoryTrimmer` reduces token count when history exceeds limits:
- Uses `TokenCounter` to estimate tokens
- Preserves system messages
- Removes oldest messages first

## Enums (`Enums/`)

- `ContentBlockType` - TEXT, IMAGE, FILE, AUDIO, VIDEO
- `SourceType` - URL, BASE64
- `MessageRole` - USER, ASSISTANT, SYSTEM, TOOL

## Stream (`Messages/Stream/`)

Streaming message chunks for real-time responses.

## Dependencies

None. Chat is self-contained.
