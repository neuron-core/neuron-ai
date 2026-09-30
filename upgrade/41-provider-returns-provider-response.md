# Upgrade: AI providers return ProviderResponse instead of Message

## Summary

Every `AIProviderInterface` implementation returns a `NeuronAI\Providers\ProviderResponse` where 3.x returned a `Message`. That covers every built-in provider (text, audio and image) and `NeuronAI\Testing\FakeAIProvider`. `ProviderResponse::message()` returns the same `Message` that 3.x returned (`AssistantMessage` or `ToolCallMessage`). Content, usage (`getUsage()`), stop reason and tool calls are still read from that message.

| 3.x | 4.x |
|---|---|
| `$provider->chat(...)` and `$provider->structured(...)` return a `Message` | They return a `ProviderResponse`. `->message()` is the `Message` |
| The final value of `$provider->stream(...)` (`$generator->getReturn()`, or the value of `yield from`) is a `Message` | It is a `ProviderResponse` |
| A custom provider declares `chat(...): Message` and `structured(...): Message`, and its `stream()` generator returns a `Message` | They declare `: ProviderResponse`, and every method, `stream()` included, returns `new ProviderResponse(message: $message)` |
| Provider streams yield `TextChunk` and `ReasoningChunk` (media providers yield `AudioChunk`/`ImageChunk`) | Every text provider except `Gemini`, `GeminiVertex` and `Ollama` also yields `NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk` while the model writes a tool call. It has `$toolName`, `$delta` and `$toolCallId`, and no `$content`. `OpenAIResponses` and `OpenAILikeResponses` also yield `ImageChunk` for partial images, whose `$content` is base64 image data |

These need no change:
- `BedrockRuntime::chatAsync()`: its promise still resolves to a `Message`. Do not add `->message()` to it.
- `new FakeAIProvider(...)` and `addResponses(...)` still take `Message` objects. Only the values the fake returns change (Cases 1 and 2).
- `ProviderResponse::body()` and `headers()` are new and need no migration. They carry the raw HTTP response only for non-streamed `chat()`/`structured()` of HTTP-based text providers, and are `null`/`[]` for streams, `BedrockRuntime` and the audio and image providers. Built-in providers leave `metadata()` empty, so read usage from `->message()->getUsage()`.

Stored data: nothing the application stored with 3.x is affected. `ProviderResponse` only wraps the value a call returns, and chat histories keep storing the `Message`.

Other guides own these, so do not migrate them here:
- Calls on an Agent or RAG instance (`$agent->chat()`, `->stream()`, `->structured()`): guide 23, already applied.
- `ChatNode` subclasses (guide 26) and `Summarization::generateSummary()` overrides (guide 27) already end their provider calls with `->message()`. Leave them as they are.
- Listeners or observers that read the inference-stop event's `$response`: guide 46.
- Other parts of a custom provider's contract: `systemPrompt()` is guide 24 (already applied), `getModel()` and the mapper accessors are guide 42, tool-call construction in provider subclasses is guide 43, and the message IDs of streamed chunks are guide 38.
- The chunk sequence `FakeAIProvider::stream()` yields, and the fake's other changes: guide 56.

## What to Search For

Run from the application root:

```bash
# 1. Call sites
grep -rnE -e '->(chat|stream|structured)\(' --include='*.php' --exclude-dir=vendor .
# 2. Final values of streams
grep -rnE 'getReturn\(\)|yield from' --include='*.php' --exclude-dir=vendor .
# 3. Classes that implement or extend a provider
grep -rnE 'function (chat|structured|stream|streamChunks|processStream) *\(|Generator<.*(Message|AssistantMessage|ToolCallMessage)>' --include='*.php' --exclude-dir=vendor .
# 4. Test doubles
grep -rnE "(method|shouldReceive|allows|expects)\(['\"](chat|structured|stream)['\"]" --include='*.php' --exclude-dir=vendor .
# 5. Files that use providers (helps you tell which receivers are providers)
grep -rnE 'AIProviderInterface|FakeAIProvider|use NeuronAI\\Providers\\' --include='*.php' --exclude-dir=vendor .
```

For searches 1 and 2, keep a call only when its receiver is a provider, wherever the call is written. That includes Agent subclasses, workflow nodes, RAG pre-processors, jobs, controllers and tests. A receiver is a provider when it is:
- a variable, property, parameter or return value typed `AIProviderInterface`;
- an instance of a class under `NeuronAI\Providers` (`Anthropic`, `OpenAI`, `OpenAIResponses`, `Gemini`, `Mistral`, `Ollama`, `Deepseek`, `BedrockRuntime`, the audio and image providers, ...), an application subclass of one, or an application class that implements `AIProviderInterface`;
- a `FakeAIProvider`;
- `$agent->getProvider()`, `$this->getProvider()` or `$this->provider()` in an Agent or RAG subclass, or `$resources->provider` in agent nodes and middleware;
- a fluent chain that starts at one of these. `systemPrompt()`, `setTools()` and `setHttpClient()` return the provider.

