---
name: neuron-streaming
description: Stream Neuron AI agent and workflow output to a consumer — iterating native chunks, yielding portable progress events from nodes, attaching a stream adapter for a UI protocol (Vercel AI SDK, AG-UI, SSE) or for Neuron's native vocabulary, and pushing output through a streaming channel when the consumer is not the HTTP response (queue worker, websocket, resumed run). Use this skill whenever the user mentions streaming, stream chunks, TextChunk, real-time responses, SSE, server-sent events, useChat, Vercel AI SDK, AG-UI, CopilotKit, stream adapters, streaming channels, pushing output to a websocket or Redis/Pusher, progress events from a workflow node, or testing streamed output. Also trigger for any task involving setStreamAdapter, setChannel, StreamAdapterInterface, NativeAdapter, StreamingChannelInterface, AbstractChannel, CallbackChannel, RedisChannel, PusherChannel, FakeChannel, ProtocolEvent, SSEEncoder, ActivityStreamEvent, StepStartedStreamEvent, or CustomStreamEvent.
---

# Neuron AI Streaming

This skill covers how output leaves a running agent or workflow and reaches a consumer: the native chunk stream, portable progress events, protocol adapters for UI libraries, and channels for push delivery.

## The Mental Model

A Workflow segment produces a sequence of live objects while it runs. Four independent concerns decide what happens to them:

| Concern | Question it answers | Component |
|---|---|---|
| **Source** | What is emitted? | Provider chunks and events yielded by nodes |
| **Shape** | What does the wire format look like? | `StreamAdapterInterface` via `setStreamAdapter()` |
| **Destination** | Where does it go? | Pull iteration over the generator, or eager delivery through `StreamingChannelInterface` via `setChannel()` |
| **Encoding** | How does it become bytes? | `SSEEncoder` on the HTTP edge; a channel encodes for its own transport |

Adapter and channel compose. The adapter decides the shape, the channel the destination. The Workflow converts each item once into a `ProtocolEvent`. `run()` consumes the pipeline eagerly; `events()` and `Agent::stream()` always return lazy generators. Consumption also delivers adapted events to a configured channel. It never frames bytes: the edge that owns the transport encodes it.

Live output is **ephemeral**. Nothing yielded during a segment is stored in persistence or replayed when a completed step is restored. Chat history is the record the UI reconciles from. Never make correctness depend on a client receiving a streamed item.

## Resources constructed per segment

Use the `streamAdapter()` and `channel()` hooks, or pass a factory to
`setStreamAdapter()` / `setChannel()`; the setters take factories only:

```php
$agent->setStreamAdapter(fn () => new AGUIAdapter($input['threadId'], $input['runId'] ?? null));
$state = $agent->run($request);
```

These factories run only for an owned execution and return a new adapter or channel
for each segment: both hold the state of one segment's stream. There is no Workflow-mutating preparation callback; saved outcomes and idle
polls skip resource construction. The AG-UI run ID is the client's per-request ID from
its input, not Neuron's durable run ID. Lazy calls capture input at creation and
execute during iteration. Setters configure the definition; an active execution keeps
its already resolved resources and graph. This rule applies to Workflow, Agent and RAG
configuration.

## Pull Streaming: Native Chunks

`Agent::stream()` and `Workflow::events()` always return a lazy `Generator`.
Iterate it for output, then read state from `getReturn()`. For eager channel delivery,
use `run(ExecutionRequest::start(new AgentStartEvent($messages, new AgentRunOptions(stream: true))))`.

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

An adapter turns each live object into zero or more `ProtocolEvent`s: small value objects carrying the event `type` and a JSON-serializable `data` payload (`jsonSerialize()` places the type first). Attach it with `setStreamAdapter()` to produce protocol events instead of native objects. Without a channel these are yielded to the caller; with a channel they are delivered eagerly.

**Built-in adapters** in `NeuronAI\Agent\Adapters`:

