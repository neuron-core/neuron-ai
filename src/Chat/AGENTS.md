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
| `SQLChatHistory` | PDO database |
| `EloquentChatHistory` | Laravel Eloquent |

**Base**: `AbstractChatHistory` provides common logic.

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