Skip calls whose receiver is an Agent or RAG instance, generators returned by an Agent, HTTP clients, framework helpers such as `response()->stream(...)`, and calls that already end in `->message()`. A chain can continue on the lines below the hit, so read each hit up to the end of its statement.

For search 3, keep classes that implement `AIProviderInterface`, including abstract bases and anonymous classes, and classes that extend a built-in provider or `FakeAIProvider`. Also keep `@return Generator<..., Message>` docblocks on those classes' stream methods. Their `parent::chat()`, `parent::structured()` and `yield from parent::stream()` calls are handled in Case 3.

For every `stream()` call you keep, follow the generator to the loop that iterates it (Case 2). The generator may be returned to a controller or passed to a response callback first.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: Using the result of `chat()` or `structured()`

Before (3.x):

```php
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;

class TicketAssistant
{
    public function __construct(protected AIProviderInterface $provider)
    {
    }

    public function summarize(string $ticket): ?string
    {
        return $this->provider
            ->systemPrompt('Summarize the ticket in one sentence.')
            ->chat(new UserMessage($ticket))
            ->getContent();
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function extract(string $ticket, array $schema): ?string
    {
        $message = $this->provider->structured([new UserMessage($ticket)], TicketData::class, $schema);

        return $message->getContent();
    }

    /**
     * @param Message[] $conversation
     * @return Message[]
     */
    public function reply(array $conversation): array
    {
        $reply = $this->provider->chat(...$conversation);
        $conversation[] = $reply;

        if ($reply instanceof ToolCallMessage) {
            // run the requested tools ...
        }

        return $conversation;
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;

class TicketAssistant
{
    public function __construct(protected AIProviderInterface $provider)
    {
    }

    public function summarize(string $ticket): ?string
    {
        return $this->provider
            ->systemPrompt('Summarize the ticket in one sentence.')
            ->chat(new UserMessage($ticket))
            ->message()
            ->getContent();
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function extract(string $ticket, array $schema): ?string
    {
        $message = $this->provider->structured([new UserMessage($ticket)], TicketData::class, $schema)->message();

        return $message->getContent();
    }

    /**
     * @param Message[] $conversation
     * @return Message[]
     */
    public function reply(array $conversation): array
    {
        $reply = $this->provider->chat(...$conversation)->message();
        $conversation[] = $reply;

        if ($reply instanceof ToolCallMessage) {
            // run the requested tools ...
        }

        return $conversation;
    }
}
```

1. Add `->message()` directly after the call. The variable then keeps holding a `Message`, and later lines stay unchanged.
2. This applies wherever the result is used as a `Message`: calling a `Message` method (`getContent()`, `getUsage()`, `getRole()`, `stopReason()`, `getToolCalls()`, ...), passing it to a `Message`-typed parameter, adding it to a history or a message list, an `instanceof` check, or returning it from a method declared `: Message`. Test assertions on the result count too, for example `$fake->chat($m)->getContent()` becomes `$fake->chat($m)->message()->getContent()`.
3. A `/** @var Message $x */` annotation stays correct once `->message()` is at the call. Retype it to `ProviderResponse` only if the code keeps the wrapper.

### Case 2: Consuming `stream()`

Before (3.x), in the same `TicketAssistant`:

```php
use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\UserMessage;

    public function answer(string $question): ?string
    {
        $generator = $this->provider->stream(new UserMessage($question));

        foreach ($generator as $chunk) {
            echo $chunk->content;
        }

        return $generator->getReturn()->getContent();
    }

    /**
     * @return Generator<int, StreamChunk, mixed, Message>
     */
    public function relay(Message ...$messages): Generator
    {
        $message = yield from $this->provider->stream(...$messages);

        // ... work with $message ...

        return $message;
    }
```

After (4.x):

```php
use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;

    public function answer(string $question): ?string
    {
        $generator = $this->provider->stream(new UserMessage($question));

        foreach ($generator as $chunk) {
            if ($chunk instanceof TextChunk) {
                echo $chunk->content;
            }
        }

        return $generator->getReturn()->message()->getContent();
    }

    /**
     * @return Generator<int, StreamChunk, mixed, Message>
     */
    public function relay(Message ...$messages): Generator
    {
        $message = (yield from $this->provider->stream(...$messages))->message();

        // ... work with $message ...

        return $message;
    }
```

