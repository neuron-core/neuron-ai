# Providers Module

Adapters between Neuron's messaging layer and each AI vendor's API. Every provider implements `AIProviderInterface` (`chat()`, `stream()`, `structured()`, `setTools()`, `systemPrompt()`): it takes Neuron `Message`s and returns a `ProviderResponse` or a `Generator` of stream chunks, so the Agent never sees a vendor payload.

## Anatomy of a provider

Each vendor directory holds cooperating pieces with one responsibility each:

- the provider class owns the HTTP conversation: `HasHttpClient` for the injectable client, `SSEParser` plus a `BasicStreamState` subclass for streaming, `HandleWithTools` for the tool registry. `newToolCall()` validates the tool name the model asked for against that registry before a `ToolCall` is created and records its deferred execution flag from `DeferredToolInterface`;
- a `MessageMapper` (`MessageMapperInterface`) translates Neuron messages, content blocks and tool call/result messages into the vendor format;
- a `ToolMapper` (`ToolMapperInterface`) translates `Tool` definitions into the vendor's tool schema, when the API supports tools. It consumes `ToolInterface::getInputSchema()` rather than rebuilding the schema from properties; provider-specific adaptations stay in the mapper.

Keep the split: mapping is pure data translation, tested in isolation from HTTP. OpenAI-compatible vendors (Deepseek, ZAI, Cohere, Grok, ...) extend `OpenAI` and its mappers instead of duplicating the protocol; `OpenAILike` / `OpenAILikeResponses` are the generic "any OpenAI-compatible endpoint" variants for the Chat Completions and Responses APIs, configured with a base URI.

## Streamed message identity

Every chunk of a streamed response carries the ID of the message the stream returns. The stream state generates one Neuron ID per stream (`BasicStreamState::messageId()`), every chunk carries it, and the message built at the end takes it with `Message::setId()`; audio and image streams do the same with the ID they generate. A client that rendered the stream finds that ID in storage, so a reload rebuilds the IDs it already shows. Never use the vendor's response or item ID: message IDs deduplicate history writes, and a repeated or empty vendor ID would silently drop messages. `ProviderStreamContractTest` checks every stream implementation, including both exits of the chat providers (tool call and plain answer).

Call IDs are per-call identity too: approval decisions, durable memos, frontend tool results and stream protocols are keyed on them. An API that omits them gets a locally-unique ID from the provider: Gemini when a call has none, Ollama always. The mappers never send those IDs back to the API.

## Multimodal and failed tool results

A tool result is `string|ToolOutput`. Mappers detect multimodality on the **value** (`$tool->getResult() instanceof ToolOutput`), never on the tool type, and map the blocks natively where the API accepts them (Anthropic, Bedrock, Gemini, OpenAI Chat and Responses, Mistral) by reusing the mapper's existing block mapping; block types an API does not support fall out through the same null-filtering, and text-only APIs (Ollama) fall back to `ToolOutput::getText()`. An error output (`ToolOutput::error()`) sets the vendor's native error flag where one exists (`is_error` on Anthropic, `status: "error"` on Bedrock); elsewhere the feedback text itself carries the semantics.

## Wiring into an agent

```php
class MyAgent extends Agent
{
    protected function provider(\NeuronAI\Workflow\ExecutionContext $context): AIProviderInterface
    {
        return new Anthropic(key: env('ANTHROPIC_API_KEY'), model: 'claude-sonnet-4-6');
    }
}
```

The default HTTP client is `CurlHttpClient`; inject `GuzzleHttpClient` through `setHttpClient()` when Guzzle middleware is needed (see `src/HttpClient/AGENTS.md`).
