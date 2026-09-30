# Upgrade: A streamed answer needs the vendor's closing event

## Summary

In 3.x a provider's `stream()` read the response body until it ended and returned whatever had arrived as a normal
answer, and an Agent saved it to the chat history. In 4.x every built-in chat provider requires the vendor's closing
event. When the body ends without it, `stream()` throws `NeuronAI\Exceptions\ProviderException` with the message
"The stream ended before the answer was complete.", and the Agent run fails. An `error` event that the vendor sends
inside the stream also throws `ProviderException`.

| 3.x | 4.x |
|---|---|
| A stream that ended early returned the text received so far, with stop reason `null` | `ProviderException('The stream ended before the answer was complete.')` |
| An application stream decorator whose `eof()` returned `true` stopped generation and kept the partial answer | `NeuronAI\HttpClient\StoppableHttpClient` is the only supported way to stop a stream on purpose. The answer keeps its text, with stop reason `StoppableHttpClient::STOP_REASON` (`'stopped'`) |
| A stop before any text returned an empty answer | `ProviderException('The stream was stopped before the answer started.')` |
| Gemini streamed one JSON array from `…:streamGenerateContent` | Gemini streams SSE `data:` lines from `…:streamGenerateContent?alt=sse` |

A stream is complete only when it contains:

| Provider (and its subclasses) | Closing event |
|---|---|
| `Anthropic`, `AnthropicVertex` | a `message_delta` event with `delta.stop_reason` |
| `OpenAI`, `AzureOpenAI`, `OpenAILike`, `Deepseek`, `Grok`, `HuggingFace`, `ZAI`, `DashScopeOpenAI`, `Mistral` | a chunk whose `choices[0].finish_reason` is not null (`"finish_reason": null` does not count) |
| `OpenAIResponses`, `OpenAILikeResponses` | a `response.completed` or `response.incomplete` event. The final answer is read from its `response.output` |
| `Gemini`, `GeminiVertex` | a candidate with `finishReason` |
| `Cohere` | a `message-end` event with `delta.finish_reason` |
| `Ollama` | a line with `"done": true` that also carries `"message": {"role": "assistant", ...}` |
| `BedrockRuntime` | a `messageStop` event with `stopReason` |

Image, audio and speech providers keep their 3.x end-of-stream behaviour.

Stored data is not affected. Partial answers that 3.x saved stay in the chat history as ordinary messages.

## What to Search For

Run from the application root:

```bash
# 1. Application stream and client decorators that can end a stream early
grep -rnE 'function eof\(|implements .*(StreamInterface|HttpClientInterface)|extends .*(GuzzleStream|AmpStream|GuzzleHttpClient|AmpHttpClient)\b' --include='*.php' --exclude-dir=vendor .

# 2. Faked provider streams in the tests, in every file type (recorded .txt/.json/.sse fixtures too)
grep -rnE 'data: ?\{|\b(choices|candidates)\b.{0,4}(:|=>)|content_block_delta|content-delta|output_text\.delta|contentBlockDelta|\bdone\b.{0,4}(:|=>) ?(true|false)|streamGenerateContent|messageStop|MockHandler' --exclude-dir=vendor tests
```

How to follow the hits:

- **Search 1:** keep the stream classes whose `eof()` can return `true` before the wrapped stream ends, for example on
  a stop flag, a time limit or a length limit. Also keep the client class that returns them from `stream()`, and find
  every place that builds that client (grep its class name). These are Case 1. A class whose `eof()` only delegates to
  the wrapped stream needs no change. Guide 2 has already covered everything else about these classes.
- **Search 2:** keep the bodies and fixtures that a test serves to a streaming call: a streamed Agent run,
  `$provider->stream()`, the `stream()` method of a fake client, or a Guzzle `MockHandler` response used by one of
  them. Check that each one ends with its closing event (Case 2). A test that expects a partial answer from a cut stream
  is Case 3. If the application keeps tests or fixtures outside `tests/`, run the search there too.
- Tests that use `FakeAIProvider` fake no HTTP stream and are not affected (guide 56 covers them).

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: A decorator that stops a stream on purpose

With 4.x, a stream that the application's `eof()` ended early makes the provider throw. Replace the decorator with
`StoppableHttpClient`. Its constructor takes the client to wrap and a closure that returns `true` when the stream must
stop.

Before (3.x, as guide 2 left it):