1. `$generator->getReturn()` becomes `$generator->getReturn()->message()`.
2. `$x = yield from $provider->stream(...)` becomes `$x = (yield from $provider->stream(...))->message()`. The parentheses are required. The same applies to `return yield from ...` in a generator that must return a `Message`. A custom provider's own `stream()` returns the `ProviderResponse` unchanged (Case 3).
3. Chunk loops: a loop that reads `$chunk->content` from every chunk now also receives `ToolArgumentChunk`, which has no `$content`. PHP raises an "Undefined property" warning, which most frameworks turn into an exception. `OpenAIResponses` streams can also deliver base64 image data in an `ImageChunk`. Select chunks by class, as shown.
4. In 3.x such a loop also printed `ReasoningChunk` text when the model streamed its reasoning (reasoning models, or thinking enabled in the provider parameters). If the provider can stream reasoning, ask the developer whether the reasoning text should still appear. If it should, use `$chunk instanceof TextChunk || $chunk instanceof ReasoningChunk` (`NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk`).
5. A loop that already selects chunks with `instanceof TextChunk`/`ReasoningChunk` needs no change. A loop that only excludes some classes, or a `match (true)` over chunk classes whose `default` throws, must also let `ToolArgumentChunk` pass without error.

### Case 3: A class that implements or extends a provider

This covers classes that implement `AIProviderInterface`, including abstract bases and anonymous classes, and classes that extend a built-in provider or `FakeAIProvider` and override `chat()`, `structured()` or `stream()`. A 3.x `: Message` return type fails when the class loads. A `stream()` that still returns a `Message` fails at runtime when the Agent reads the stream's final value.

Before (3.x):

```php
use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Providers\AIProviderInterface;

class AcmeProvider implements AIProviderInterface
{
    // systemPrompt(), setTools(), setHttpClient(), ... are not part of this guide

    public function chat(Message ...$messages): Message
    {
        $data = $this->request($messages); // the application's own HTTP call

        return new AssistantMessage($data['text']);
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        $data = $this->request(is_array($messages) ? $messages : [$messages], $response_schema);

        return new AssistantMessage($data['text']);
    }

    /**
     * @return Generator<int, StreamChunk, mixed, Message>
     */
    public function stream(Message ...$messages): Generator
    {
        $text = '';

        foreach ($this->requestStream($messages) as $event) {
            $text .= $event['delta'];
            yield new TextChunk($event['id'], $event['delta']);
        }

        return new AssistantMessage($text);
    }
}
```

After (4.x):

```php
use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;

class AcmeProvider implements AIProviderInterface
{
    // systemPrompt(), setTools(), setHttpClient(), ... are not part of this guide

    public function chat(Message ...$messages): ProviderResponse
    {
        $data = $this->request($messages); // the application's own HTTP call

        return new ProviderResponse(message: new AssistantMessage($data['text']));
    }

    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
    {
        $data = $this->request(is_array($messages) ? $messages : [$messages], $response_schema);

        return new ProviderResponse(message: new AssistantMessage($data['text']));
    }

    /**
     * @return Generator<int, StreamChunk, mixed, ProviderResponse>
     */
    public function stream(Message ...$messages): Generator
    {
        $text = '';

        foreach ($this->requestStream($messages) as $event) {
            $text .= $event['delta'];
            yield new TextChunk($event['id'], $event['delta']);
        }

        return new ProviderResponse(message: new AssistantMessage($text));
    }
}
```

An override that calls the parent receives a `ProviderResponse`.

Before (3.x):

```php
use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Providers\OpenAI\OpenAI;

class MeteredOpenAI extends OpenAI
{
    /** @var array<Usage|null> */
    protected array $usages = [];

    public function chat(Message ...$messages): Message
    {
        $message = parent::chat(...$messages);
        $this->usages[] = $message->getUsage();

        return $message;
    }

    public function stream(Message ...$messages): Generator
    {
        $message = yield from parent::stream(...$messages);
        $this->usages[] = $message->getUsage();

        return $message;
    }
}
```

After (4.x):

```php
use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\ProviderResponse;

class MeteredOpenAI extends OpenAI
{
    /** @var array<Usage|null> */
    protected array $usages = [];

    public function chat(Message ...$messages): ProviderResponse
    {
        $response = parent::chat(...$messages);
        $this->usages[] = $response->message()->getUsage();

        return $response;
    }

    public function stream(Message ...$messages): Generator
    {
        $response = yield from parent::stream(...$messages);
        $this->usages[] = $response->message()->getUsage();

        return $response;
    }
}
```

