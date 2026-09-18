# Stream Adapters

The protocol boundary between Neuron's native streamed objects and UI protocols such as AG-UI and the Vercel AI SDK data stream. `AgentChunkAdapter` is the same boundary for a destination that speaks no UI protocol.

## Ownership and layering

`StreamAdapterInterface`, `ProtocolEvent` and `SSEEncoder` live under `Workflow\Streaming` because the Workflow consumes the capability; the protocol-specific adapters live under `Agent\Adapters`. The Workflow-level contract must stay free of Agent- and memory-specific concepts so custom workflows can use it:

- a node may `yield` any object as live, intermediate output, and must still `return` its routing event;
- `Workflow::events()` exposes the native objects when no adapter is configured;
- `setStreamAdapter()` makes the Workflow convert output to `ProtocolEvent`s **once**: without a channel, pull consumers receive those events; with a channel, `events()` consumes them eagerly through `send(ProtocolEvent)` and returns the final state. A channel without an adapter receives only the segment lifecycle during iteration;
- `Agent::stream()` records streaming intent and returns the Workflow result: a final `AgentState` with adapter + channel, otherwise a lazy generator.

An adapter is stateful for one stream; never share an instance between concurrent streams. An adapter owns its protocol vocabulary and state, and nothing about bytes: `ProtocolEvent` carries a `type` and a JSON-serializable `data` payload (`jsonSerialize()` places the type first), and `SSEEncoder::encode()` / `frame()` turn events into `data:` lines at the HTTP edge, never inside the Workflow. The HTTP headers a protocol requires stay on the adapter (`getHeaders()`), because the Vercel header is part of that protocol's contract; ids come from `UniqueIdGenerator::generateId('msg_')`.

## Contract

`start()` (optional framing), `transform(object $chunk)` (one object → zero or more `ProtocolEvent`s), and one terminal per segment, selected by the Workflow from the segment's outcome: `end()` on completion, `interrupt(InterruptRequest $request)` on suspension (the single current `InterruptRequest`, encoded so the client learns what the run waits for; return `[]` when the protocol cannot express a pause) and `error(Throwable $error)` on failure (return `[]` when the protocol has no failure frames). The Workflow calls `error()` itself for failures during streamed execution or chunk transformation; the run is settled as failed exactly as for a failing node, the channel still receives `failed()` and the exception is rethrown to the caller. After `error()` an adapter emits no further frames. An `InterruptEvent` never reaches `transform()`.

`reset()` is the segment boundary: the Workflow calls it before every segment, so one instance can serve a suspension and its continuation in the same process. It drops the previous segment's stream state and keeps the seeded protocol identity and snapshot.

## Portable stream events

Nodes yield protocol-neutral UI information without importing AG-UI or Vercel types:

```text
workflow domain event → exact-class mapper (optional) → StreamEventInterface → AG-UI event | Vercel data part
```

`Events/` holds the portable value objects: `StepStartedStreamEvent`, `StepFinishedStreamEvent`, `ActivityStreamEvent` (a replaceable progress snapshot) and `CustomStreamEvent` (a named escape hatch with a JSON-serializable value). They are stream output, not workflow routing events, and contain no protocol field names; each adapter translates them (`STEP_STARTED` / `STEP_FINISHED` / `ACTIVITY_SNAPSHOT` / `CUSTOM` on AG-UI, transient `data-*` parts on Vercel).

`CustomizableStreamAdapterInterface::mapEvent()` (implemented once in `MapsStreamEvents`, used by every built-in adapter) lets a developer map their own domain events without touching the adapter:

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

Resolution order: a yielded `StreamEventInterface` is encoded directly; then an **exact-class** mapping (no inheritance or first-match rules, so behavior stays predictable); then built-in native chunk conversion; anything else is ignored. A mapper returning `null` suppresses the event, and the implementation distinguishes "no mapping" from "mapped to null" so suppression never falls through to chunk handling. Mappers return portable value objects, never `ProtocolEvent`s or protocol arrays: the adapter is the single owner of the protocol vocabulary, and framing belongs to the transport edge. A `StreamEventInterface` the adapter cannot encode fails with an expressive exception rather than being dropped silently.