- `VercelAIAdapter(?messageId, parts)` for the Vercel AI SDK data stream (`useChat`, `useCompletion`). Pass the latest assistant message ID and parts on a continuation so pending tool parts are not redispatched.
- `AGUIAdapter(threadId, ?runId, messages, state)` for the AG-UI protocol (CopilotKit). `messages` and `state` seed the frontend snapshot.
- `NativeAdapter()` for Neuron's own vocabulary, when the consumer speaks no UI protocol (typically a custom frontend behind a channel). See *Native vocabulary* below.

The two UI protocol adapters expose `getHeaders()` with the HTTP response headers their protocol requires; the native vocabulary requires none. Framing is not the adapter's job: `SSEEncoder::encode($generator)` turns the events into `data:` lines on the HTTP edge and forwards the generator's return value, so the final state is still reachable after streaming. `SSEEncoder::frame($event)` frames a single event.

**Laravel endpoint:** This pull-stream example assumes `MyAgent` has no configured channel, including through its `channel()` hook. `SSEEncoder::encode()` takes a generator; the generator can also deliver to a configured channel during iteration.

```php
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Streaming\SSEEncoder;

Route::post('/chat', function (Request $request) {
    $adapter = new VercelAIAdapter();

    // The request runs one segment, so it builds its adapter once and keeps it for the headers.
    $stream = MyAgent::make(workflowId: $request->input('threadId'))
        ->setStreamAdapter(fn (): VercelAIAdapter => $adapter)
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

An adapter holds the state of one segment's stream. The factory runs for every segment, so a suspension and its continuation in one process, as a queue worker runs them, each get their own adapter. Seed a continuation's adapter with what the client already holds: the AG-UI `messages`, the Vercel message and its `parts`.

### Terminal frames

The Workflow selects the terminal from the segment's outcome, so application code never calls these itself:

| Outcome | Adapter method | What the client learns |
|---|---|---|
| Completed | `end()` | The run finished |
| Suspended | `interrupt($request)` | The current `InterruptRequest` the run waits for |
| Failed | `error($e)` | A neutral failure text (override the adapter's protected `errorMessage()` to expose more), then the exception is rethrown to the caller |

With `AGUIAdapter` a suspended stream ends with `RUN_FINISHED` whose `outcome` lists the pending interrupts; with `VercelAIAdapter` it ends with a `tool-approval-request` part per pending call; with `AgentChunkAdapter` it ends with one `interrupt` event carrying the serialized request. Continue native approvals with `$agent->submitApprovalDecisions($decisions)->events()` and deferred tool results with `$agent->submitToolResults($results)->events()`. Both maps use tool call IDs; a result entry contains exactly one `result` value or `error` string. Raw AG-UI and Vercel envelopes still use their protocol translators through `submitInputs()`. See **neuron-tool-approval** and **neuron-frontend-integration** for the inbound round trip.

### Mapping domain events

Every built-in adapter implements `CustomizableStreamAdapterInterface`. Use `mapEvent()` when nodes yield application objects that should stay independent from Neuron's event classes:

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

| Portable meaning | AG-UI | Vercel AI SDK | Native |
|---|---|---|---|
| step started / finished | `STEP_STARTED` / `STEP_FINISHED` | transient `data-workflow-step` | `step-started` / `step-finished` |
| activity or progress | `ACTIVITY_SNAPSHOT` | transient `data-workflow-activity` | `activity` |
| named custom data | `CUSTOM` | transient `data-{name}` | `custom` |

Vercel parts are transient, so intermediate information reaches the UI without entering assistant-message history.

### Native vocabulary

`AgentChunkAdapter` is stateless and one-to-one: each yielded object becomes one event named after its kind, there are no start or end frames, and unknown objects are ignored. A chunk's payload is its own `toArray()`, so it always includes `messageId`: the ID of the message the chunk belongs to, the `ToolCallMessage` for a tool call chunk, and `null` on tool result chunks.

| Yielded object | Event `type` | `data` |
|---|---|---|
| `TextChunk`, `ReasoningChunk`, `ImageChunk`, `AudioChunk` | `text`, `reasoning`, `image`, `audio` | `messageId`, `content` |
| `ToolArgumentChunk` | `tool-argument` | `messageId`, `toolName`, `toolCallId`, `delta` |
| `ToolCallChunk`, `ToolResultChunk` | `tool-call`, `tool-result` | `tool`: the serialized `ToolCall` (`callId`, `name`, `inputs`, `result`, approval fields) |
| `StepStartedStreamEvent`, `StepFinishedStreamEvent` | `step-started`, `step-finished` | `name`, `metadata` |
| `ActivityStreamEvent` | `activity` | `id`, `activityType`, `data` |
| `CustomStreamEvent` | `custom` | `name`, `value` |
| Suspended segment | `interrupt` | `request`: the serialized `InterruptRequest` (`interruptId`, `type`, `message`, and `actions` or `toolCalls`) |
| Failed segment | `error` | `message`: the neutral `errorMessage()` text |

`type` is the event discriminator, so a payload never carries a `type` of its own: the activity's type travels as `activityType` and the interrupt request stays nested under `request`. It is the only built-in adapter that carries `ImageChunk` and `AudioChunk`. A completed segment emits no frame: a channel reports it with `stream.completed`, and an SSE response simply closes.

### Custom adapters

Implement `StreamAdapterInterface`: `start()`, `transform(object)`, `end()`, `interrupt(InterruptRequest)`, `error(Throwable)` each return an iterable of `ProtocolEvent`s, and an instance serves one segment. Generate ids with `UniqueIdGenerator::generateId('msg_')` and expose the HTTP headers the protocol needs from the adapter itself. Return an empty iterable from `interrupt()` or `error()` when the protocol cannot express that outcome. Add `MapsStreamEvents` and implement `CustomizableStreamAdapterInterface` to support `mapEvent()`.

## Streaming Channels: Push Delivery

The examples below use `ExecutionRequest` from `NeuronAI\Workflow\Executor`,
`AgentStartEvent` from `NeuronAI\Agent\Events`, and `AgentRunOptions` from `NeuronAI\Agent`.

Pull iteration only works when the code driving the generator is also the consumer, typically an HTTP response. Often it is not:

- A queue worker runs the agent and the browser is connected to a websocket or a Redis/Pusher stream.
- A run is resumed after an approval from a different process than the one the client is watching.
- The application calls `run()` or `chat()` and still wants live output somewhere.

Attach a channel with `setChannel()` and an adapter with `setStreamAdapter()`.
`run($request)` consumes execution, delivers protocol events synchronously, and
returns state. `events($request)` and `stream($messages)` always return lazy generators;
iteration sends events to the channel and yields them to the caller. Components
supplied through the protected hooks obey the same rules.

Use an `ExecutionRequest::start()` containing `AgentStartEvent` and
`AgentRunOptions(stream: true)` when an eager worker needs provider chunks. This
keeps provider streaming intent separate from how the caller consumes execution.

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

| Adapter | Channel | `events()` / `stream()` returns |
|---|---|---|
| No | No | Lazy generator of native objects |
| Yes | No | Lazy generator of `ProtocolEvent`s |
| No | Yes | Lazy generator of native objects; channel receives lifecycle during iteration |
| Yes | Yes | Lazy generator; iteration delivers protocol events through `send()` |

The channel encodes for its own transport. Native objects never reach it: a push destination is another system and needs a wire vocabulary, which is exactly what the adapter provides. When the consumer speaks no UI protocol, attach `AgentChunkAdapter`: it is that vocabulary for Neuron's own chunks and events.

The lifecycle methods fire once per segment after the adapter's terminal frames. `interrupted()` receives a clone of the state so the channel can inspect the pending interrupts. The channel receives lifecycle calls even when nothing was streamed, so a zero-item run still reports completion.

### CallbackChannel

`CallbackChannel` wraps up to four closures, one per method. Unset hooks are silent no-ops, so a transport usually needs a single closure. With Laravel Broadcast, for example, the event type is the broadcast name and `data` the payload, sent synchronously because a queued broadcast with several workers loses ordering:

```php
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;

