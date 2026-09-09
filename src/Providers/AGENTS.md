# Providers Module

Adapters between Neuron's messaging layer and each AI vendor's API. Every provider implements `AIProviderInterface` (`chat()`, `stream()`, `structured()`, `setTools()`, `systemPrompt()`): it takes Neuron `Message`s and returns a `ProviderResponse` or a `Generator` of stream chunks, so the Agent never sees a vendor payload.

## Anatomy of a provider

Each vendor directory holds cooperating pieces with one responsibility each:

- the provider class owns the HTTP conversation: `HasHttpClient` for the injectable client, `SSEParser` plus a `BasicStreamState` subclass for streaming, `HandleWithTools` for the tool registry. `newToolCall()` validates the tool name the model asked for against that registry before a `ToolCall` is created;
- a `MessageMapper` (`MessageMapperInterface`) translates Neuron messages, content blocks and tool call/result messages into the vendor format;
- a `ToolMapper` (`ToolMapperInterface`) translates `Tool` definitions into the vendor's tool schema, when the API supports tools.

Keep the split: mapping is pure data translation, tested in isolation from HTTP. OpenAI-compatible vendors (Deepseek, ZAI, Cohere, Grok, ...) extend `OpenAI` and its mappers instead of duplicating the protocol; `OpenAILike` / `OpenAILikeResponses` are the generic "any OpenAI-compatible endpoint" variants for the Chat Completions and Responses APIs, configured with a base URI.

## Multimodal and failed tool results

A tool result is `string|ToolOutput`. Mappers detect multimodality on the **value** (`$tool->getResult() instanceof ToolOutput`), never on the tool type, and map the blocks natively where the API accepts them (Anthropic, Bedrock, Gemini, OpenAI Chat and Responses, Mistral) by reusing the mapper's existing block mapping; block types an API does not support fall out through the same null-filtering, and text-only APIs (Ollama) fall back to `ToolOutput::getText()`. An error output (`ToolOutput::error()`) sets the vendor's native error flag where one exists (`is_error` on Anthropic, `status: "error"` on Bedrock); elsewhere the feedback text itself carries the semantics.

## Wiring into an agent

```php
class MyAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(key: env('ANTHROPIC_API_KEY'), model: 'claude-sonnet-4-6');
    }
}
```

The default HTTP client is `CurlHttpClient`; inject `GuzzleHttpClient` through `setHttpClient()` when Guzzle middleware is needed (see `src/HttpClient/AGENTS.md`).
