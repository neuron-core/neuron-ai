# Upgrade: Streamed chunks carry Neuron message IDs

## Summary

Every chunk a provider streams (text, reasoning, tool arguments, image, audio) now carries the Neuron ID of the message the stream returns. So `$chunk->messageId` is the same value as `$message->getId()` on the returned message and in the message store. In 3.x it was usually the vendor's ID.

| 3.x | 4.x |
|-----|-----|
| Chunk `messageId` was Anthropic's `msg_…`, the Chat Completions `id` (OpenAI and every provider extending it: OpenAILike, AzureOpenAI, Deepseek, Grok, ZAI, HuggingFace, DashScopeOpenAI), Cohere's `id`, or OpenAI Responses' `item_id` (a different one for each output item) | One generated Neuron ID per stream, shared by all its chunks and set on the returned message |
| `FakeAIProvider` chunks carried `uniqid('fake_msg_')` | They carry the queued message's `getId()` |
| `BasicStreamState::messageId(?string $id = null)` set the ID and read it | `messageId(): string` only reads it: one ID per state instance |
| `Message` had no ID setter | `Message::setId(string $id): static` |
| `ElevenLabsTextToSpeech::stream()`: `AudioChunk::$content` was raw audio bytes | It is the base64 of that chunk's bytes, as `OpenAITextToSpeech` already did |
| Ollama tool calls: `ToolCall::getCallId()` was `null` | A generated unique ID |
| Gemini / GeminiVertex tool calls: `getCallId()` was the tool name | The API's `functionCall.id`, or a generated unique ID |

Other guides handle these related changes. Leave them for their guide:
- `ToolCallChunk` / `ToolResultChunk`: guide 39. `ToolResultChunk::$messageId` stays `null`.
- The message IDs the Vercel AI and AG-UI adapters send to frontends: guide 37.
- `stream()` returning a `ProviderResponse`, and `getReturn()` reads on provider streams: guide 41.

**Stored data:** Neuron never stored chunk IDs, so there is nothing to migrate. 3.x chunk IDs the app saved in its own tables match no stored message, as they never did: leave them. Chat histories written by 3.x keep their Gemini call IDs (the tool name) and Ollama call IDs (`null`). 4.x reads and replays them as-is, because the Gemini and Ollama message mappers never send call IDs back to the API.

## What to Search For

Run from the application root:

```bash
grep -rn 'messageId' --include='*.php' --exclude-dir=vendor .
grep -rnE 'implements AIProviderInterface|new (TextChunk|ReasoningChunk|ImageChunk|AudioChunk)\(' --include='*.php' --exclude-dir=vendor .
grep -rn 'fake_msg_' --include='*.php' --exclude-dir=vendor .
grep -rnE 'AudioChunk|ElevenLabsTextToSpeech' --include='*.php' --exclude-dir=vendor .
grep -rn 'getCallId()' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:
- **`messageId`**:
  - `messageId(...)` called with an argument, or a `function messageId(` override: Case 1.
  - `$chunk->messageId` read in application code: Case 2. Read in tests: Case 3.
  - Inside a custom stream adapter: nothing to do here. Guide 35 migrates adapters, and guide 37 covers the frames they send.
  - `new ToolCallChunk(...)`: guide 39.
- **`implements AIProviderInterface` / `new TextChunk(` and the other chunk constructors**: a class with a `stream()` method that yields chunks is Case 1. This includes subclasses of built-in providers that override stream internals. Chunks built by hand in tests (for example, to feed an adapter) need no change, because any string ID works.
- **`fake_msg_`**: Case 3.
- **`AudioChunk` / `ElevenLabsTextToSpeech`**: follow each `ElevenLabsTextToSpeech` instance to the loop that consumes its `stream()` chunks: Case 4. Code that only handles `OpenAITextToSpeech` chunks needs no change.
- **`getCallId()`**: Case 5, when the code compares a call ID with a tool name or with `null`, or falls back to the tool name.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: A custom provider or provider subclass that streams

Before (3.x):

```php
use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Providers\BasicStreamState;

/**
 * @return Generator<int, StreamChunk, mixed, Message>
 */
public function stream(Message ...$messages): Generator
{
    $this->streamState = new BasicStreamState();
    $text = '';

    foreach ($this->client->streamEvents($messages) as $event) {
        $this->streamState->messageId($event['id']);             // the vendor's ID
        $this->streamState->addOutputTokens($event['usage']['output_tokens'] ?? 0);
        $text .= $event['delta'];
        yield new TextChunk($this->streamState->messageId(), $event['delta']);
    }

    $message = new AssistantMessage($text);
    $message->setUsage($this->streamState->getUsage());

    return $message;
}
```

After (4.x):

```php
/**
 * @return Generator<int, StreamChunk, mixed, Message>
 */
public function stream(Message ...$messages): Generator
{
    $this->streamState = new BasicStreamState();                 // a new state for every stream() call
    $text = '';

    foreach ($this->client->streamEvents($messages) as $event) {
        $this->streamState->addOutputTokens($event['usage']['output_tokens'] ?? 0);
        $text .= $event['delta'];
        yield new TextChunk($this->streamState->messageId(), $event['delta']);
    }

    $message = new AssistantMessage($text);
    $message->setId($this->streamState->messageId())->setUsage($this->streamState->getUsage());

    return $message;                                             // guide 41 wraps it in a ProviderResponse
}
```

1. Delete every argument passed to `messageId()`, such as `messageId($event['id'])` or `messageId($line['id'] ?? null)`. Delete the whole call when it only stored the vendor's ID. PHP ignores the extra argument without an error, so only the search or PHPStan (`arguments.count`) shows the leftovers.
2. Create the stream state (or your `BasicStreamState` subclass, or any built-in `StreamState`) at the start of every `stream()` call. A state reused across calls returns the same ID for every stream. The message stores keep only the first message with a given ID, so later answers would be silently missing from the history.
3. Set the stream's ID on the returned message on every exit, including the tool-call message: `$message->setId($this->streamState->messageId())`.
4. In a `BasicStreamState` subclass that overrides `messageId()`, remove the `$id` parameter and the code that stored it. Delete the override if nothing else is left.
5. Leave `return $message;` as it is. Guide 41 wraps it in a `ProviderResponse`.

A subclass of a built-in provider that only overrides chunk hooks, such as `processContentDelta()` on an OpenAI-based provider, and yields with `$this->streamState->messageId()` needs no change. Guide 43 covers the other protected members of built-in providers.

A provider without a stream state generates the ID itself.

Before (3.x):

```php
$response = $this->client->openStream($messages);
$text = '';

foreach ($response->deltas() as $delta) {
    $text .= $delta;
    yield new TextChunk($response->id(), $delta);                // the vendor's ID
}

return new AssistantMessage($text);
```

After (4.x):

```php
use NeuronAI\UniqueIdGenerator;

$response = $this->client->openStream($messages);
$messageId = UniqueIdGenerator::generateId('msg_');
$text = '';

foreach ($response->deltas() as $delta) {
    $text .= $delta;
    yield new TextChunk($messageId, $delta);
}

return (new AssistantMessage($text))->setId($messageId);         // guide 41 wraps it in a ProviderResponse
```

A provider that already holds the message before it streams, as `FakeAIProvider` does, yields `$message->getId()` instead. Never give the message the vendor's ID or any other ID that can repeat.

### Case 2: Code that reads `$chunk->messageId`

Before (3.x):

```php
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;

foreach ($stream as $chunk) {
    if ($chunk instanceof TextChunk || $chunk instanceof ReasoningChunk) {
        // OpenAI Responses gave reasoning and text different item IDs
        $parts[$chunk->messageId] = ($parts[$chunk->messageId] ?? '') . $chunk->content;
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;

foreach ($stream as $chunk) {
    if ($chunk instanceof ReasoningChunk) {
        $reasoning .= $chunk->content;
    } elseif ($chunk instanceof TextChunk) {
        $text .= $chunk->content;
    }
}
```

1. Remove code that treats `$chunk->messageId` as the vendor's ID, for example logging it as the Anthropic or OpenAI response ID, or looking it up with the vendor. No 4.x API exposes the vendor's stream ID. If the developer relied on it (support requests, cost reconciliation), report it to them rather than inventing a replacement.
2. All chunks of one provider response now share one ID. Code that split a stream into parts whenever the ID changed must tell reasoning from text by the chunk class (`ReasoningChunk`, `TextChunk`), as above.
3. Code that paired chunks with stored messages by other means (timing, order) can compare `$chunk->messageId` with `$message->getId()`.

### Case 3: Tests that assert chunk IDs

Before (3.x):

```php
$this->assertSame('msg_01XFDUDYJgAACzvnptvVoYEL', $chunk->messageId);   // the vendor ID in a faked HTTP body
$this->assertStringStartsWith('fake_msg_', $chunk->messageId);          // FakeAIProvider
```

After (4.x), taking `$messageId` from whichever source fits the test:

```php
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;

// Provider stream
$stream = $provider->stream(new UserMessage('Hi'));
$chunks = iterator_to_array($stream, false);                 // getReturn() needs a finished stream
$messageId = $stream->getReturn()->message()->getId();

// Agent stream
$stream = $agent->stream(new UserMessage('Hi'));
$chunks = iterator_to_array($stream, false);
$messageId = $stream->getReturn()->getMessage()->getId();    // the final answer

// FakeAIProvider behind either stream: the queued message
$provider = new FakeAIProvider($answer = new AssistantMessage('Hello there'));
$messageId = $answer->getId();

foreach ($chunks as $chunk) {
    if ($chunk instanceof TextChunk) {
        $this->assertSame($messageId, $chunk->messageId);
    }
}
```

1. Replace vendor IDs and `fake_msg_` expectations with the ID of the message the stream returned, or the queued message's ID. The same holds for `ReasoningChunk`.
2. On a provider stream, `getReturn()` returns a `ProviderResponse`, so `->message()` is needed. Guide 41 migrates the other `getReturn()` reads.
3. In an Agent stream, the chunks streamed before a tool call carry the ID of that `ToolCallMessage`, not the final answer's ID. Compare those chunks with the tool-call message, or assert only on the chunks of the final answer.

### Case 4: ElevenLabs streamed audio

Before (3.x):

```php
use NeuronAI\Chat\Messages\Stream\Chunks\AudioChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ElevenLabs\ElevenLabsTextToSpeech;

$tts = new ElevenLabsTextToSpeech(key: $key, model: 'eleven_multilingual_v2', voiceId: $voiceId);

foreach ($tts->stream(new UserMessage($text)) as $chunk) {
    if ($chunk instanceof AudioChunk) {
        fwrite($out, $chunk->content);                                   // raw audio bytes
        // or, sent to a browser:
        echo 'data: ' . json_encode(['audio' => base64_encode($chunk->content)]) . "\n\n";
    }
}
```

After (4.x):

```php
foreach ($tts->stream(new UserMessage($text)) as $chunk) {
    if ($chunk instanceof AudioChunk) {
        fwrite($out, base64_decode($chunk->content));                    // decode each chunk on its own
        // or, sent to a browser:
        echo 'data: ' . json_encode(['audio' => $chunk->content]) . "\n\n";   // already base64
    }
}
```

1. Where ElevenLabs chunk content is written, played or returned as bytes, pass it through `base64_decode()`.
2. Decode each chunk separately. Never join the base64 strings before decoding, because joined base64 is not valid base64.
3. Where the app base64-encoded ElevenLabs chunks itself, remove that `base64_encode()`.
4. Remove any branch that treated ElevenLabs chunks as raw bytes and `OpenAITextToSpeech` chunks as base64. Decode both the same way.
5. The audio message the stream returns (an `AudioContent` holding the base64 of the whole audio) is unchanged.

### Case 5: Tool call IDs from Gemini and Ollama

Before (after guide 4, with 3.x provider behaviour):

```php
foreach ($message->getToolCalls() as $call) {
    $key = $call->getCallId() ?? $call->getName();          // Ollama sent no call ID
    $log[$key] = $call->getInputs();

    if ($call->getCallId() === 'get_weather') {              // Gemini used the tool name as call ID
        $log['weather'] = true;
    }
}
```

After (4.x):

```php
foreach ($message->getToolCalls() as $call) {
    $key = $call->getCallId();
    $log[$key] = $call->getInputs();

    if ($call->getName() === 'get_weather') {
        $log['weather'] = true;
    }
}
```

1. Replace comparisons of a call ID with a tool name with `$call->getName() === '<tool>'`. Where the app built a Gemini call ID from the tool name, read `$call->getCallId()` instead.
2. Remove fallbacks written for Ollama's missing call ID: `getCallId() === null` branches and `?? $call->getName()`. Keep a fallback only if one of the app's own providers builds calls without an ID.
3. Key per-call data by `getCallId()`. With Gemini, parallel calls to the same tool now have different IDs.

## Checklist

- `grep -rnE 'messageId\([^)]' --include='*.php' --exclude-dir=vendor .` finds no call that passes an argument and no override that declares a parameter.
- Every custom stream creates its state or ID inside `stream()` and sets it on the returned message on every exit (tool call and plain answer).
- No code reads a vendor ID from `$chunk->messageId`, or splits a stream by changes of that ID.
- No test expects a vendor ID or a `fake_msg_…` ID on a chunk.
- ElevenLabs `AudioChunk` content is base64-decoded chunk by chunk wherever bytes are needed, and is not base64-encoded again.
- No code compares `getCallId()` with a tool name or relies on a `null` Ollama call ID.
- PHPStan reports no `arguments.count` error on `messageId()`.
