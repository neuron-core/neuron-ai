# Stream Adapters

This directory contains the protocol boundary between Neuron's native streamed
objects and UI transports such as AG-UI and the Vercel AI SDK data stream.

## Ownership and layering

`StreamAdapterInterface` currently lives under `Chat` for public API
compatibility, but the capability is consumed by the underlying `Workflow`:

- a node may yield any object as intermediate, live output;
- `Workflow::events()` exposes those objects to pull consumers;
- a workflow with a streaming channel and `setStreamAdapter()` converts them to
  protocol lines before push delivery;
- `Agent::stream($messages, $adapter)` uses the same adapter contract for pull
  delivery.

Do not combine changes to this feature with a namespace move. A namespace move
would be a separate breaking-change decision. Keep the portable abstraction free
of Agent-specific and memory-specific concepts so custom workflows can use it.

## Current adapter contract

`StreamAdapterInterface` has three operations:

- `start()` emits optional protocol framing;
- `transform(object $chunk)` converts one yielded object into zero or more
  strings;
- `end()` emits optional protocol termination.

Adapters are stateful for one stream. Never share one adapter instance between
two concurrent streams, or between pull and push delivery.

`SSEAdapter` owns only common SSE formatting and ID generation. Protocol state
and protocol payloads belong in the concrete adapters.

### Existing protocol behavior

| Adapter | Run/message framing | Native chunks |
|---|---|---|
| `AGUIAdapter` | `RUN_STARTED` / `RUN_FINISHED`, with separate text and reasoning lifecycles | text, reasoning, tool arguments, tool calls, tool results |
| `VercelAIAdapter` | lazy `start`, then `finish` and `[DONE]` | text, reasoning, tool arguments, tool calls, tool results |

Unknown objects are currently ignored. Preserve that behavior for objects that
are neither recognized portable stream events nor explicitly mapped developer
events.

## Portable stream events

Nodes should be able to yield protocol-neutral UI information without importing
AG-UI or Vercel types:

```text
workflow domain event
        | exact-class mapper (optional)
        v
portable StreamEventInterface
        | concrete adapter
        v
AG-UI event or Vercel data part
```

The initial public value objects belong in the dedicated adapter namespace
`NeuronAI\Chat\Messages\Stream\Adapters\Events`:

- `StreamEventInterface` — marker for portable, intermediate UI events;
- `StepStartedStreamEvent` — a named operation began;
- `StepFinishedStreamEvent` — a named operation completed;
- `ActivityStreamEvent` — a replaceable progress/activity snapshot;
- `CustomStreamEvent` — a named escape hatch with a JSON-serializable value.

These objects are stream output, not workflow routing events. A generator node
may `yield` them and must still `return` its normal `Workflow\Events\Event` for
graph traversal.

Keeping them under `Adapters` makes their ownership explicit: they are the
portable input contract adapters know how to encode, not provider stream chunks
or workflow routing events. The optional mapping-capability interface remains in
the parent `Adapters` namespace because it describes adapter behavior rather
than an event value.

### Developer mapping API

`CustomizableStreamAdapterInterface` adds mapping without changing
`StreamAdapterInterface`, so existing custom adapter implementations remain
compatible. Both built-in adapters implement it and share the exact-class
registry and resolution behavior.

The developer API is:

```php
$adapter->mapEvent(
    IndexingProgress::class,
    static fn (IndexingProgress $event): ActivityStreamEvent =>
        new ActivityStreamEvent(
            id: $event->jobId,
            type: 'indexing',
            data: ['processed' => $event->processed, 'total' => $event->total],
        ),
);
```

Mapping rules:

1. A yielded `StreamEventInterface` is encoded directly.
2. An exact-class developer mapping is resolved next. Do not use inheritance or
   first-match rules; exact classes make behavior predictable.
3. Existing native chunk conversion runs next.
4. Any remaining object is ignored, preserving current behavior.

A mapper returns `StreamEventInterface|null`. `null` means the explicitly mapped
event is suppressed. Internally, mapping resolution must distinguish "no mapping"
from "a mapping returned null" so suppression cannot fall through to built-in
chunk handling.

Mapper callbacks never return SSE strings, JSON, or protocol-specific arrays.
The adapter remains the single owner of wire format, escaping, framing, and
protocol evolution. If an object implements `StreamEventInterface` but the
adapter cannot encode its concrete type, fail with an expressive exception
instead of silently dropping a developer-declared portable event.

### Protocol conversions

| Portable event | AG-UI | Vercel AI SDK |
|---|---|---|
| `StepStartedStreamEvent` | `STEP_STARTED` | transient `data-workflow-step` with `status: started` |
| `StepFinishedStreamEvent` | `STEP_FINISHED` | transient `data-workflow-step` with `status: finished` |
| `ActivityStreamEvent` | `ACTIVITY_SNAPSHOT` | transient `data-workflow-activity` |
| `CustomStreamEvent` | `CUSTOM` | transient `data-{name}` |

Protocol-neutral value objects must not contain protocol field names. Each
adapter translates semantic fields to the names required by its protocol.

## Protocol lifecycle invariants

### AG-UI

- `RUN_STARTED` is the first frame and `RUN_FINISHED` is the last frame.
- A text or reasoning lifecycle must close before another incompatible lifecycle
  begins.
- Step, activity, and custom events are run-level events. They must not create,
  close, or reuse text message state.
- Preserve the configured thread ID and run ID on run framing.

### Vercel AI SDK

- A custom or portable event may be the first yielded item.
- Never read `messageId` before confirming the object is a message-bearing native
  chunk. The current lazy-start code does this and therefore cannot safely accept
  a custom object before model output; the implementation must fix it.
- Emit `start` exactly once, immediately before the first message-bearing native
  chunk. Portable transient data can be emitted before it without inventing a
  message.
- Step, activity, and custom conversions are transient UI data and must not be
  appended to the persisted assistant message.
- Preserve the existing `finish` and `[DONE]` termination sequence.

## Durability and replay

Yielded stream items are live, ephemeral output. They are not stored in workflow
persistence and are not replayed when a completed step is restored. Only the
generator's returned routing event is the durable step result.

This has two consequences:

- do not claim that a client reconnect will receive past progress events;
- do not make correctness depend on receiving a step/activity event.

These events improve visibility and UX; workflow state and returned events remain
the source of truth.

## Memory integration

Semantic memory is a consumer of this general feature, not part of the adapter
layer. `RecallMemoryNode` and `StoreMemoryNode` yield named step events around
recall and storage. Adapters never import memory node classes or use hardcoded
memory branches.

## Verification expectations

Coverage must preserve:

- direct portable event conversion in both built-in adapters;
- exact-class mapping, mapped suppression, and mapper exceptions;
- unknown unmapped objects remaining ignored;
- a portable/custom event before the first Vercel native chunk;
- Vercel emitting `start` once when the first native chunk later arrives;
- AG-UI run and text/reasoning lifecycle ordering remaining valid;
- mixed native chunks and portable events preserving their original order;
- pull (`Agent::stream`) and push (`Workflow` channel) delivery using the same
  conversions;
- generator return events continuing to route the workflow and never being
  emitted as UI progress.
