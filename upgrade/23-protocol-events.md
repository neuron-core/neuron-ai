# Upgrade: Stream adapters emit protocol events, SSE framing moves to the edge

## Summary

In 3.x a stream adapter turned every chunk into ready-to-send SSE strings
(`data: {...}\n\n`), so `Agent::stream()` with an adapter attached yielded strings and an
endpoint echoed them. That fused the protocol (AG-UI, Vercel AI SDK) with one transport
(SSE over HTTP), which is wrong for every other destination: a broadcast channel, a Redis
stream or a websocket needs the event, not a framed line.

In 4.x the chain is split in two:

- **Adapters emit `NeuronAI\Workflow\Streaming\ProtocolEvent` objects**, one per wire
  event: a `type` plus a JSON-serializable `data` array. `jsonSerialize()` returns the
  full wire object with `type` first. `Workflow::events()` and `Agent::stream()` yield
  these objects when an adapter is attached, and the same instances reach an attached
  streaming channel.
- **`NeuronAI\Workflow\Streaming\SSEEncoder` frames them at the HTTP edge.**
  `SSEEncoder::encode($generator)` yields the `data:` lines and forwards the generator's
  return value; `SSEEncoder::frame($event)` frames a single event.

| 3.x | 4.x |
|---|---|
| `SSEAdapter` base class (`sse()`, `generateId()`, `getHeaders()`) | Removed. Adapters implement `StreamAdapterInterface` directly, build `new ProtocolEvent($type, $data)`, generate ids with `UniqueIdGenerator::generateId('msg_')` and declare `getHeaders()` themselves |
| `transform()`, `start()`, `end()`, `interrupt()`, `error()` return `iterable<string>` | They return `iterable<ProtocolEvent>` |
| A new adapter instance per segment is the only option | Adapters also implement `reset()`, called by the Workflow before every segment, so one instance can serve a suspension and its continuation |
| `Agent::stream()` yields SSE strings when an adapter is attached | It yields `ProtocolEvent` objects; an SSE endpoint wraps the generator with `SSEEncoder::encode()` |
| `VercelAIAdapter` ends every stream with `data: [DONE]` | No sentinel: the stream ends with the `finish` (or `error`) event and the response closing. The AI SDK client discards `[DONE]`, and non-SSE transports never carried it |
| `getHeaders()` inherited from `SSEAdapter` | Unchanged for the built-in adapters: still declared on `AGUIAdapter` and `VercelAIAdapter` |

New in 4.x, and therefore absent from a 3.x codebase, is the streaming channel
(`StreamingChannelInterface`, `CallbackChannel`, `FakeChannel`). Its `send(ProtocolEvent)`
port receives the adapter's events, and a channel without an adapter receives only the
segment lifecycle; there is nothing to migrate.

## What to Search For

Search the whole application, including tests and config, excluding `vendor/`:

```
grep -rn "SSEAdapter\|\$this->sse(\|generateId(" --include="*.php" .
grep -rn "getHeaders()\|text/event-stream" --include="*.php" .
grep -rn "\[DONE\]" --include="*.php" --include="*.ts" --include="*.js" .
```

The first finds custom adapters. The second finds the endpoints that echo the stream and
must now frame it. The third finds tests or clients that wait for the Vercel sentinel.

## How to Refactor

### Case 1: An SSE endpoint that echoes the stream

Before:

```php
$stream = $agent->setStreamAdapter($adapter)->stream(new UserMessage($input));

foreach ($adapter->getHeaders() as $name => $value) {
    header("{$name}: {$value}");
}
foreach ($stream as $line) {
    echo $line;
    flush();
}
```

After:

```php
use NeuronAI\Workflow\Streaming\SSEEncoder;

$stream = $agent->setStreamAdapter($adapter)->stream(new UserMessage($input));

foreach ($adapter->getHeaders() as $name => $value) {
    header("{$name}: {$value}");
}
$lines = SSEEncoder::encode($stream);
foreach ($lines as $line) {
    echo $line;
    flush();
}
$state = $lines->getReturn(); // the final AgentState, as before
```

The bytes on the wire are identical, except that a Vercel stream no longer ends with
`data: [DONE]`.

### Case 2: A custom adapter extending `SSEAdapter`

Before:

```php
use NeuronAI\Chat\Messages\Stream\Adapters\SSEAdapter;

final class MyProtocolAdapter extends SSEAdapter
{
    public function transform(object $chunk): iterable
    {
        if ($chunk instanceof TextChunk) {
            yield $this->sse(['type' => 'delta', 'id' => $this->generateId('msg'), 'text' => $chunk->content]);
        }
    }

    public function end(): iterable
    {
        yield $this->sse(['type' => 'done']);
    }
    // ...
}
```

After:

```php
use NeuronAI\UniqueIdGenerator;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;

final class MyProtocolAdapter implements StreamAdapterInterface
{
    public function transform(object $chunk): iterable
    {
        if ($chunk instanceof TextChunk) {
            yield new ProtocolEvent('delta', ['id' => UniqueIdGenerator::generateId('msg_'), 'text' => $chunk->content]);
        }
    }

    public function end(): iterable
    {
        yield new ProtocolEvent('done');
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no'];
    }
    // ...
}
```

Move the `type` key out of the array into the first constructor argument and keep every
other key in `data`. Keep payload values JSON-serializable (arrays, scalars, `(object) []`
for an empty JSON object). If the adapter wrote raw non-JSON lines such as a
`[DONE]`-style sentinel, drop them: the transport, not the protocol, decides how a
stream ends.

### Case 3: Tests asserting on SSE strings

Before:

```php
$events = array_map(fn (string $line) => json_decode(substr($line, 6, -2), true), $frames);
$this->assertSame("data: [DONE]\n\n", array_pop($frames));
```

After:

```php
$events = array_map(fn (ProtocolEvent $event) => json_decode(json_encode($event), true), $frames);
$this->assertSame('finish', $frames[array_key_last($frames)]->type);
```

`json_decode(json_encode($event), true)` yields the exact array the wire carried, so
existing expectations keep working. To assert on bytes, frame first with
`SSEEncoder::frame($event)`.

### Case 4: Clients waiting for `[DONE]`

The Vercel AI SDK client ignores the sentinel and completes when the response closes, so
`useChat` needs no change. A custom consumer that treated `[DONE]` as the end of the
stream should stop on the `finish` event or on the end of the response.

## Verification Checklist

- [ ] The search patterns above return no matches outside `vendor/`
- [ ] Every SSE endpoint wraps the generator with `SSEEncoder::encode()` and still sends
      the adapter's headers
- [ ] Custom adapters implement `StreamAdapterInterface`, yield `ProtocolEvent`s only and
      pass `vendor/bin/phpstan`
- [ ] No test or client waits for `data: [DONE]`
