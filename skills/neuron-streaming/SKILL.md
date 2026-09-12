---
name: neuron-streaming
description: Stream Neuron AI agent and workflow output to a consumer — iterating native chunks, yielding portable progress events from nodes, attaching a stream adapter for a UI protocol (Vercel AI SDK, AG-UI, SSE), and pushing output through a streaming channel when the consumer is not the HTTP response (queue worker, websocket, resumed run). Use this skill whenever the user mentions streaming, stream chunks, TextChunk, real-time responses, SSE, server-sent events, useChat, Vercel AI SDK, AG-UI, CopilotKit, stream adapters, streaming channels, pushing output to a websocket or Redis/Pusher, progress events from a workflow node, or testing streamed output. Also trigger for any task involving setStreamAdapter, setChannel, StreamAdapterInterface, StreamingChannelInterface, CallbackChannel, FakeChannel, ActivityStreamEvent, StepStartedStreamEvent, or CustomStreamEvent.
---

# Neuron AI Streaming

This skill covers how output leaves a running agent or workflow and reaches a consumer: the native chunk stream, portable progress events, protocol adapters for UI libraries, and channels for push delivery.

## The Mental Model

A Workflow segment produces a sequence of live objects while it runs. Three independent concerns decide what happens to them:

| Concern | Question it answers | Component |
|---|---|---|
| **Source** | What is emitted? | Provider chunks and events yielded by nodes |
| **Shape** | What does the wire format look like? | `StreamAdapterInterface` via `setStreamAdapter()` |
| **Destination** | Where does it go? | Pull iteration over the generator, and/or `StreamingChannelInterface` via `setChannel()` |

Adapter and channel compose. The adapter decides the shape, the channel the destination. The Workflow converts each item once and hands the same result to both the pull consumer and the channel.

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

An adapter turns each live object into zero or more protocol strings. Attach it with `setStreamAdapter()` and the generator yields strings instead of objects.

**Built-in adapters** in `NeuronAI\Agent\Adapters`:

- `VercelAIAdapter(?messageId, parts)` for the Vercel AI SDK data stream (`useChat`, `useCompletion`). Pass the latest assistant message ID and parts on a continuation so pending tool parts are not redispatched.
- `AGUIAdapter(threadId, ?runId, messages, state)` for the AG-UI protocol (CopilotKit). `messages` and `state` seed the frontend snapshot.

Both extend `SSEAdapter`, which provides `getHeaders()` for the HTTP response.

**Laravel endpoint:**

```php
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\UserMessage;

Route::post('/chat', function (Request $request) {
    $adapter = new VercelAIAdapter();

    $stream = MyAgent::make(threadId: $request->input('threadId'))
        ->setStreamAdapter($adapter)
        ->stream(new UserMessage($request->input('message')));

    return response()->stream(function () use ($stream) {
        foreach ($stream as $line) {
            echo $line;
            ob_flush();
            flush();
        }
    }, 200, $adapter->getHeaders());
});
```

An adapter is stateful for one stream. Create a new instance per request and never share one between concurrent streams.

### Terminal frames

The Workflow selects the terminal from the segment's outcome, so application code never calls these itself:

| Outcome | Adapter method | What the client learns |
|---|---|---|
| Completed | `end()` | The run finished |
| Suspended | `suspended($requests)` | The active `InterruptRequest`s, keyed by interrupt ID |
| Failed | `error($e)` | The failure, then the exception is rethrown to the caller |

With `AGUIAdapter` a suspended stream ends with `RUN_FINISHED` whose `outcome` lists the pending interrupts; with `VercelAIAdapter` it ends with a `tool-approval-request` part per pending call. The inbound half of that round trip is covered by the **neuron-tool-approval** skill.

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

Implement `StreamAdapterInterface` (`start()`, `transform(object)`, `end()`, `suspended(array)`, `error(Throwable)`), each returning an iterable of strings. Extend `SSEAdapter` to reuse SSE framing and headers. Return an empty iterable from `suspended()` or `error()` when the protocol cannot express that outcome. Add `MapsStreamEvents` and implement `CustomizableStreamAdapterInterface` to support `mapEvent()`.

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
    public function send(object $item): void;                            // native object, no adapter
    public function sendLine(string $line): void;                        // protocol line, adapter attached
    public function suspended(WorkflowState $state): void;
    public function completed(WorkflowState $state, string $workflowId): void;
    public function failed(Throwable $exception, string $workflowId): void;
}
```

Which port receives output depends on the adapter:

| Adapter attached? | Pull consumer receives | Channel receives |
|---|---|---|
| No | native objects | `send($object)` |
| Yes | protocol strings | `sendLine($string)`, identical to the pulled lines |

The lifecycle methods fire once per segment after the adapter's terminal frames. `suspended()` receives a clone of the state so the channel can inspect the pending interrupts. The channel receives lifecycle calls even when nothing was streamed, so a zero-item run still reports completion.

### CallbackChannel

`CallbackChannel` wraps up to five closures, one per method. Unset hooks are silent no-ops, so a transport usually needs a single closure:

```php
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;

// Inside a queued job: the HTTP request already returned.
$agent = MyAgent::make(threadId: $threadId)
    ->setStreamAdapter(new VercelAIAdapter())
    ->setChannel(new CallbackChannel(
        onSendLine: fn (string $line) => $redis->publish("chat:{$threadId}", $line),
        onCompleted: fn ($state, string $workflowId) => $redis->publish("chat:{$threadId}", '[DONE]'),
    ));

$agent->chat(new UserMessage($message));
```

For a dedicated transport implement `StreamingChannelInterface` directly, or declare it once on the class by overriding the protected `channel()` hook, the same way `streamAdapter()` declares a default adapter.

### Failure policy

A channel error never fails the run. The Workflow catches it, dispatches a `ChannelError` observability event carrying the exception, and continues delivering. Losing liveness must never lose the run. Retries, thresholds, and circuit breaking belong to the channel implementation and to the observer that listens for `ChannelError`, not to the engine.

## Testing Streamed Output

`FakeAIProvider::setStreamChunkSize()` makes chunking deterministic. `FakeChannel` records every delivery in public arrays: `sent`, `lines`, `suspendedStates`, `completions`, `failures`. Set `throwOnSend` to exercise the failure policy.

```php
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeChannel;

$channel = new FakeChannel();

$agent = Agent::make()
    ->setStreamAdapter(new VercelAIAdapter())
    ->setChannel($channel);
$agent->setAiProvider((new FakeAIProvider(new AssistantMessage('Hello world')))->setStreamChunkSize(5));

$pulled = iterator_to_array($agent->stream(new UserMessage('Hi')), false);

$this->assertSame($pulled, $channel->lines);
$this->assertCount(1, $channel->completions);
```

See the **neuron-test** skill for the provider fake and assertion helpers.

## Related

- **Agent setup** (providers, history, persistence) and the `stream()` / `chat()` entry points: the **neuron-agent** skill.
- **Writing nodes, memoization, and durable resume**: the **neuron-workflow** skill.
- **Approval round trip** (rendering the pending approval, translating client decisions, continuing the run): the **neuron-tool-approval** skill.
- **Frontend tools executed in the browser** (deferred tools, the endpoint, Vercel `useChat`, the AG-UI client, CopilotKit): the **neuron-frontend-integration** skill.
