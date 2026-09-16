# Channel wire contract

`RedisChannel` publishes each envelope as JSON. `PusherChannel` passes the envelope to the injected official Pusher SDK as already-encoded event `data`, named by the envelope's `type`. On `private-encrypted-*` channels the SDK encrypts it, and the encryption-enabled browser SDK restores the envelope before invoking callbacks. `CallbackChannel` calls its configured closures directly and does not use this contract. Pull streaming and SSE remain protocol events; this envelope belongs only to broadcast channel delivery.

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

Ordering is a consumer responsibility. Pusher [guarantees order within a batch, not between batches](https://docs.bird.com/pusher/channels/channels/events/why-dont-channels-events-arrive-in-order). A terminal event can therefore arrive before earlier data. Do not close on its arrival until preceding sequences are complete, or declare a gap and reconcile from application history. Redis orders one publisher's messages, but different segments can share a destination and must still be distinguished.

## Browser consumer example

This example consumes **one selected segment**. Route by `streamId` at the application edge; do not combine simultaneous streams. The limits below are example application limits, not PHP transport limits. Adjust them for expected payload sizes. Subscribe before execution, and reconcile a late subscription from history instead of treating its first received sequence as zero.

`onEvent` receives `{type, data}` after ordering and reassembly. A protocol bridge can convert this to the flat `{...data, type}` representation used by `SSEEncoder`. The supplied `onGap` callback should discard partial UI output and reload authoritative application history. Call `close()` on unmount and call `onGap` on transport disconnection; if completion itself is lost without a disconnect, use an application-level run timeout/status check.

```js
function createChannelConsumer(streamId, onEvent, onGap) {
  const pending = new Map();
  const terminalTypes = new Set(['stream.completed', 'stream.interrupted', 'stream.failed']);
  let next = 0;
  let bufferedBytes = 0;
  let bufferedParts = 0;
  let timer = null;
  let closed = false;

  function close() {
    closed = true;
    clearTimeout(timer);
    pending.clear();
    bufferedBytes = 0;
    bufferedParts = 0;
  }

  function fail(reason) {
    close();
    onGap(reason);
  }

  function accept(frame) {
    if (closed || frame.streamId !== streamId) return;
    if (!Number.isSafeInteger(frame.sequence) || frame.sequence < 0 || typeof frame.type !== 'string') {
      return fail('Invalid channel envelope');
    }
    if (frame.sequence < next) return; // Already consumed duplicate.
    const fragment = frame.type === 'stream.fragment';
    const data = frame.data;
    if (fragment && (!data || typeof data.event !== 'string' || typeof data.part !== 'string'
      || !/^[A-Za-z0-9_=-]*$/.test(data.part) || !Number.isSafeInteger(data.index)
      || !Number.isSafeInteger(data.total) || data.total < 1 || data.total > 4096
      || data.index < 0 || data.index >= data.total)) {
      return fail('Invalid channel fragment');
    }
    let entry = pending.get(frame.sequence);
    if (entry && (entry.fragment !== fragment || (fragment && (entry.type !== data.event || entry.total !== data.total)))) {
      return fail('Conflicting channel fragments');
    }
    if (!entry) {
      entry = { fragment, type: fragment ? data.event : frame.type, total: fragment ? data.total : 1, parts: new Map(), bytes: 0, data };
      pending.set(frame.sequence, entry);
    }
    const index = fragment ? data.index : 0;
    if (entry.parts.has(index)) return;
    const part = fragment ? data.part : JSON.stringify(data);
    if (typeof part !== 'string') return fail('Missing channel payload');
    entry.parts.set(index, part);
    ++bufferedParts;
    const bytes = new TextEncoder().encode(part).length;
    entry.bytes += bytes;
    bufferedBytes += bytes;
    if (pending.size > 1024 || bufferedParts > 4096 || bufferedBytes > 8 * 1024 * 1024) {
      return fail('Channel buffer limit exceeded');
    }

    clearTimeout(timer);
    while (pending.has(next)) {
      const ready = pending.get(next);
      if (ready.parts.size !== ready.total) break;
      let payload = ready.data;
      if (ready.fragment) {
        const parts = Array.from({ length: ready.total }, (_, i) => ready.parts.get(i));
        try {
          payload = JSON.parse(atob(parts.join('').replace(/-/g, '+').replace(/_/g, '/')));
        } catch {
          return fail('Invalid reassembled payload');
        }
      }
      pending.delete(next++);
      bufferedBytes -= ready.bytes;
      bufferedParts -= ready.parts.size;
      const terminal = terminalTypes.has(ready.type);
      if (terminal) close();
      onEvent({ type: ready.type, data: payload });
      if (terminal) return;
    }
    if (pending.size) timer = setTimeout(() => fail('Missing channel events'), 30_000);
  }

  return { accept, close };
}
```

For Pusher, feed the second argument of `channel.bind_global((name, envelope) => ...)` into `accept`, excluding `pusher:*` control events. For Redis, parse the published JSON first. A multiplexer needs its own bound on active stream IDs and must dispose each consumer on completion, failure, disconnect, or application timeout. Sequence numbers cannot recover lost events.

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

At termination, pending data is flushed separately from the terminal event so its failure cannot suppress the terminal attempt. If either fails, the failure propagates to Workflow's `ChannelError` reporting. Channel state is reset in either case. Data delivery stops after the first transport failure for that segment; serialization and configuration errors are reported without classifying them as transport outages. Reuse a channel only after a segment's terminal method completes, never concurrently or after abandoning a generator mid-segment.