// Inside a queued job: the HTTP request already returned.
$agent = MyAgent::make(workflowId: $threadId)
    ->setStreamAdapter(fn (): VercelAIAdapter => new VercelAIAdapter())
    ->setChannel(fn (): CallbackChannel => new CallbackChannel(
        onSend: fn (ProtocolEvent $event) => Broadcast::private("chat.{$threadId}")
            ->as($event->type)
            ->with($event->data)
            ->sendNow(),
    ));

$state = $agent->run(ExecutionRequest::start(new AgentStartEvent([new UserMessage($message)], new AgentRunOptions(stream: true))));
```

Use `AgentRunOptions(stream: true)` for provider chunks. `chat()` uses buffered model inference; attaching an adapter and channel does not change that inference mode.

For a dedicated transport extend `AbstractChannel` (see *Writing a channel* below). Declare a channel once on the class by overriding the protected `channel()` hook, the same way `streamAdapter()` declares a default adapter. Declare both: a `channel()` hook alone delivers only the lifecycle.

```php
use NeuronAI\Agent\Adapters\AgentChunkAdapter;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;

class MyAgent extends Agent
{
    protected function streamAdapter(): ?StreamAdapterInterface
    {
        return new AgentChunkAdapter();
    }

    protected function channel(): ?StreamingChannelInterface
    {
        return new CallbackChannel(
            onSend: fn (ProtocolEvent $event) => Broadcast::private("chat.{$this->getThreadId()}")
                ->as($event->type)
                ->with($event->data)
                ->sendNow(),
        );
    }
}
```

### RedisChannel

`RedisChannel` publishes the segment on a Redis Pub/Sub channel, the usual fan-out between a worker running the agent and the process holding the client's connection (an SSE endpoint, a websocket server). It needs `ext-redis` and a connected client, the same requirement as `RedisPersistence`.

```php
use NeuronAI\Workflow\Streaming\Channel\RedisChannel;

