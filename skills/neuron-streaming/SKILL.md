---
name: neuron-streaming
description: Stream Neuron AI agent and workflow output to a consumer — iterating native chunks, yielding portable progress events from nodes, attaching a stream adapter for a UI protocol (Vercel AI SDK, AG-UI, SSE), and pushing output through a streaming channel when the consumer is not the HTTP response (queue worker, websocket, resumed run). Use this skill whenever the user mentions streaming, stream chunks, TextChunk, real-time responses, SSE, server-sent events, useChat, Vercel AI SDK, AG-UI, CopilotKit, stream adapters, streaming channels, pushing output to a websocket or Redis/Pusher, progress events from a workflow node, or testing streamed output. Also trigger for any task involving setStreamAdapter, setChannel, StreamAdapterInterface, StreamingChannelInterface, AbstractChannel, CallbackChannel, RedisChannel, PusherChannel, FakeChannel, ProtocolEvent, SSEEncoder, ActivityStreamEvent, StepStartedStreamEvent, or CustomStreamEvent.
---

# Neuron AI Streaming

This skill covers how output leaves a running agent or workflow and reaches a consumer: the native chunk stream, portable progress events, protocol adapters for UI libraries, and channels for push delivery.

## The Mental Model

A Workflow segment produces a sequence of live objects while it runs. Four independent concerns decide what happens to them:

| Concern | Question it answers | Component |
|---|---|---|
| **Source** | What is emitted? | Provider chunks and events yielded by nodes |
| **Shape** | What does the wire format look like? | `StreamAdapterInterface` via `setStreamAdapter()` |
| **Destination** | Where does it go? | Pull iteration over the generator, and/or `StreamingChannelInterface` via `setChannel()` |
| **Encoding** | How does it become bytes? | `SSEEncoder` on the HTTP edge; a channel encodes for its own transport |

Adapter and channel compose. The adapter decides the shape, the channel the destination. The Workflow converts each item once into a `ProtocolEvent` and hands the same object to both the pull consumer and the channel. It never frames bytes: the edge that owns the transport encodes it.

Live output is **ephemeral**. Nothing yielded during a segment is stored in persistence or replayed when a completed step is restored. Chat history is the record the UI reconciles from. Never make correctness depend on a client receiving a streamed item.

## Pull Streaming: Native Chunks

`Agent::stream()` and `Workflow::events()` return a `Generator`. Iterate it for live output, then read the final state from `getReturn()`.

```php
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;

$stream = $agent->stream(new UserMessage('Hello'));

foreach ($stream as $item) {
    if ($item instanceof TextChunk) {
        echo $item->content;
    }
}

$state = $stream->getReturn();
$message = $state->getMessage();
```

Chunk types under `NeuronAI\Chat\Messages\Stream\Chunks`:

| Chunk | Carries |
|---|---|
| `TextChunk` | A piece of assistant text (`content`) |
| `ReasoningChunk` | A piece of model reasoning (`content`) |
| `ToolCallChunk` | A tool call the model decided to make |
| `ToolArgumentChunk` | An argument delta for a tool call (`toolName`, `delta`, `toolCallId`) |
| `ToolResultChunk` | The result of an executed tool |
| `ImageChunk`, `AudioChunk` | Multimodal content |

Every chunk extends `StreamChunk` and exposes `toArray()`.

Without an adapter the generator also yields the portable events described next as their native value objects. Handle them when the application wants to show intermediate activity, or ignore them when only model content matters.

## Yielding Progress From a Node

A node that returns a `Generator` can `yield` any object as live output. It must still `return` its routing `Event` to reach the next node. Yielded values are output, not routing.

```php
use NeuronAI\Agent\Adapters\Events\ActivityStreamEvent;

public function __invoke(ProcessEvent $event, WorkflowState $state): \Generator
{
    yield new ActivityStreamEvent(
        id: $event->jobId,
        type: 'indexing',
        data: ['processed' => 10, 'total' => 100],
    );

    $result = $this->memoize('indexing', fn () => $this->finishIndexing($event));

    return new ResultEvent($result);
}
```

Yield the portable value objects from `NeuronAI\Agent\Adapters\Events` so the node never imports a UI protocol:

| Event | Meaning |
|---|---|
| `StepStartedStreamEvent(name, metadata)` | A named phase began |
| `StepFinishedStreamEvent(name, metadata)` | A named phase ended |
| `ActivityStreamEvent(id, type, data)` | A replaceable progress snapshot; the same `id` updates the previous one |
| `CustomStreamEvent(name, value)` | A named escape hatch with a JSON-serializable value |