1. Change the return type of `chat()` and `structured()` from `Message` (or `AssistantMessage`) to `ProviderResponse`, and add `use NeuronAI\Providers\ProviderResponse;`.
2. Wrap every returned message as `return new ProviderResponse(message: $message);`. The constructor also accepts `body:` (`?string`) and `headers:` (`array`) for the raw HTTP response. They are optional, and nothing in Neuron requires them.
3. In `stream()`, keep the yields and replace the final `return $message;` with `return new ProviderResponse(message: $message);`. Change `@return Generator<..., Message>` to `@return Generator<int, StreamChunk, mixed, ProviderResponse>`.
4. An override that calls `parent::chat()`, `parent::structured()` or `yield from parent::stream()` reads the message with `->message()` and returns the parent's `ProviderResponse`. If the override replaces the message, it returns `new ProviderResponse(message: $changed)`.
5. The same change applies to an override of `processStream()` in a subclass of `OpenAI`, of a provider built on it, or of `Cohere`, because that method returns the stream's final value. It also applies to an override of `streamChunks(Message $response): Generator` in a `FakeAIProvider` subclass, which ends with `return new ProviderResponse(message: $response);`.
6. A class that implements `AIProviderInterface` directly still fails to load until guide 42 adds `getModel()`. That is expected at this step.

### Case 4: Test doubles of a provider

PHPUnit refuses a `Message` as the return value of a mocked `chat()` ("may not return value of type ... AssistantMessage").

Before (3.x):

```php
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Providers\AIProviderInterface;
use PHPUnit\Framework\TestCase;

class TicketAssistantTest extends TestCase
{
    public function test_summarize(): void
    {
        $provider = $this->createMock(AIProviderInterface::class);
        $provider->method('systemPrompt')->willReturnSelf();
        $provider->method('chat')->willReturn(new AssistantMessage('Printer is broken.'));

        $this->assertSame('Printer is broken.', (new TicketAssistant($provider))->summarize('...'));
    }

    public function test_answer(): void
    {
        $provider = $this->createMock(AIProviderInterface::class);
        $provider->method('stream')->willReturnCallback(function () {
            yield new TextChunk('msg_1', 'Hi');

            return new AssistantMessage('Hi');
        });

        $this->assertSame('Hi', (new TicketAssistant($provider))->answer('Hello?'));
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;
use PHPUnit\Framework\TestCase;

class TicketAssistantTest extends TestCase
{
    public function test_summarize(): void
    {
        $provider = $this->createMock(AIProviderInterface::class);
        $provider->method('systemPrompt')->willReturnSelf();
        $provider->method('chat')->willReturn(new ProviderResponse(message: new AssistantMessage('Printer is broken.')));

        $this->assertSame('Printer is broken.', (new TicketAssistant($provider))->summarize('...'));
    }

    public function test_answer(): void
    {
        $provider = $this->createMock(AIProviderInterface::class);
        $provider->method('stream')->willReturnCallback(function () {
            yield new TextChunk('msg_1', 'Hi');

            return new ProviderResponse(message: new AssistantMessage('Hi'));
        });

        $this->assertSame('Hi', (new TicketAssistant($provider))->answer('Hello?'));
    }
}
```

- Apply the same change to `structured()` stubs, to Mockery (`shouldReceive('chat')->andReturn(new ProviderResponse(message: $message))`), to Prophecy and to hand-written stub classes (Case 3).
- Tests that use `FakeAIProvider` keep queuing `Message` objects. Only the code that reads the fake's results changes (Cases 1 and 2).

## Checklist

- No provider result from `chat()`, `structured()`, `getReturn()` or `yield from` is used as a `Message` without `->message()`.
- Calls on Agent or RAG instances and `BedrockRuntime::chatAsync()` results were left unchanged.
- Loops over provider streams read `$content` only from chunk classes they select (`TextChunk`, plus `ReasoningChunk` if the developer wants reasoning shown), and `ToolArgumentChunk` passes through without error.
- No class that implements or extends a provider still declares `: Message` on `chat()`/`structured()`, returns a `Message` from `stream()`, `processStream()` or `streamChunks()`, or keeps a `Generator<..., Message>` docblock on them.
- Provider mocks and stubs return `ProviderResponse`.
- `use NeuronAI\Providers\ProviderResponse;` is present wherever the type is named (return types, `new ProviderResponse`, type hints, docblocks). Code that only chains `->message()` has no new import.
- Re-run the searches, then PHPStan or the test suite. Any error left about `ProviderResponse` or a provider method belongs to this guide. The missing `getModel()` on direct implementers belongs to guide 42.