$agent = MyAgent::make(workflowId: $threadId)
    ->setStreamAdapter(fn (): VercelAIAdapter => new VercelAIAdapter())
    ->setChannel(fn (): RedisChannel => new RedisChannel($redis, "chat:{$threadId}"));

$state = $agent->run(ExecutionRequest::start(new AgentStartEvent([new UserMessage($message)], new AgentRunOptions(stream: true))));
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
$agent = MyAgent::make(workflowId: $threadId)
    ->setStreamAdapter(fn (): VercelAIAdapter => new VercelAIAdapter())
    ->setChannel(fn (): PusherChannel => new PusherChannel(
        client: $pusher,
        channel: "private-encrypted-chat.{$threadId}",
    ));

$state = $agent->run(ExecutionRequest::start(new AgentStartEvent([new UserMessage($message)], new AgentRunOptions(stream: true))));
```

Each protocol event becomes a Pusher event named by its `type`, carrying the same `{streamId, sequence, type, data}` envelope as Redis. The three lifecycle events carry only `workflowId` in `data`; exception details and workflow state are not exposed.

For encryption, configure a base64-encoded 32-byte master key on the SDK and use a `private-encrypted-*` channel. The SDK encrypts every envelope, including fragments and lifecycle events. Use the encryption-enabled Pusher JavaScript client; it decrypts before invoking event callbacks, so the envelope consumer stays unchanged. The application must authenticate and authorize subscribers before returning `$pusher->authorizeChannel($channel, $socketId)` from its authorization endpoint. Only the per-channel shared secret goes to an authorized subscriber; never expose the master key. An encrypted channel without a configured key fails delivery; it never falls back to plaintext. For ordinary private channels, use `private-*` and omit the encryption key.

Pusher keeps `batchSize: 10` by default, with an independent 10,000-byte event-data limit and `maxRequestBytes: 10_000` request limit. Increasing the request limit does not increase the event limit. Encrypted channels conservatively reserve space for the authentication tag, nonce, base64 and both layers of JSON escaping; encrypted fragments may be smaller and batches may flush before ten events. Partial batches wait until another event fills the batch or the segment ends; choose `batchSize: 1` for immediate delivery.

Use Pusher SDK 7.2.4 or later; earlier releases do not propagate the SDK timeout to HTTP requests. Neuron does not mutate the injected Pusher client or configure its timeouts. Set the SDK's `timeout` option explicitly (five seconds in the example); the SDK passes this timeout per request even when a custom Guzzle client is injected. Configure `connect_timeout` on that Guzzle client if needed.

### Writing and consuming a channel

Extend `AbstractChannel` for a transport with the shared wire contract; `CallbackChannel` remains a direct lifecycle callback adapter, and an `onSend` callback alone receives no lifecycle notifications. The base class serializes each payload once, splits oversized events incrementally, batches encoded bytes, and enforces event and delivery limits. Implement `deliver(string $batch)`; only override the encoding/limit hooks your transport needs.

Read [Channel wire contract and consumers](references/channels.md) for the exact envelope and fragment shapes, `@neuron-core/streaming` consumer examples, extension hooks, and the socket transport example. Do not assume Pusher batches arrive in order or concatenate fragments by arrival order.

### Failure policy

A channel error never fails the workflow. Workflow dispatches `ChannelError` with the exception. After the first transport delivery failure, `AbstractChannel` stops ordinary delivery for that segment; it does not retry uncertain batches. At termination it attempts pending data, then separately attempts the terminal notification even if that flush failed. Terminal delivery is not retried. A channel serves one segment: every segment builds its own through the factory or the hook, with a fresh stream ID.

Clients detect gaps using sequence numbers, bound fragment buffering, and reconcile from application history on a timeout, disconnect, or incomplete segment. Streamed output is not a durable delivery channel.

## Testing Streamed Output

`FakeAIProvider::setStreamChunkSize()` makes chunking deterministic. `FakeChannel` records every delivery: `getSent()` returns the protocol events in stream order, `getSuspensions()`, `getCompletions()` and `getFailures()` the segment lifecycle. `setThrowOnSend()` exercises the failure policy.

```php
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeChannel;

$channel = new FakeChannel();

// The factory returns the same fake, so the test can read what every segment delivered.
$agent = Agent::make()
    ->setStreamAdapter(fn (): VercelAIAdapter => new VercelAIAdapter())
    ->setChannel(fn (): FakeChannel => $channel);
$agent->setAiProvider((new FakeAIProvider(new AssistantMessage('Hello world')))->setStreamChunkSize(5));

$state = $agent->run(ExecutionRequest::start(new AgentStartEvent([new UserMessage('Hi')], new AgentRunOptions(stream: true))));

$this->assertSame('Hello world', $state->getMessage()->getContent());
$this->assertNotEmpty($channel->getSent());
$channel->assertCompleted();
```

See the **neuron-test** skill for the provider fake and assertion helpers.

## Related

- **Agent setup** (providers, history, persistence) and the `stream()` / `chat()` entry points: the **neuron-agent** skill.
- **Writing nodes, memoization, and durable resume**: the **neuron-workflow** skill.
- **Approval round trip** (rendering the pending approval, translating client decisions, continuing the run): the **neuron-tool-approval** skill.
- **Frontend tools executed in the browser** (deferred tools, the endpoint, Vercel `useChat`, the AG-UI client, CopilotKit): the **neuron-frontend-integration** skill.