Names and IDs cannot be empty. Because yielded output is never replayed, wrap the durable work in `memoize()` and let the progress event be lost on a resumed run.

## Stream Adapters: Shaping Output for a UI Protocol

An adapter turns each live object into zero or more `ProtocolEvent`s: small value objects carrying the event `type` and a JSON-serializable `data` payload (`jsonSerialize()` places the type first). Attach it with `setStreamAdapter()` and the generator yields protocol events instead of native objects.

**Built-in adapters** in `NeuronAI\Agent\Adapters`:

- `VercelAIAdapter(?messageId, parts)` for the Vercel AI SDK data stream (`useChat`, `useCompletion`). Pass the latest assistant message ID and parts on a continuation so pending tool parts are not redispatched.
- `AGUIAdapter(threadId, ?runId, messages, state)` for the AG-UI protocol (CopilotKit). `messages` and `state` seed the frontend snapshot.

Both expose `getHeaders()` with the HTTP response headers their protocol requires. Framing is not the adapter's job: `SSEEncoder::encode($generator)` turns the events into `data:` lines on the HTTP edge and forwards the generator's return value, so the final state is still reachable after streaming. `SSEEncoder::frame($event)` frames a single event.

**Laravel endpoint:**

```php
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Streaming\SSEEncoder;

Route::post('/chat', function (Request $request) {
    $adapter = new VercelAIAdapter();

    $stream = MyAgent::make(threadId: $request->input('threadId'))
        ->setStreamAdapter($adapter)
        ->stream(new UserMessage($request->input('message')));

    return response()->stream(function () use ($stream) {
        foreach (SSEEncoder::encode($stream) as $line) {
            echo $line;
            ob_flush();
            flush();
        }
    }, 200, $adapter->getHeaders());
});
```

An adapter is stateful for one stream. Create a new instance per request and never share one between concurrent streams. The Workflow calls `reset()` before every segment, so the same instance can also serve a suspension and its continuation in one process, as a queue worker does.

### Terminal frames

The Workflow selects the terminal from the segment's outcome, so application code never calls these itself:

