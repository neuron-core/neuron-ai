# Upgrade: A streamed answer needs the vendor's closing event

## Summary

In 3.x a provider read a stream until its body ended and returned whatever had arrived. When a proxy, a load balancer
or the vendor's edge cut the connection mid-answer, and the client reported the cut as a normal end (Guzzle does), half
an answer came back as a complete one: an Agent saved it to the chat history, memoized it and replayed it on recovery.
The OpenAI Responses provider even returned the partial message on purpose.

- **Every stream must end with the vendor's closing event**: Anthropic's `message_delta` stop reason, the Chat
  Completions `finish_reason` (every OpenAI-compatible provider), `response.completed` or `response.incomplete`
  (Responses), Gemini's `finishReason`, Cohere's `message-end`, Ollama's `done` line, Bedrock's `messageStop`. A stream
  that ends without it throws `ProviderException` ("The stream ended before the answer was complete."), so an Agent run
  fails and can be recovered or retried instead of keeping half an answer.
- **Stopping a stream on purpose has one supported way**: `NeuronAI\HttpClient\StoppableHttpClient`. The answer keeps
  the text streamed so far, with the stop reason `StoppableHttpClient::STOP_REASON` (`'stopped'`); the reasoning and
  tool calls it left incomplete are dropped, and a stop before the first word throws `ProviderException`.

## How to Refactor

### Case 1: A stream decorator that stops generation

A decorator that made `eof()` return `true` early, so the provider saved what had arrived, now makes the provider throw.
Replace it with `StoppableHttpClient`, which takes the client and the stop condition.

Before:

```php
class StoppableStream implements StreamInterface
{
    public function eof(): bool
    {
        if (Cache::get("chat_stop_{$this->chatId}")) {
            $this->inner->close();
            return true;
        }
        return $this->inner->eof();
    }
    // read(), readLine(), close() delegate to $this->inner
}

httpClient: new StoppableHttpClient($chatId), // the application's own decorator
```

After:

```php
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\StoppableHttpClient;

httpClient: new StoppableHttpClient(new CurlHttpClient(), fn (): bool => Cache::pull("chat_stop_{$chatId}", false)),
```

Delete the application's stream and client decorators. Code that shows a stopped answer differently can read
`$message->stopReason() === StoppableHttpClient::STOP_REASON`.

### Case 2: Tests that fake a provider stream

A fake HTTP body that ends without the closing event is now a cut stream. Add the event the real API sends last.

Before:

```php
$body = 'data: {"choices":[{"delta":{"content":"Hello"}}]}' . "\n\n";
```

After:

```php
$body = 'data: {"choices":[{"delta":{"content":"Hello"},"finish_reason":"stop"}]}' . "\n\n";
```

## What to Search For

```
grep -rn "implements StreamInterface" --include="*.php" .
grep -rnE "HttpClientInterface|StreamInterface" --include="*.php" .
grep -rnE "message_delta|finish_reason|finishReason|response\.completed|message-end|\"done\"" --include="*.php" tests
```

## Checklist

- No application class ends a provider stream early by returning `true` from `eof()`; stopping goes through
  `StoppableHttpClient`.
- Every faked provider stream in the tests ends with its vendor's closing event.