## Protocol invariants

- **Failures**: no adapter puts `Throwable::getMessage()` on the wire. `RUN_ERROR`, the Vercel `error` part and the native `error` event carry the neutral text of the protected `errorMessage()` hook, which an application subclass overrides to expose what its clients may know.
- **AG-UI**: `RUN_STARTED` is first, `RUN_FINISHED` or `RUN_ERROR` last. Text uses the native message ID; reasoning uses a distinct ID and closes before text starts. Constructor `messages` and `state` seed the frontend snapshot. New streamed text, reasoning, activities, calls and results update this projection. Explicit interruptions emit `STATE_SNAPSHOT` and `MESSAGES_SNAPSHOT` before finishing. Approval proposals use `confirmation` interrupts with action IDs, metadata and a response schema; no executable tool call is published before approval. Argument deltas are buffered. Local calls publish with their results, and deferred calls publish only from the persisted `ToolResultsRequest` in `interrupt()`. A deferred tool request finishes normally so CopilotKit can return tool messages; a custom wait uses a standard interrupt and explicit resume. Other branch requests wait their turn inside Workflow. Seeded calls and results are not echoed again.
- **Vercel**: `start` is lazy and carries a non-null message ID. Constructor `messageId` and `parts` retain the latest assistant message on continuation. Text and reasoning use stable part IDs with start/delta/end lifecycles; an inference following tool results starts a new UI step. `ToolCallChunk` and argument deltas preview input without triggering execution. Only `interrupt(ToolResultsRequest)` emits `tool-input-available`; approval emits a preview followed by `tool-approval-request`. Local results settle preview parts directly. Errors use `tool-output-error`, rejections use `tool-output-denied`, and known frontend outputs are not echoed or converted to strings. Pending parts already in `input-available` are not redispatched on partial continuation. Other waits remain transient `data-workflow-interrupt`. Success closes parts and finishes with `finish`; errors close parts and end with `error`. The SSE `[DONE]` sentinel is not emitted: the AI SDK client discards it and non-SSE transports never carry it. For native application payloads, continue through `Agent::submitApprovalDecisions($decisions)->events()` or `Agent::submitToolResults($results)->events()`. Raw AG-UI and Vercel envelopes use `submitInputs()` with the corresponding protocol translator. See `src/Agent/Frontend/README.md` for the combined automatic-continuation predicate needed for mixed batches.
- **Native**: `AgentChunkAdapter` is Neuron's own vocabulary for a consumer that speaks no UI protocol, typically a custom frontend behind a channel. It is stateless and one-to-one: a chunk becomes one event named after its kind (`text`, `reasoning`, `image`, `audio`, `tool-argument`, `tool-call`, `tool-result`) whose payload is the chunk's own `toArray()`, and the portable events become `step-started`, `step-finished`, `activity` and `custom`. It frames nothing on start or completion, because a channel already reports the segment's end through its lifecycle; `interrupt()` emits one `interrupt` event and `error()` one `error` event. `ProtocolEvent` flattens its payload beside `type`, so a payload never carries a `type` of its own: the activity's type travels as `activityType` and the `InterruptRequest` stays nested under `request`. It is the only built-in adapter that carries `ImageChunk` and `AudioChunk`. Attaching it is always explicit: a channel without an adapter still receives only the lifecycle.
- The AG-UI and Vercel adapters preserve tool call IDs across fresh instances. They reindex yielded frames so default `iterator_to_array()` cannot overwrite frames from delegated generators, and terminal methods suppress subsequent output until `reset()` opens the next segment.

## Durability

Yielded items are live, ephemeral output: they are not stored in workflow persistence and are not replayed when a completed step is restored. Only the generator's returned routing event is durable. Never promise that a reconnecting client sees past progress events, and never make correctness depend on receiving one.