| Outcome | Adapter method | What the client learns |
|---|---|---|
| Completed | `end()` | The run finished |
| Suspended | `interrupt($request)` | The current `InterruptRequest` the run waits for |
| Failed | `error($e)` | A neutral failure text (override the adapter's protected `errorMessage()` to expose more), then the exception is rethrown to the caller |

With `AGUIAdapter` a suspended stream ends with `RUN_FINISHED` whose `outcome` lists the pending interrupts; with `VercelAIAdapter` it ends with a `tool-approval-request` part per pending call. Continue native approvals with `$agent->submitApprovalDecisions($decisions)->events()` and deferred tool results with `$agent->submitToolResults($results)->events()`. Both maps use tool call IDs; a result entry contains exactly one `result` value or `error` string. Raw AG-UI and Vercel envelopes still use their protocol translators through `submitInputs()`. See **neuron-tool-approval** and **neuron-frontend-integration** for the inbound round trip.

### Mapping domain events

Both built-in adapters implement `CustomizableStreamAdapterInterface`. Use `mapEvent()` when nodes yield application objects that should stay independent from Neuron's event classes:

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

The mapper returns a portable event, never a string or protocol array. Return `null` to suppress the event. Matching is by exact class, so a parent-class mapping does not capture subclasses.

Resolution order for a yielded object: a portable event is encoded directly, then an exact-class mapping, then built-in chunk conversion, then the object is ignored.

### Protocol translation

| Portable meaning | AG-UI | Vercel AI SDK |
|---|---|---|
| step started / finished | `STEP_STARTED` / `STEP_FINISHED` | transient `data-workflow-step` |
| activity or progress | `ACTIVITY_SNAPSHOT` | transient `data-workflow-activity` |
| named custom data | `CUSTOM` | transient `data-{name}` |

Vercel parts are transient, so intermediate information reaches the UI without entering assistant-message history.

### Custom adapters

Implement `StreamAdapterInterface`: `reset()` opens a segment by dropping the previous segment's stream state, and `start()`, `transform(object)`, `end()`, `interrupt(InterruptRequest)`, `error(Throwable)` each return an iterable of `ProtocolEvent`s. Generate ids with `UniqueIdGenerator::generateId('msg_')` and expose the HTTP headers the protocol needs from the adapter itself. Return an empty iterable from `interrupt()` or `error()` when the protocol cannot express that outcome. Add `MapsStreamEvents` and implement `CustomizableStreamAdapterInterface` to support `mapEvent()`.

## Streaming Channels: Push Delivery

Pull iteration only works when the code driving the generator is also the consumer, typically an HTTP response. Often it is not:

- A queue worker runs the agent and the browser is connected to a websocket or a Redis/Pusher stream.
- A run is resumed after an approval from a different process than the one the client is watching.
- The application calls `run()` or `chat()` and still wants live output somewhere.

A channel solves this. Attach one with `setChannel()` and the Workflow pushes every item to it as a side effect, regardless of who iterates the generator. Delivery happens on both `events()` and `run()`, since `run()` consumes `events()` internally.

```php
namespace NeuronAI\Workflow\Streaming\Channel;

interface StreamingChannelInterface
{
    public function send(ProtocolEvent $event): void;                    // the adapter's events, in stream order
    public function interrupted(WorkflowState $state): void;
    public function completed(WorkflowState $state, string $workflowId): void;
    public function failed(Throwable $exception, string $workflowId): void;
}
```

A channel speaks the adapter's protocol, so content delivery needs an adapter:

| Adapter attached? | Pull consumer receives | Channel receives |
|---|---|---|
| No | native objects | only the lifecycle methods |
| Yes | `ProtocolEvent`s | the same instances through `send()` |

The channel encodes for its own transport. Native objects never reach it: a push destination is another system and needs a wire vocabulary, which is exactly what the adapter provides.

The lifecycle methods fire once per segment after the adapter's terminal frames. `interrupted()` receives a clone of the state so the channel can inspect the pending interrupts. The channel receives lifecycle calls even when nothing was streamed, so a zero-item run still reports completion.

### CallbackChannel

`CallbackChannel` wraps up to four closures, one per method. Unset hooks are silent no-ops, so a transport usually needs a single closure. With Laravel Broadcast, for example, the event type is the broadcast name and `data` the payload, sent synchronously because a queued broadcast with several workers loses ordering:

```php
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;

// Inside a queued job: the HTTP request already returned.
$agent = MyAgent::make(threadId: $threadId)
    ->setStreamAdapter(new VercelAIAdapter())
    ->setChannel(new CallbackChannel(
        onSend: fn (ProtocolEvent $event) => Broadcast::private("chat.{$threadId}")
            ->as($event->type)
            ->with($event->data)
            ->sendNow(),
    ));

foreach ($agent->stream(new UserMessage($message)) as $ignored) {
}
```

Drain `stream()` rather than calling `chat()`: the buffered path never yields provider chunks, so the channel would only see the terminal frames.

For a dedicated transport extend `AbstractChannel` (see *Writing a channel* below). Declare a channel once on the class by overriding the protected `channel()` hook, the same way `streamAdapter()` declares a default adapter.

### RedisChannel

`RedisChannel` publishes the segment on a Redis Pub/Sub channel, the usual fan-out between a worker running the agent and the process holding the client's connection (an SSE endpoint, a websocket server). It needs `ext-redis` and a connected client, the same requirement as `RedisPersistence`.

```php
use NeuronAI\Workflow\Streaming\Channel\RedisChannel;

$agent = MyAgent::make(threadId: $threadId)
    ->setStreamAdapter(new VercelAIAdapter())
    ->setChannel(new RedisChannel($redis, "chat:{$threadId}"));
```

Every message is a JSON envelope `{streamId, sequence, type, data}`. Unwrap it before passing the protocol event to an SSE encoder; forwarding the envelope directly is not the UI protocol. The Redis client must be connected and outside a transaction or pipeline. A publish result of zero subscribers is valid; Pub/Sub does not replay missed messages. Read [Channel wire contract and consumers](references/channels.md) when wiring subscribers, reassembly, or gap recovery.

### PusherChannel

`PusherChannel` accepts an application-configured `Pusher\Pusher` instance from the optional `pusher/pusher-php-server` package (`composer require pusher/pusher-php-server:^7.2.4`). The official SDK owns signing, encryption, endpoint settings and HTTP delivery. Configure `host`, `port` and `scheme` on that client for Pusher-compatible servers such as Reverb and Soketi; a custom Guzzle client can be passed as its fifth constructor argument.

```php
use NeuronAI\Workflow\Streaming\Channel\PusherChannel;
use Pusher\Pusher;

$pusher = new Pusher(
    $_ENV['PUSHER_APP_KEY'],
    $_ENV['PUSHER_APP_SECRET'],
    $_ENV['PUSHER_APP_ID'],
    [
        'cluster' => 'eu',
        'timeout' => 5,
        'encryption_master_key_base64' => $_ENV['PUSHER_ENCRYPTION_MASTER_KEY'],
    ],
);

// Inside a queued job: the HTTP request already returned.
$agent = MyAgent::make(threadId: $threadId)
    ->setStreamAdapter(new VercelAIAdapter())
    ->setChannel(new PusherChannel(
        client: $pusher,
        channel: "private-encrypted-chat.{$threadId}",
    ));

foreach ($agent->stream(new UserMessage($message)) as $ignored) {
}
```

Each protocol event becomes a Pusher event named by its `type`, carrying the same `{streamId, sequence, type, data}` envelope as Redis. The three lifecycle events carry only `workflowId` in `data`; exception details and workflow state are not exposed.

For encryption, configure a base64-encoded 32-byte master key on the SDK and use a `private-encrypted-*` channel. The SDK encrypts every envelope, including fragments and lifecycle events. Use the encryption-enabled Pusher JavaScript client; it decrypts before invoking event callbacks, so the envelope consumer stays unchanged. The application must authenticate and authorize subscribers before returning `$pusher->authorizeChannel($channel, $socketId)` from its authorization endpoint. Only the per-channel shared secret goes to an authorized subscriber; never expose the master key. An encrypted channel without a configured key fails delivery; it never falls back to plaintext. For ordinary private channels, use `private-*` and omit the encryption key.

Pusher keeps `batchSize: 10` by default, with an independent 10,000-byte event-data limit and `maxRequestBytes: 10_000` request limit. Increasing the request limit does not increase the event limit. Encrypted channels conservatively reserve space for the authentication tag, nonce, base64 and both layers of JSON escaping; encrypted fragments may be smaller and batches may flush before ten events. Partial batches wait until another event fills the batch or the segment ends; choose `batchSize: 1` for immediate delivery.

Use Pusher SDK 7.2.4 or later; earlier releases do not propagate the SDK timeout to HTTP requests. Neuron does not mutate the injected Pusher client or configure its timeouts. Set the SDK's `timeout` option explicitly (five seconds in the example); the SDK passes this timeout per request even when a custom Guzzle client is injected. Configure `connect_timeout` on that Guzzle client if needed.

### Writing and consuming a channel

Extend `AbstractChannel` for a transport with the shared wire contract; `CallbackChannel` remains a direct lifecycle callback adapter, and an `onSend` callback alone receives no lifecycle notifications. The base class serializes each payload once, splits oversized events incrementally, batches encoded bytes, and enforces event and delivery limits. Implement `deliver(string $batch)`; only override the encoding/limit hooks your transport needs.

Read [Channel wire contract and consumers](references/channels.md) for the exact envelope and fragment shapes, `@neuron-core/streaming` consumer examples, extension hooks, and the socket transport example. Do not assume Pusher batches arrive in order or concatenate fragments by arrival order.

### Failure policy

A channel error never fails the workflow. Workflow dispatches `ChannelError` with the exception. After the first transport delivery failure, `AbstractChannel` stops ordinary delivery for that segment; it does not retry uncertain batches. At termination it attempts pending data, then separately attempts the terminal notification even if that flush failed. Terminal delivery is not retried. Segment state is cleared even when termination fails, so sequential reuse starts with a fresh stream ID. Never share an instance between concurrent segments or reuse one after abandoning its generator without termination.

Clients detect gaps using sequence numbers, bound fragment buffering, and reconcile from application history on a timeout, disconnect, or incomplete segment. Streamed output is not a durable delivery channel.

## Testing Streamed Output

`FakeAIProvider::setStreamChunkSize()` makes chunking deterministic. `FakeChannel` records every delivery: `getSent()` returns the protocol events in stream order, `getSuspensions()`, `getCompletions()` and `getFailures()` the segment lifecycle. `setThrowOnSend()` exercises the failure policy.

```php
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeChannel;

$channel = new FakeChannel();

$agent = Agent::make()
    ->setStreamAdapter(new VercelAIAdapter())
    ->setChannel($channel);
$agent->setAiProvider((new FakeAIProvider(new AssistantMessage('Hello world')))->setStreamChunkSize(5));

$pulled = iterator_to_array($agent->stream(new UserMessage('Hi')), false);

$this->assertSame($pulled, $channel->getSent());
$channel->assertCompleted();
```

See the **neuron-test** skill for the provider fake and assertion helpers.

## Related

- **Agent setup** (providers, history, persistence) and the `stream()` / `chat()` entry points: the **neuron-agent** skill.
- **Writing nodes, memoization, and durable resume**: the **neuron-workflow** skill.
- **Approval round trip** (rendering the pending approval, translating client decisions, continuing the run): the **neuron-tool-approval** skill.
- **Frontend tools executed in the browser** (deferred tools, the endpoint, Vercel `useChat`, the AG-UI client, CopilotKit): the **neuron-frontend-integration** skill.