```php
use Illuminate\Support\Facades\Cache;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\Providers\Anthropic\Anthropic;

class StopAwareHttpClient implements HttpClientInterface
{
    public function __construct(protected HttpClientInterface $client, protected string $chatId)
    {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        return $this->client->request($request);
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        return new StopAwareStream($this->client->stream($request), $this->chatId);
    }
}

class StopAwareStream implements StreamInterface
{
    public function __construct(protected StreamInterface $inner, protected string $chatId)
    {
    }

    public function eof(): bool
    {
        if (Cache::get("chat_stop_{$this->chatId}")) {
            $this->inner->close();
            return true;
        }

        return $this->inner->eof();
    }

    public function read(int $length): string
    {
        return $this->inner->read($length);
    }

    public function readLine(): string
    {
        return $this->inner->readLine();
    }

    public function close(): void
    {
        $this->inner->close();
    }
}

$provider = new Anthropic(
    key: $key,
    model: 'claude-sonnet-4-5',
    httpClient: new StopAwareHttpClient(new GuzzleHttpClient(), $chatId),
);
```

After (4.x):

```php
use Illuminate\Support\Facades\Cache;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Providers\Anthropic\Anthropic;

$provider = new Anthropic(
    key: $key,
    model: 'claude-sonnet-4-5',
    httpClient: new StoppableHttpClient(
        new GuzzleHttpClient(),
        fn (): bool => (bool) Cache::get("chat_stop_{$chatId}"),
    ),
);
```

1. Pass as the first argument the same client expression the decorator wrapped, unchanged: `new GuzzleHttpClient(...)`
   with its options, `new CurlHttpClient()`, or `$provider->getHttpClient()`. If the decorator built its inner client
   itself, move that expression here. If it extended a client class instead of wrapping one, pass a new instance of
   that parent class with the same constructor arguments.
2. Pass as the second argument a closure that returns the condition the `eof()` checked, read exactly the same way.
   `Cache::get` stays `Cache::get`: never switch to a read that consumes the flag, such as `Cache::pull`. Keep the code
   that clears the flag when the next turn starts. Cast the result to `bool`, because under `declare(strict_types=1)`
   a `fn (): bool` that returns `1` or `'1'` throws a `TypeError`. The closure runs before every streamed event, as
   often as the old `eof()`, so keep it as cheap as it was.
3. Delete the decorator classes and their imports. If the application's own class was named `StoppableHttpClient`,
   import `NeuronAI\HttpClient\StoppableHttpClient` in its place.
4. Handle a stop that comes before the first word, as shown below.
5. If code recognized a stopped answer by `stopReason() === null`, compare with `StoppableHttpClient::STOP_REASON`
   instead.

A stopped answer keeps the text streamed so far, and its `stopReason()` returns `'stopped'`. Reasoning and tool calls
that were still streaming are dropped, so a stop during a tool call ends the turn without running the tool. The Agent
saves the stopped answer to the chat history like any other answer.

`BedrockRuntime` streams through the AWS SDK, not through Neuron's HTTP client, so `StoppableHttpClient` cannot stop it.
A 3.x decorator could not stop it either. If the application offers its stop button for Bedrock agents, tell the
developer.

**A stop before the first word.** 3.x finished the turn with an empty answer. 4.x throws
`ProviderException('The stream was stopped before the answer started.')`. Wrap the code that runs a stoppable turn.
When the stop condition is set, treat the exception as a stop. Otherwise rethrow it. With a stream adapter, the client
receives the adapter's error frame before the exception reaches this `catch`. Ask the developer how the interface
should show a turn that was stopped before it answered, because 3.x showed an empty answer there.

Before (3.x, as guide 23 left it):

```php
use NeuronAI\Chat\Messages\UserMessage;

$stream = $agent->stream(new UserMessage($text));
foreach ($stream as $chunk) {
    // send the chunk to the browser
}
```

After (4.x):

```php
use Illuminate\Support\Facades\Cache;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;

try {
    $stream = $agent->stream(new UserMessage($text));
    foreach ($stream as $chunk) {
        // send the chunk to the browser
    }
} catch (ProviderException $e) {
    if (! (bool) Cache::get("chat_stop_{$chatId}")) {
        throw $e;
    }
    // Stopped before the first word: there is no answer to show.
}
```

### Case 2: Tests that fake a provider stream

A faked body or fixture that ends without its closing event is now a cut stream, and the test fails with
`ProviderException`. Add the event that the real API sends last, and keep the rest of the body.

Chat Completions (every provider in that row of the Summary table):

Before (3.x):

```php
$body = 'data: {"choices":[{"index":0,"delta":{"content":"Hello"}}]}' . "\n\n";
```

