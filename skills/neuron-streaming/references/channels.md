# Channel wire contract

`RedisChannel` publishes each envelope as JSON. `PusherChannel` passes the envelope to the injected official Pusher SDK as already-encoded event `data`, named by the envelope's `type`. On `private-encrypted-*` channels the SDK encrypts it, and the encryption-enabled browser SDK restores the envelope before invoking callbacks. `MercureChannel` publishes a JSON array of envelopes as the `data` of each update, with no SSE event type, so a single `message` listener receives every update. `CallbackChannel` calls its configured closures directly and does not use this contract. Pull streaming and SSE remain protocol events; this envelope belongs only to broadcast channel delivery.

```json
{"streamId":"81d4f00e881563538f272ca26aa7a8d4","sequence":0,"type":"text-delta","data":{"id":"message-1","delta":"Hello"}}
```

- `streamId` is a random, 32-character hexadecimal identifier for one execution segment. It is not a workflow ID, authorization credential, or durable run ID. A new segment gets a new identifier, including after interruption or failure.
- `sequence` starts at zero and increases once per logical event. It identifies ordering and gaps; it is not an acknowledgement or replay cursor.
- `type` names the protocol event. `data` preserves its payload, with invalid UTF-8 replaced by U+FFFD.
- `stream.completed`, `stream.interrupted`, and `stream.failed` are terminal events whose `data` is `{workflowId}`. They do not expose workflow state or exceptions. Transport frames use the reserved `stream.*` namespace.

An oversized event becomes one or more `stream.fragment` envelopes:

```json
{"streamId":"81d4f00e881563538f272ca26aa7a8d4","sequence":1,"type":"stream.fragment","data":{"event":"tool-output-available","index":0,"total":3,"part":"eyJvdXRwdXQiOiJoZWxsbyJ9"}}
```

All fragments of one logical event share `(streamId, sequence)`. Sort their zero-based `index` values, concatenate `part`, translate URL-safe base64 to standard base64, decode, and parse the resulting JSON to recover the original `data`. Unicode remains JSON-escaped, so decoded JSON is ASCII. `event` is the original type. The final fragment may contain base64 padding; earlier fragments do not.

