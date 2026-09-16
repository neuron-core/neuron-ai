# @neuron-core/streaming

Reconstruct Neuron AI events delivered through transports with message-size limits. The PHP backend splits oversized events into fragments; this package orders the envelopes, joins their fragments, suppresses duplicates, and reports gaps that require application recovery.

The core is independent of transport, UI framework, and agent protocol. Use it with any source of Neuron envelopes: WebSockets, a Redis-to-browser bridge, Pusher, or an in-process callback. Consume the reconstructed events directly, update React/Vue state, or bridge them into AG-UI or the Vercel AI SDK.

```sh
npm install @neuron-core/streaming
```

Dependency-free ESM with TypeScript declarations, for modern browsers and Node.js 22+.

## Architecture

```text
Transport SDK / message source
        ↓ decoded Neuron envelopes
createChannelConsumer
        ↓ ordered events with stream identity
Application callbacks / UI state / createProtocolStream
                                      ↓ validated protocol objects
                                AG-UI / Vercel / other consumers
```

The backend's `AbstractChannel` owns sequencing and fragmentation. Transport implementations such as `PusherChannel` supply delivery and byte budgets. The frontend core understands the shared Neuron envelope contract, without knowing which transport carried it or what the event payload means.

`subscribeToPusher` is a thin input adapter around the same core. `createProtocolStream` is an optional output bridge. Neither is required for direct consumption.

## Core: consume events from any source

```ts
import { createChannelConsumer } from '@neuron-core/streaming';

const consumer = createChannelConsumer({
  onEvent: ({ streamId, sequence, type, data }) => {
    // data is unknown: narrow it for your application's event types.
    renderEvent(streamId, type, data);
  },
  onGap: reason => reloadConversation(reason),
});

consumer.accept(JSON.parse(message)); // An already decoded envelope is also accepted.
consumer.close();                     // On disposal or disconnection.
```

`accept()` takes an envelope of this form:

```ts
{
  streamId: '0123456789abcdef0123456789abcdef',
  sequence: 0,
  type: 'text-delta',
  data: { id: 'part-1', delta: 'Hello' },
}
```

A `stream.fragment` envelope has `data: { event, index, total, part }`, where `event` is the original type and `part` is a base64url slice of its JSON payload. Fragments share the logical event's sequence. Consumers only receive the complete original event, with its `streamId` and `sequence` preserved. The core also preserves scalar, array, and null payloads.

The consumer discovers segments automatically and orders each independently, starting at sequence zero. Concurrent segments may interleave; use `streamId` to route output. Each backend execution segment, including a resumed segment, has a new ID. A stream ID is not a thread ID, workflow ID, or authorization credential.

To select one known segment, pass its ID as the second argument:

```ts
const consumer = createChannelConsumer({ onEvent, onGap }, streamId);
```

IDs are 32-character lowercase hexadecimal strings. Other valid segment IDs are ignored. The backend generates IDs when sending events; your application must obtain the selected ID through its own coordination, or isolate executions by destination. This package does not introduce a start-run handshake.

The terminal events `stream.completed`, `stream.interrupted`, and `stream.failed` are delivered after all preceding events. They close that segment; a consumer discovering segments remains available for other segments. Finished IDs are retained to suppress delayed duplicates.

Callbacks are synchronous. A thrown `onEvent` exception closes the entire consumer and propagates. A gap closes the entire consumer before calling `onGap` once. Reconcile from authoritative application history, then create a fresh consumer.

## WebSocket and other transport adapters

A transport adapter decodes messages, passes envelopes to the core, and detaches its listeners on cleanup. Here is a reusable WebSocket adapter for an application-owned, already connected socket:

```ts
import { createChannelConsumer, type ChannelCallbacks } from '@neuron-core/streaming';

function subscribeToSocket(socket: WebSocket, callbacks: ChannelCallbacks, streamId?: string) {
  let closed = false;
  const consumer = createChannelConsumer({ onEvent: callbacks.onEvent, onGap: fail }, streamId);

  function close() {
    if (closed) return;
    closed = true;
    consumer.close();
    socket.removeEventListener('message', receive);
    socket.removeEventListener('close', disconnected);
    socket.removeEventListener('error', disconnected);
  }
  function fail(reason: string) {
    if (closed) return;
    close();
    callbacks.onGap(reason);
  }
  function disconnected() { fail('Transport disconnected'); }
  function receive(message: MessageEvent) {
    let frame: unknown;
    try { frame = JSON.parse(message.data); }
    catch { fail('Invalid transport JSON'); return; }
    try { consumer.accept(frame); }
    catch (error) { close(); throw error; }
  }

  socket.addEventListener('message', receive);
  socket.addEventListener('close', disconnected);
  socket.addEventListener('error', disconnected);
  return { close };
}
```

The same pattern applies to a Redis bridge, an event emitter, or another broker SDK. Authentication, connection readiness, reconnects, and starting backend executions belong to the application. Attach the listener before triggering execution. This example expects JSON text messages containing Neuron envelopes only; adapt the decoding for your transport.