After (4.x):

```php
$body = 'data: {"choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":"stop"}]}' . "\n\n";
```

Use `"finish_reason":"tool_calls"` on a turn that ends with tool calls.

The last event each other provider needs:

- **Anthropic** (`"stop_reason":"tool_use"` on a tool-call turn):

  ```
  event: message_delta
  data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":1}}
  ```

- **OpenAI Responses:** a final `response.completed` event that carries the answer in `response.output`. Without that
  text, the answer comes back empty.

  ```
  data: {"type":"response.completed","response":{"status":"completed","output":[{"type":"message","id":"msg_1","content":[{"type":"output_text","text":"Hello"}]}]}}
  ```

- **Cohere:** `data: {"type":"message-end","delta":{"finish_reason":"COMPLETE"}}`. A `message-end` without
  `finish_reason` does not count.
- **Ollama:** a final line with `"done":true`. It must also carry the assistant `message`, like the real API. On a
  tool-call turn, add it after the line with the tool calls:

  ```
  {"model":"llama3.2","message":{"role":"assistant","content":""},"done":true,"done_reason":"stop"}
  ```

- **Bedrock** (events of a mocked `converseStream()`): `['messageStop' => ['stopReason' => 'end_turn']]`, or
  `'tool_use'` on a tool-call turn.
- **Gemini:** the last element carries `"finishReason":"STOP"`, and the body format changes as shown below.

Gemini fakes change format. 3.x read one JSON array. 4.x reads SSE, so write one `data: {...}` line per array element,
each followed by a blank line. The request now goes to `…/{model}:streamGenerateContent?alt=sse`. Update URL matchers
and assertions on the streamed request to match.

Before (3.x):

```php
$body = '[{"candidates":[{"content":{"role":"model","parts":[{"text":"Hello"}]}}]},'
    . '{"candidates":[{"content":{"role":"model","parts":[{"text":" there"}]},"finishReason":"STOP"}]}]';
```

After (4.x):

```php
$body = 'data: {"candidates":[{"content":{"role":"model","parts":[{"text":"Hello"}]}}]}' . "\n\n"
    . 'data: {"candidates":[{"content":{"role":"model","parts":[{"text":" there"}]},"finishReason":"STOP"}]}' . "\n\n";
```

### Case 3: Tests that expect a partial answer from a cut stream

A test that fed a truncated stream, or a stream with a vendor `error` event, and asserted the text received so far now
gets a `ProviderException`. If the partial answer was the point of the test, expect the exception. If it was not, add
the closing event (Case 2) and keep the assertions.

Before (3.x):

```php
use NeuronAI\Chat\Messages\UserMessage;

public function test_cut_stream_returns_the_partial_answer(): void
{
    $provider = $this->openAIWithBody('data: {"choices":[{"index":0,"delta":{"content":"Hel"}}]}' . "\n\n");

    $generator = $provider->stream(new UserMessage('Hi'));
    foreach ($generator as $chunk) {
    }

    $this->assertSame('Hel', $generator->getReturn()->getContent());
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;

public function test_cut_stream_fails(): void
{
    $provider = $this->openAIWithBody('data: {"choices":[{"index":0,"delta":{"content":"Hel"}}]}' . "\n\n");

    $this->expectException(ProviderException::class);
    $this->expectExceptionMessage('The stream ended before the answer was complete.');

    foreach ($provider->stream(new UserMessage('Hi')) as $chunk) {
    }
}
```

Guide 41 migrates what `getReturn()` returns in the tests that keep reading it.

## Report to the developer

- A self-hosted OpenAI-compatible server (or a gateway in front of any vendor) that never sends the closing event now
  fails every streamed answer with `ProviderException`. The developer should check each such endpoint against a real
  streamed request.

## Checklist

- Search 1 finds no class whose `eof()` ends a provider stream early. Every stop goes through
  `NeuronAI\HttpClient\StoppableHttpClient`, which wraps the client the decorator wrapped.
- The stop closure reads the flag the same way the decorator did (no `pull`). The code that clears the flag at the next
  turn is still in place.
- The code that runs a stoppable turn handles `ProviderException` for a stop before the first word, and rethrows it
  otherwise.
- Every faked stream body and fixture that Search 2 finds ends with its provider's closing event. Gemini fakes are SSE
  `data:` lines, and their URL matchers end in `:streamGenerateContent?alt=sse`.
- Tests that asserted a partial answer from a cut stream now expect `ProviderException`.
