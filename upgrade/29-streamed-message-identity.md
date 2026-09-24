# Upgrade: Streamed chunks carry the ID of the message they build

## Summary

Every chunk of a streamed response now carries the ID of the message the stream returns. A provider
generates one Neuron ID per stream, every chunk carries it, and the returned message takes it with the new
`Message::setId()`. A client that rendered the stream finds the same ID in storage, which lets a reloaded
page rebuild the conversation it shows (`AGUIAdapter::hydrate()`, guide 30).

- **Vendor IDs are no longer chunk IDs.** The chunks' `messageId` used to be Anthropic's `msg_…`, the `id` of
  OpenAI Chat Completions (and of every provider built on it, `OpenAILike` included) or Cohere's `id`; OpenAI
  Responses used a different `item_id` for each output item of one response. None of them was ever stored,
  and message IDs now deduplicate history writes, where a repeated or empty vendor ID would drop messages.
- **`BasicStreamState::messageId()` takes no argument.** It returns the stream's ID, generating it on first use.
- **`FakeAIProvider` streams with the queued message's ID** instead of a `fake_msg_…` ID.
- **Ollama tool calls have IDs.** Its API sends none; the provider now generates a unique call ID, as Gemini
  does when a call has none, so approvals, durable memos and frontend results can address each call.

## How to Refactor

### Case 1: Code reading the vendor's ID from chunks

There is no replacement: the vendor's ID was never stored, and chunks no longer carry it. Correlate with the
provider through other means, such as request timing or the provider's own logs. `$chunk->messageId` is now
the ID of the message you will find in the message store.

### Case 2: A custom provider

A provider that streams must use one ID for the whole stream and give it to the message it returns.

Before:

```php
public function stream(Message ...$messages): Generator
{
    $response = $this->openStream($messages);
    $text = '';
    foreach ($response->deltas() as $delta) {
        $text .= $delta;
        yield new TextChunk($response->id, $delta);   // the vendor's ID
    }

    return new ProviderResponse(message: new AssistantMessage($text));
}
```

After:

```php
use NeuronAI\UniqueIdGenerator;

public function stream(Message ...$messages): Generator
{
    $response = $this->openStream($messages);
    $messageId = UniqueIdGenerator::generateId('msg_');
    $text = '';
    foreach ($response->deltas() as $delta) {
        $text .= $delta;
        yield new TextChunk($messageId, $delta);
    }

    return new ProviderResponse(message: (new AssistantMessage($text))->setId($messageId));
}
```

A provider extending a `BasicStreamState` subclass removes the argument from `messageId()` and sets the
state's ID on the message it builds, on every exit (tool call and plain answer):

```php
$message->setId($this->streamState->messageId())->setUsage($this->streamState->getUsage());
```

A provider that already holds the message before streaming, as `FakeAIProvider` does, streams with
`$message->getId()`.

### Case 3: Tests asserting chunk IDs

Before:

```php
$this->assertSame('msg_01XFDUDYJgAACzvnptvVoYEL', $chunk->messageId);
$this->assertStringStartsWith('fake_msg_', $chunk->messageId);
```

After:

```php
$message = $stream->getReturn()->message();
$this->assertSame($message->getId(), $chunk->messageId);
```

### Case 4: Code relying on Ollama calls without an ID

`ToolCall::getCallId()` is no longer `null` for Ollama. Code that matched Ollama calls by tool name can use
the call ID, as with every other provider.

## What to Search For

```
grep -rn "messageId(" --include="*.php" .
grep -rn "\->messageId" --include="*.php" .
grep -rn "fake_msg_" --include="*.php" .
grep -rn "function stream(" --include="*.php" .
```

## Checklist

- Every custom provider streams with one ID per stream and sets it on the message it returns, on every exit.
- No call passes an argument to `BasicStreamState::messageId()`.
- No code or test expects a vendor ID or a `fake_msg_…` ID on a chunk.
