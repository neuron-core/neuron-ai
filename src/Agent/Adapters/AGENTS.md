# Stream Adapters

The protocol boundary between Neuron's native streamed objects and UI transports such as AG-UI and the Vercel AI SDK data stream.

## Ownership and layering

`StreamAdapterInterface` lives under `Chat` for public API compatibility, but the capability is consumed by `Workflow`, and it must stay free of Agent- and memory-specific concepts so custom workflows can use it:

- a node may `yield` any object as live, intermediate output, and must still `return` its routing event;
- `Workflow::events()` exposes the native objects when no adapter is configured;
- `setStreamAdapter()` makes the Workflow convert output to protocol lines **once**: pull consumers receive those lines, and an attached `StreamingChannelInterface` receives the same lines through `sendLine()`;
- `Agent::stream()` only records streaming intent and yields Workflow output.

An adapter is stateful for one stream; never share an instance between concurrent streams. `SSEAdapter` owns only common SSE formatting and ID generation; protocol state and payloads belong to the concrete adapters.

## Contract

`start()` (optional framing), `transform(object $chunk)` (one object → zero or more strings), and one terminal per segment, selected by the Workflow from the segment's outcome: `end()` on completion, `suspended(array $requests)` on suspension (the active `InterruptRequest`s keyed by interrupt ID, encoded so the client learns what the run waits for; return `[]` when the protocol cannot express a pause) and `error(Throwable $error)` on failure (return `[]` when the protocol has no failure frames). The Workflow calls `error()` itself for failures during streamed execution or chunk transformation; the channel still receives `failed()` and the exception is rethrown to the caller. After `error()` an adapter emits no further frames. An `InterruptEvent` never reaches `transform()`.

## Portable stream events

Nodes yield protocol-neutral UI information without importing AG-UI or Vercel types:

```text
workflow domain event → exact-class mapper (optional) → StreamEventInterface → AG-UI event | Vercel data part
```

`Events/` holds the portable value objects: `StepStartedStreamEvent`, `StepFinishedStreamEvent`, `ActivityStreamEvent` (a replaceable progress snapshot) and `CustomStreamEvent` (a named escape hatch with a JSON-serializable value). They are stream output, not workflow routing events, and contain no protocol field names; each adapter translates them (`STEP_STARTED` / `STEP_FINISHED` / `ACTIVITY_SNAPSHOT` / `CUSTOM` on AG-UI, transient `data-*` parts on Vercel).

`CustomizableStreamAdapterInterface::mapEvent()` (implemented once in `MapsStreamEvents`, used by both built-in adapters) lets a developer map their own domain events without touching the adapter:

```php
$adapter->mapEvent(
    IndexingProgress::class,
    static fn (IndexingProgress $event): ?ActivityStreamEvent => new ActivityStreamEvent(
        id: $event->jobId,
        type: 'indexing',
        data: ['processed' => $event->processed, 'total' => $event->total],
    ),
);
```

Resolution order: a yielded `StreamEventInterface` is encoded directly; then an **exact-class** mapping (no inheritance or first-match rules, so behavior stays predictable); then built-in native chunk conversion; anything else is ignored. A mapper returning `null` suppresses the event, and the implementation distinguishes "no mapping" from "mapped to null" so suppression never falls through to chunk handling. Mappers return value objects, never SSE strings or protocol arrays: the adapter is the single owner of wire format, escaping and framing. A `StreamEventInterface` the adapter cannot encode fails with an expressive exception rather than being dropped silently.

## Protocol invariants

- **AG-UI**: `RUN_STARTED` is first, `RUN_FINISHED` or `RUN_ERROR` last. Text uses the native message ID; reasoning uses a distinct ID and closes before text starts. Constructor `messages` and `state` seed the frontend snapshot. New streamed text, reasoning, activities, calls and results update this projection. Explicit interruptions emit `STATE_SNAPSHOT` and `MESSAGES_SNAPSHOT` before finishing. Approval proposals use `confirmation` interrupts with action IDs, metadata and a response schema; no executable tool call is published before approval. Argument deltas are buffered. Local calls publish with their results, and deferred calls publish only from the persisted `ToolResultsRequest` in `suspended()`. A suspension containing only deferred waits finishes normally so CopilotKit can return tool messages; mixed/custom waits use standard interrupts and explicit resume. Seeded calls and results are not echoed again.
- **Vercel**: `start` is lazy and carries a non-null message ID. Constructor `messageId` and `parts` retain the latest assistant message on continuation. Text and reasoning use stable part IDs with start/delta/end lifecycles; an inference following tool results starts a new UI step. `ToolCallChunk` and argument deltas preview input without triggering execution. Only `suspended(ToolResultsRequest)` emits `tool-input-available`; approval emits a preview followed by `tool-approval-request`. Local results settle preview parts directly. Errors use `tool-output-error`, rejections use `tool-output-denied`, and known frontend outputs are not echoed or converted to strings. Pending parts already in `input-available` are not redispatched on partial continuation. Other waits remain transient `data-workflow-interrupt`. Success closes parts and finishes with `[DONE]`; errors close parts and end with error plus `[DONE]`. See `src/Agent/Frontend/README.md` for the combined automatic-continuation predicate needed for mixed batches.
- Both adapters preserve tool call IDs across fresh instances. They reindex yielded frames so default `iterator_to_array()` cannot overwrite frames from delegated generators, and terminal methods suppress subsequent output.

## Durability

Yielded items are live, ephemeral output: they are not stored in workflow persistence and are not replayed when a completed step is restored. Only the generator's returned routing event is durable. Never promise that a reconnecting client sees past progress events, and never make correctness depend on receiving one.

Semantic memory is a consumer of this feature, not part of it: `RecallMemoryNode` and `StoreMemoryNode` yield `memory.recall` / `memory.store` step events, and adapters never import memory classes.
