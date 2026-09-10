# Chat Module

The messaging layer shared by Agent, RAG and Providers: messages, content blocks, stream chunks and chat history. Self-contained.

## Messages are content blocks

`Message` manages `ContentBlock[]` (`TextContent`, `ImageContent`, `FileContent`, `AudioContent`, `VideoContent`, `ReasoningContent`, `SystemContent`), so multimodality is native rather than bolted on. `getContent()` is the text-only view; `getContentBlocks()` is the truth.

```php
new UserMessage([
    new TextContent('Analyze this:'),
    new ImageContent('https://...', SourceType::URL, MediaType::JPEG),
]);
```

`SystemMessage` carries `SystemContent` blocks; `->cache()` marks them for provider-side prompt caching. `ToolCallMessage` (an `AssistantMessage`) and `ToolResultMessage` (a `UserMessage`) carry the same `ToolCall[]`: pure conversation data, settled with results in the second. Executable tools never appear in messages (see `src/Tools/AGENTS.md`). Content blocks accept `string|MediaType` for the media type and normalize to string, so custom MIME types always work.

## Chat history

`AbstractChatHistory` implements the logic once; backends persist through one protected hook per primitive mutation, append (`onNewMessage`), head-trim (`onTrimHistory`, invoked before the in-memory history drops the trimmed head) and clear, or ignore the hooks and rewrite the whole state via `setMessages()` (File, InMemory). SQL and Eloquent backends store one row per message keyed by thread. `HistoryTrimmer` keeps the thread inside the context window by estimating tokens and dropping the oldest messages first.

### Trimming archives, it never deletes

The durable backends (SQL, Eloquent, File) keep the messages trimmed out of the context window: a trim stamps them with `archived_at` (a nullable column on the messages table, a key on the file entry) and loading reads only the unarchived ones, so the full transcript stays available to the application while the model sees the trimmed thread. `flushAll()` is the one destructive operation: it removes the whole thread, archived messages included. `InMemoryChatHistory` simply drops what it trims.

### Identity: histories are bound, not identity-constructed

A history is thread-scoped by nature but constructible *without* its thread: loading is lazy, so the Agent can bind the resolved thread ID into an unbound history before it is ever touched (`new SQLChatHistory($pdo)` in a hook, identity supplied once by `Agent::make(threadId:)`). The rules, implemented in `AbstractChatHistory`:

- `setThreadId()` is assign-once: the same id is a no-op, a different id throws `ChatHistoryException`. Re-pointing a conversation at another thread is never legitimate.
- A durable backend *used* while unbound throws loudly, never a silent read of a wrong, empty thread.
- Constructor identity is optional and positioned after the required dependencies (`new SQLChatHistory($pdo, 'thread-1')`); passing it pre-binds the history, which the Agent adopts as an identity declaration. `InMemoryChatHistory` self-keys when none is given.

Thread identity itself belongs to the Agent (`src/Agent/AGENTS.md`); the history only validates against it.

### Invariants

- **Alternation.** A plain `UserMessage` can never directly follow a `ToolCallMessage`: the calls must be answered by a `ToolResultMessage` first. `HistoryTrimmer::validateAlternation()` enforces it on every append, which also covers sequences loaded from storage; a custom `HistoryTrimmerInterface` takes over this responsibility.
- **Append-only.** `addMessage()` always appends; there is no update or replace, so a direct `ChatHistoryInterface` implementation that appends is fully conformant. Write-once convergence under crash replay is the *writer's* job, not the store's: agent nodes wrap history writes in durable memos (`src/Agent/AGENTS.md`).
- **Nothing dangles.** Messages commit only after the step that consumes them succeeds, so a failed provider call or a crashed tool never leaves a user message or tool call at the tail. The single exception is an approval-gated `ToolCallMessage`, written before the suspend so a cold process can render the pending approval from history alone.
- **Approval is recorded on messages.** The `tool_call` message keeps its pending snapshot forever; the final outcomes (approved/rejected, feedback, results) live on the `ToolResultMessage` that follows. "Is approval pending?" is answered by the thread tail alone. Serialized tool entries carry `approval`, `approvalReason` (outbound, why the tool asked) and `rejectReason` (inbound, the approver's feedback); entries stored without these keys deserialize as not gated.
- **No execution identity.** History records nothing about the workflow run that produced a message. Reattaching to a suspended run is the engine's job, keyed by the thread itself (`src/Workflow/AGENTS.md`).

A tool entry's `result` round-trips as a string, a content block array (a multimodal `ToolOutput`) or `{is_error: true, blocks: [...]}` for `ToolOutput::error()`; `deserializeToolResult()` discriminates on shape, so legacy stored histories come back unchanged.