For Node.js or another asynchronous source, feed its decoded messages directly:

```ts
const consumer = createChannelConsumer({ onEvent, onGap });
try {
  for await (const frame of source) consumer.accept(frame);
} finally {
  consumer.close();
}
```

The application must stop or reconnect `source` after a gap and verify the execution outcome when the source ends. Closing the consumer only releases local resources; it does not prove successful completion.

## Pusher

Pass a channel from your configured `pusher-js` client:

```ts
import { subscribeToPusher } from '@neuron-core/streaming';

const subscription = subscribeToPusher(channel, {
  onEvent: ({ streamId, type, data }) => renderEvent(streamId, type, data),
  onGap: reason => reloadConversation(reason),
});

subscription.close();
```

An optional third argument selects one `streamId`. Without it, the same neutral core discovers and manages all segments. `close()` removes only this subscription's listener and clears its buffers and timers. It does not unsubscribe the channel or disconnect the SDK. A gap or thrown event callback also detaches the listener.

Subscribe before starting execution, and wait for Pusher's subscription success before triggering it. The destination should carry Neuron envelopes only; `pusher:*` control events are ignored. The adapter checks that Pusher's event name matches the envelope type.

The SDK owns authentication, connection settings, and decryption. For `private-encrypted-*` channels use `pusher-js/with-encryption` and the corresponding backend encryption configuration. Compatible servers are configured on the same client. This package imports no Pusher SDK and opens no connections. Watch SDK connection failures yourself and reconcile after disconnection.

## React, Vue, and other UI frameworks

The callback API requires no framework wrapper. Subscribe when a component mounts and close on disposal. For example, in React:

```tsx
import { useEffect, useState } from 'react';
import type { ChannelCallbacks, ChannelEvent } from '@neuron-core/streaming';

type Subscribe = (callbacks: ChannelCallbacks) => { close(): void };

function LatestEvent({ subscribe }: { subscribe: Subscribe }) {
  const [event, setEvent] = useState<ChannelEvent | null>(null);
  const [gap, setGap] = useState<string | null>(null);

  useEffect(() => {
    setEvent(null);
    setGap(null);
    const subscription = subscribe({ onEvent: setEvent, onGap: setGap });
    return () => subscription.close();
  }, [subscribe]);

  return <pre>{gap ?? JSON.stringify(event)}</pre>;
}
```

Supply a stable `subscribe` function, using your WebSocket adapter, Pusher, or another source. In Vue, the equivalent is assigning events to a `ref` and calling `close()` from `onUnmounted`. The application decides how events update its state and how gaps trigger history reconciliation.

## Standard protocol streams

`createProtocolStream(subscribe, parse)` bridges a subscription's reconstructed events into a typed WHATWG `ReadableStream`. It is useful for async iteration, Web Streams pipelines, and agent UI libraries:

```ts
import { createProtocolStream, type ChannelCallbacks } from '@neuron-core/streaming';

const subscribe = (callbacks: ChannelCallbacks) =>
  subscribeToSocket(socket, callbacks, streamId);

const stream = createProtocolStream(subscribe, event => event);
const reader = stream.getReader();
try {
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    console.log(value);
  }
} finally {
  await reader.cancel();
  reader.releaseLock();
}
```

The bridge restores protocol objects from the Neuron envelope:

```text
{ streamId, sequence, type: "text-delta", data: { id: "p1", delta: "Hi" } }
→ { type: "text-delta", id: "p1", delta: "Hi" }
```

Protocol payloads must be objects, or `[]` for PHP's empty payload (for example, Vercel step boundaries). Nonempty arrays and scalar payloads remain available through the raw consumer. The envelope's `type` remains authoritative. The `parse` callback validates/narrows the object and may return a promise; validation preserves event order. Use your SDK's schema, rather than casting unknown input to an SDK type.

A protocol stream represents **one execution segment**. Select its ID upstream or use a dedicated destination. Receiving another segment is an error, not an implicit merge. Create a fresh bridge for a resumed segment.

The bridge consumes Neuron's terminal events itself: completion and interruption close the stream, failure errors it after preceding protocol events. Protocol-specific terminal events such as AG-UI's `RUN_FINISHED` and Vercel's `finish` still pass through. Neuron lifecycle frames never reach SDK parsers.

Cancellation, gaps, invalid protocol data, and parser failures release the subscription. The bridge also releases it when a Neuron terminal event arrives. It does not cancel the backend execution; implement that command in your application if needed.

Push transports cannot honor Web Streams backpressure. The bridge therefore has a separate bounded queue: 8 MiB of serialized event bytes, with a minimum charge of 8 KiB per event (at most 1,024 queued events). A slow reader exceeding that budget receives an error and its subscription closes. These are queue accounting limits, not a JavaScript heap guarantee.

## Vercel AI SDK

