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

`start()` (optional framing), `transform(object $chunk)` (one object → zero or more strings), `end()` (termination on completion or suspension) and `error(Throwable $error)` (failure frames instead of `end()`; return `[]` when the protocol has none). The Workflow calls `error()` itself for failures during streamed execution or chunk transformation; the channel still receives `failed()` and the exception is rethrown to the caller. After `error()` an adapter emits no further frames.

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

- **AG-UI**: `RUN_STARTED` is the first frame, `RUN_FINISHED` or `RUN_ERROR` the last; a text or reasoning lifecycle closes before an incompatible one begins; step, activity and custom events are run-level and never create, close or reuse text message state; the configured thread and run IDs are preserved on run framing.
- **Vercel**: `start` is emitted exactly once, lazily, right before the first message-bearing native chunk, so a portable event may legitimately be the first yielded item; portable conversions are transient data never appended to the persisted assistant message; success ends with `finish` then `[DONE]`, failure with `error` then `[DONE]`.

## Durability

Yielded items are live, ephemeral output: they are not stored in workflow persistence and are not replayed when a completed step is restored. Only the generator's returned routing event is durable. Never promise that a reconnecting client sees past progress events, and never make correctness depend on receiving one.

Semantic memory is a consumer of this feature, not part of it: `RecallMemoryNode` and `StoreMemoryNode` yield `memory.recall` / `memory.store` step events, and adapters never import memory classes.
