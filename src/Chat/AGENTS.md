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

**Base**: `AbstractChatHistory` provides common logic.

### Replace-last rule (ADR 0003)

`addMessage()` recognizes an incoming `ToolCallMessage` whose tool callIds match the
current last message and **replaces** it instead of appending. This makes the
suspend-time write, each partial-resume update, and `ToolNode`'s replay re-add all converge
to a single message reflecting the latest approval state.

Backends that persist via `setMessages()` (File, InMemory) get this for free — the
whole history is rewritten on every add. Row-per-message backends (`SQLChatHistory`,
`EloquentChatHistory`) override `onMessageReplaced()` to update the last row.

Serialized tool entries carry two approval fields: `approval` (`pending`|`approved`|
`rejected`, or absent for a non-gated tool) and `approvalReason` (rejection-only). Old
stored histories without these keys deserialize as `null` (not gated).

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