Ordering is a consumer responsibility. Pusher [guarantees order within a batch, not between batches](https://docs.bird.com/pusher/channels/channels/events/why-dont-channels-events-arrive-in-order). A terminal event can therefore arrive before earlier data. Do not close on its arrival until preceding sequences are complete, or declare a gap and reconcile from application history. Redis orders one publisher's messages, but different segments can share a destination and must still be distinguished. `MercureChannel` publishes one update after the other, so a hub delivers a segment's updates in stream order, and the envelopes inside an update are in stream order.

## Browser consumer

Install `@neuron-core/streaming`; the package owns ordering, fragmentation, duplicate handling, buffer limits, and gap deadlines.

```js
import { subscribeToPusher } from '@neuron-core/streaming';

const subscription = subscribeToPusher(channel, {
  onEvent: ({ type, data }) => renderEvent(type, data),
  onGap: (reason) => reloadConversation(reason),
});

// On cleanup or transport disconnection:
subscription.close();
```

Pass a channel from the official Pusher browser SDK, using `pusher-js/with-encryption` for encrypted channels. Wait for subscription success before starting backend execution. `onEvent` receives `{streamId, sequence, type, data}` after ordering and reassembly. Multiple segment IDs are tracked independently; a gap closes the subscription before calling `onGap`.

For another transport, or to consume one selected segment (omit the second argument to discover segments automatically):

```js
import { createChannelConsumer } from '@neuron-core/streaming';

const consumer = createChannelConsumer({ onEvent, onGap }, streamId);
consumer.accept(JSON.parse(message));
consumer.close(); // on cleanup
```

For a Mercure hub, open the `EventSource` with the hub's `match` parameter, once the application has set the subscriber cookie, and pass it to `subscribeToMercure`:

```js
import { subscribeToMercure } from '@neuron-core/streaming';

const url = new URL('https://hub.example.com/.well-known/mercure');
url.searchParams.append('match', `https://example.com/threads/${threadId}`);
const source = new EventSource(url, { withCredentials: true });

const subscription = subscribeToMercure(source, {
  onEvent: ({ type, data }) => renderEvent(type, data),
  onGap: (reason) => reloadConversation(reason),
});

// On cleanup:
subscription.close();
source.close();
```

A Mercure update holds a JSON array of envelopes, however many the channel packed into that request, and nothing in it announces the count. The adapter unwraps the array: `onEvent` receives one event at a time, in order, as with every other transport. A whole update passed to `createChannelConsumer().accept()` is refused as `Invalid channel envelope`; the core takes one envelope. The third argument selects one segment, as for Pusher, and `close()` leaves the `EventSource` open.

Wait for the source's `open` event before starting backend execution. An `EventSource` reconnects with the ID of the last update it received, and a hub with history replays the updates published since: the consumer ignores the ones it already delivered, so a short interruption leaves no gap. History is the hub's: Mercure Cloud retains about a hundred updates for the whole hub, and a hub on the `local` transport none. When the source's `error` event finds its `readyState` `CLOSED`, the browser has given up: close the subscription and reconcile.

The AG-UI `HttpAgent` and the default `useChat` transport read one SSE response from the application, so they cannot read a channel, whichever its transport. Give them a custom `AbstractAgent` or `ChatTransport` built on `createProtocolStream`, which turns one segment of a subscription into the validated protocol objects those clients expect: they then receive AG-UI or Vercel events one by one and never see envelopes, fragments or packed updates. The [package guide](../../../packages/streaming/README.md) has both. Where the stock client must stay, relay the run through `RedisChannelReader` instead.

Reconcile late subscriptions and transport disconnections from authoritative history. A lost final event or silence before the first event requires an application run timeout/status check. The package cannot replay events. See the [package guide](../../../packages/streaming/README.md) for limits, lifecycle details, and TypeScript usage.

## Redis consumer in PHP

`RedisChannelReader` reads what `RedisChannel` publishes, for the process holding the client's connection. `listen()` passes the channel's first segment to a callback as protocol events and returns at its terminal event. A segment that started before it subscribed, or that another segment replaces before its terminal event, raises `ChannelReadException`, as does silence past its timeout. Redis delivers one publisher's messages in order and `RedisChannel` never fragments, so it needs no reordering or reassembly. Pub/Sub keeps nothing for late subscribers: build the worker's channel with `awaitListener`, so its first publish waits for the reader. Example: the RedisChannel section of the skill.

## Implementing a transport

`AbstractChannel` owns the final orchestration methods. The only required hook is `deliver(string $batch)`: transmit the batch and throw on failure. SDK-backed transports may transform it during delivery if `batchBytes()` conservatively accounts for that transformation. The base class does not retain failed batches or retry ambiguous delivery.

Optional protected hooks:

| Hook | Responsibility | Default |
| --- | --- | --- |
| `encode(string $type, string $envelope): string` | Wrap one already-serialized envelope for the transport | Return the envelope |
| `batch(array $events): string` | Combine encoded events, including delimiters and wrapper | Concatenate |
| `batchBytes(array $events): int` | Delivery size or a safe upper bound after SDK transformations | Byte length of `batch($events)` |
| `budget(): ?int` | Maximum delivery bytes, including the batch wrapper | No ceiling |
| `eventBudget(): ?int` | Maximum JSON-envelope bytes before transport wrapping | No ceiling |
| `batchSize(): int` | Maximum event count per delivery | 1 |

Encoding and measurement hooks must be deterministic and free of I/O. Do not deserialize and reserialize application payloads. Fragment sizing searches for a capacity that fits both limits, including transport expansion. Measurements must grow monotonically with fragment length, and URL-safe base64 characters must have uniform cost or be measured by a safe upper bound. Every actual fragment is checked again; invalid limits and envelopes too large to leave fragment space fail explicitly with `LengthException`.

Pusher sends through `triggerBatch(..., true)`, letting the SDK encrypt and sign once at delivery. Unencrypted request sizes are exact. Encrypted measurements conservatively allow every base64 character to require JSON slash escaping, both inside the encrypted payload and in the request. The plaintext event budget is reduced accordingly so the encrypted `data` stays within 10,000 bytes. This may underfill encrypted requests; it avoids depending on private SDK encoders or sending an oversized request.

```php
use NeuronAI\Workflow\Streaming\Channel\AbstractChannel;

final class SocketServerChannel extends AbstractChannel
{
    public function __construct(
        protected SocketServer $server,
        protected string $room,
        protected int $maxFrameBytes = 65_536,
    ) {
    }

    protected function budget(): ?int
    {
        return $this->maxFrameBytes;
    }

    protected function deliver(string $batch): void
    {
        $this->server->broadcast($this->room, $batch);
    }
}
```

Pusher's `batch()` stages already-encoded envelopes in `{"batch":[...]}` for the SDK. Its 10,000-byte event-data ceiling is independent of `maxRequestBytes`; increasing request capacity never permits larger individual events. Batch size defaults to ten events and accepts values from 1 to 50. Select a batch size supported by the configured server; byte limits may flush smaller batches. Configure timeouts, endpoint settings and encryption on the injected `Pusher\Pusher` client. The application owns private-channel authorization; encrypted channels also require the SDK master key and an encryption-enabled browser client. See the [Pusher setup example](../SKILL.md#pusherchannel).

Mercure's `batch()` wraps the envelopes in a JSON array, and its `batchBytes()` measures the form-encoded request the SDK sends, where JSON punctuation takes three bytes. Pacing uses the same hooks: `batchSize()` answers 1 while the hub accepts a request and no limit while it does not, so the pending envelopes leave together when the slot opens, and `deliver()` waits for the slot before a delivery that cannot be put off (a full update, the end of the segment) and sends an update refused with 429 again. The wait is bounded at ten seconds the run has not worked off; past it `deliver()` throws and the segment's delivery stops.

At termination, pending data is flushed separately from the terminal event so its failure cannot suppress the terminal attempt. If either fails, the failure propagates to Workflow's `ChannelError` reporting. Data delivery stops after the first transport failure for that segment; serialization and configuration errors are reported without classifying them as transport outages. A channel serves one segment: the Workflow builds a new one for every segment through the `setChannel()` factory or the `channel()` hook.