Configure the backend with `VercelAIAdapter`. The frontend bridge delivers `UIMessageChunk` objects to a custom `ChatTransport`, so `useChat` can use a broadcast transport instead of an HTTP/SSE response:

```ts
import { uiMessageChunkSchema, type ChatTransport, type UIMessage } from 'ai';
import { createProtocolStream } from '@neuron-core/streaming';

const transport: ChatTransport<UIMessage> = {
  async sendMessages(options) {
    return createProtocolStream(
      callbacks => subscribeToExecution(options, callbacks),
      async event => {
        const result = await uiMessageChunkSchema().validate!(event);
        if (!result.success) throw result.error;
        return result.value;
      },
    );
  },
  async reconnectToStream() {
    return null; // No replay service is supplied by this package.
  },
};

// In your React component: useChat({ transport })
```

`subscribeToExecution` is application code: attach a subscription scoped to this execution, wait for transport readiness, submit `options.messages` and other request fields to your backend, report setup/connection failures through `onGap`, and return a synchronous `{ close() }` cleanup handle. Wire `options.abortSignal` into that lifecycle. It can wrap any transport adapter; it must not return a promise.

For consumers outside `useChat`, pass the same validated stream to `readUIMessageStream({ stream })` from `ai`. No SSE encoding or parsing is needed. See [custom transports](https://ai-sdk.dev/docs/ai-sdk-ui/transport) and [reading UI message streams](https://ai-sdk.dev/docs/ai-sdk-ui/reading-ui-message-streams).

## AG-UI

Configure the backend with `AGUIAdapter`. Extend AG-UI's `AbstractAgent` to connect its observable event interface to the same transport-independent bridge:

```ts
import { AbstractAgent } from '@ag-ui/client';
import { EventSchemas, type BaseEvent, type RunAgentInput } from '@ag-ui/core';
import { Observable } from 'rxjs';
import { createProtocolStream } from '@neuron-core/streaming';

class ChannelAgent extends AbstractAgent {
  run(input: RunAgentInput): Observable<BaseEvent> {
    return new Observable(observer => {
      const stream = createProtocolStream(
        callbacks => subscribeToExecution(input, callbacks),
        event => EventSchemas.parse(event),
      );
      const reader = stream.getReader();
      void (async () => {
        try {
          while (!observer.closed) {
            const { done, value } = await reader.read();
            if (done) { observer.complete(); return; }
            observer.next(value);
          }
        } catch (error) {
          observer.error(error);
        } finally {
          reader.releaseLock();
        }
      })();
      return () => { void reader.cancel().catch(() => {}); };
    });
  }
}
```

As in the Vercel example, `subscribeToExecution` owns subscription readiness and the backend request. Here it sends the AG-UI `RunAgentInput`, preserving the thread/run identity expected by the client. Observable teardown cancels the reader and releases the transport subscription, including while waiting for the next event. See [AG-UI custom agents](https://docs.ag-ui.com/sdk/js/client/abstract-agent).

These examples use the SDKs' own validation and state handling. This package does not translate arbitrary application events into AG-UI or Vercel events, manage conversation history, or submit tool results; those responsibilities remain with the backend protocol adapter, SDK, and application.

## Limits and recovery

All transports share the same reconciliation limits: 1,024 queued logical events, 4,096 buffered parts, and 8 MiB of encoded payload strings and event names. A consumer supports at most 64 active segments and remembers up to 1,024 total segment IDs. Recreate a long-lived consumer after reconciliation when its segment limit is reached. These limits do not bound total JavaScript heap usage.

A missing sequence or incomplete event has a 30-second deadline. Ordered delivery resets the deadline; duplicates and later arrivals do not. Malformed envelopes, conflicting queued duplicates, exceeded limits, and expired deadlines close the consumer and call `onGap`. Already delivered duplicates are ignored without retaining payload history. Queued plain events are compared by serialized JSON representation.

The package cannot detect a lost final event, silence before the first event, or a connection failure without subsequent frames. Watch transport connection state and backend run status/timeouts. Close subscriptions on disconnection and reconcile from authoritative history. Sequence numbers detect ordering gaps; they cannot replay lost events. A source ending or a local `close()` call is not evidence of successful backend completion.

This contract is supplied by Neuron's `RedisChannel` and `PusherChannel` and custom channels extending `AbstractChannel`. `CallbackChannel` bypasses the envelope contract; ordinary SSE from `SSEEncoder` already carries protocol objects and does not need this reconciliation layer.

## Development

From the repository root:

```sh
npm ci
npm test                 # build and package unit tests
npm run typecheck
npm run test:frontend -- --project channels
npm run pack:streaming
npm run test:packed -- --project channels
```

The channel browser suite verifies PHP envelopes, encrypted Pusher delivery, and fragmented PHP AG-UI/Vercel output consumed by the official SDKs. Browser tests require Composer dev dependencies and Playwright Chromium. See [the integration suite](../../tests/Integration/Frontend/README.md) and [release instructions](RELEASING.md).

Package versions are independent of PHP versions; compatibility is verified against the backend revision being released.
