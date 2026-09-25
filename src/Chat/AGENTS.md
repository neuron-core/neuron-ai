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

`SystemMessage` carries `SystemContent` blocks; `->cache()` marks them for provider-side prompt caching. A cloned message owns copies of its blocks, so a copy can be edited in place without touching the original (the Agent entry nodes rely on this to isolate a run's instructions from the agent configuration). `ToolCallMessage` (an `AssistantMessage`) and `ToolResultMessage` (a `UserMessage`) carry the same `ToolCall[]`: pure conversation data, settled with results in the second. Executable tools never appear in messages (see `src/Tools/AGENTS.md`). Content blocks accept `string|MediaType` for the media type and normalize to string, so custom MIME types always work.

## Chat history

Two parts with different lifetimes:

- `MessageStoreInterface` stores conversations. Every call names its thread (`loadActive()`, `loadAll()`, `append()`, `archive()`, `clear()`), so a store keeps no conversation state and one instance serves the whole process: bind it once in a container. `SQLMessageStore` and `EloquentMessageStore` store one row per message with a `message_id` unique within its thread; the table key orders the thread, so an Eloquent key must follow insertion order (auto-increment, ULID or UUIDv7). `FileMessageStore` keeps one JSON file per thread, replaced atomically on every write, for controlled single-host use. `InMemoryMessageStore` lives in process memory. Constructing a store performs no I/O.
- `ChatHistory` is the working context of one conversation for one execution segment: it receives its thread at construction, loads the active messages on first use, keeps them inside the context window with `HistoryTrimmer` (`DEFAULT_CONTEXT_WINDOW`, 50,000 tokens, unless configured), and writes through the store. It is concrete, like Workflow's `WorkflowRunStore` over `PersistenceInterface`: storage varies through the store and trimming through `HistoryTrimmerInterface`, which a composed workflow passes to the constructor (one instance per history, since trimmers are stateful). Open a new history per segment, as the Agent does (`src/Agent/AGENTS.md`); thread identity belongs to the Agent.

A message's identity is `Message::getId()`: assigned at construction, stored with the message and restored on load; `setMetadata()` keeps it. `Message::jsonSerialize()` keeps the message's own fields at the top level (`__id`, `role`, `content`, `usage`, and `type` and `tools` on tool messages) and nests the metadata under `__meta`, so no metadata key can collide with them. `MessageDeserializer` rebuilds messages from that shape for stores and `Trajectory`. It also reads the flat shape of earlier versions, where the metadata sat beside the fields, because stored rows are never rewritten.

### Trimming archives, it never deletes

When the trimmer drops the oldest messages from the context, `ChatHistory` archives them in the store instead of deleting them: `archived_at` on the row or file entry, a counted prefix in memory. `loadActive()` returns the unarchived messages; `loadAll()` returns the whole transcript, optionally the `$limit` messages before a message ID, which is how a UI pages backward. `flushAll()` is the one destructive operation: it clears the whole thread, archived messages included. The `Summarization` middleware compacts through `flushAll()`, so summarizing a thread erases its transcript.

`calculateTotalUsage()` measures the active messages on demand, so a freshly loaded history reports its real size.

### Invariants

- **Alternation.** A plain `UserMessage` can never directly follow a `ToolCallMessage`: the calls must be answered by a `ToolResultMessage` first. `HistoryTrimmer::validateAlternation()` enforces it on every append, which also covers sequences loaded from storage; a custom `HistoryTrimmerInterface` takes over this responsibility.
- **Append-only, idempotent by identity.** `addMessage()` always appends; there is no update or replace. A message whose ID is already in the context is skipped, and a store skips a message already stored in the thread, so a replayed write converges even when its durable memo was lost (agent nodes also wrap history writes in memos, `src/Agent/AGENTS.md`). The trim validates the sequence before anything is stored.
- **One writer per segment.** Archiving counts from the oldest active message. The count is correct because a working history loads fresh for its segment and the workflow run fence admits one active segment per conversation.
- **Nothing dangles.** Messages commit only after the step that consumes them succeeds, so a failed provider call or a crashed tool never leaves a user message or tool call at the tail. The single exception is an approval-gated `ToolCallMessage`, written before the suspend so a cold process can render the pending approval from history alone.
- **Approval is recorded on messages.** The `tool_call` message keeps its pending snapshot forever; the final outcomes (approved/rejected, feedback, results) live on the `ToolResultMessage` that follows. "Is approval pending?" is answered by the thread tail alone. Serialized tool entries carry `approval`, `approvalReason` (outbound, why the tool asked) and `rejectReason` (inbound, the approver's feedback); entries stored without these keys deserialize as not gated.
- **No execution identity.** History records nothing about the workflow run that produced a message. Reattaching to a suspended run is the engine's job, keyed by the thread itself (`src/Workflow/AGENTS.md`).

A tool entry's `result` round-trips as a string, a content block array (a multimodal `ToolOutput`) or `{is_error: true, blocks: [...]}` for `ToolOutput::error()`; `MessageDeserializer` discriminates on shape, so legacy stored histories come back unchanged.
