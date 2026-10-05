# Providers

Every provider implements `NeuronAI\Providers\AIProviderInterface` (`chat()`, `stream()`, `structured()`), so Agent, RAG, and custom nodes never see a vendor payload. An Agent takes its provider from the protected `provider()` hook; `setAiProvider()` on an instance takes precedence over the hook. Construct providers with named arguments. The sections below group the shipped classes by what their constructor needs. Every class also accepts `parameters` and `httpClient`, except Bedrock, which is covered under platform credentials.

Pick the class in this order:

1. The vendor is listed below: use its class.
2. The vendor exposes an OpenAI-compatible API but is not listed (OpenRouter, Groq, LM Studio, vLLM, ...): use `OpenAILike` or `OpenAILikeResponses` with the vendor's base URI. Write a provider class only when the wire format is not OpenAI-compatible.
3. Speech and image generation: see the last section.
4. Embeddings providers live under `NeuronAI\RAG\Embeddings` and are covered by the **neuron-rag** skill.

## Key and model

```php
use NeuronAI\Providers\OpenAI\OpenAI;

new OpenAI(
    key: $_ENV['OPENAI_API_KEY'],
    model: $_ENV['OPENAI_MODEL'],
);
```

| Class | Beyond `key` and `model` |
|-------|--------------------------|
| `NeuronAI\Providers\Anthropic\Anthropic` | `version` (API date header, default `2023-06-01`), `max_tokens` (default 8192) |
| `NeuronAI\Providers\OpenAI\OpenAI` | Chat Completions API; `strict_response` |
| `NeuronAI\Providers\OpenAI\Responses\OpenAIResponses` | Responses API; `strict_response` |
| `NeuronAI\Providers\Gemini\Gemini` | optional `baseUri` (default Google AI `v1beta`) |
| `NeuronAI\Providers\Mistral\Mistral` | none |
| `NeuronAI\Providers\Deepseek\Deepseek` | OpenAI-compatible; `reasoning_content` becomes `ReasoningContent` |
| `NeuronAI\Providers\XAI\Grok` | OpenAI-compatible |
| `NeuronAI\Providers\ZAI\ZAI` | OpenAI-compatible; optional `baseUri` |
| `NeuronAI\Providers\Alibaba\DashScopeOpenAI` | OpenAI-compatible; optional `baseUri` |
| `NeuronAI\Providers\Cohere\Cohere` | OpenAI-compatible; optional `baseUri` (default Cohere v2) |
| `NeuronAI\Providers\HuggingFace\HuggingFace` | `inferenceProvider`: an `InferenceProvider` enum case (`HF_INFERENCE`, `GROQ`, `TOGETHER`, `CEREBRAS`, `FIREWORKS_AI`, ...), or `null` (default) to let Hugging Face pick the fastest provider serving the model |

The OpenAI-compatible classes extend `OpenAI`, so they share its constructor and `strict_response`.

## Endpoint

```php
use NeuronAI\Providers\OpenAILike;

new OpenAILike(
    baseUri: 'https://openrouter.ai/api/v1',
    key: $_ENV['OPENROUTER_API_KEY'],
    model: $_ENV['OPENROUTER_MODEL'],
);
```

| Class | Arguments |
|-------|-----------|
| `NeuronAI\Providers\Ollama\Ollama` | `url` (for example `http://localhost:11434/api`), `model`; no key |
| `NeuronAI\Providers\OpenAILike` | `baseUri`, `key`, `model`; Chat Completions wire format |
| `NeuronAI\Providers\OpenAILikeResponses` | `baseUri`, `key`, `model`; Responses API wire format |
| `NeuronAI\Providers\OpenAI\AzureOpenAI` | `key` (a resource key, sent in the `api-key` header), `endpoint` (the resource host), `model` (the deployment name); calls Azure's v1 API, so no `api-version` |

## Platform credentials

These vendors authenticate through a cloud SDK rather than an API key. Neither package is a framework dependency; require it in the application.

```php
use Aws\BedrockRuntime\BedrockRuntimeClient;
use NeuronAI\Providers\AWS\BedrockRuntime;

// aws/aws-sdk-php: region and credentials belong to the SDK client
new BedrockRuntime(
    bedrockRuntimeClient: new BedrockRuntimeClient(['region' => 'us-east-1', 'version' => 'latest']),
    model: $_ENV['BEDROCK_MODEL'],
    inferenceConfig: ['maxTokens' => 4096],
);
```

```php
use NeuronAI\Providers\Anthropic\AnthropicVertex;

// google/auth: a service-account JSON file
new AnthropicVertex(
    pathJsonCredentials: '/secrets/vertex.json',
    location: 'us-east5',
    projectId: $_ENV['GOOGLE_PROJECT_ID'],
    model: $_ENV['ANTHROPIC_MODEL'],
);
```

`NeuronAI\Providers\Gemini\GeminiVertex` takes the same arguments. `location: null` selects the global endpoint. Bedrock speaks the Converse API: tuning options go in `inferenceConfig` under Converse's names (`maxTokens`, `temperature`, ...), and `setHttpClient()` is a no-op because the AWS SDK owns the transport.

## Vendor request parameters

`parameters` is spread into the top-level JSON body next to the framework's own fields, so it accepts any field the vendor's endpoint accepts. Nest options where the vendor does: Gemini under `generationConfig`, Ollama under `options`.

```php
// Anthropic: adaptive thinking; thinking blocks map to ReasoningContent
new Anthropic(key: $key, model: $model, parameters: ['thinking' => ['type' => 'adaptive']]);

// OpenAI Responses API: reasoning effort, with summaries mapped to ReasoningContent
new OpenAIResponses(key: $key, model: $model, parameters: ['reasoning' => ['effort' => 'high', 'summary' => 'auto']]);

// Ollama: sampling and context options live under `options`
new Ollama(url: $url, model: $model, parameters: ['options' => ['temperature' => 0.2, 'num_ctx' => 16384]]);
```

`strict_response: true` on the OpenAI family turns on the vendor's strict JSON schema mode for `structured()`; the framework rewrites the schema to satisfy the strict-mode rules. Prompt caching follows the `cache()` marker described in the skill, on instruction blocks and on the blocks of conversation messages:

- `Anthropic` and `AnthropicVertex` turn each marked block into a `cache_control` breakpoint.
- `OpenAIResponses` turns a marked instruction block or user text into a `prompt_cache_breakpoint`. OpenAI documents it for GPT-5.6 and later, so mark blocks only for those models; a marker on an image or a file is ignored.
- `BedrockRuntime` adds a `cachePoint` after a marked block of the conversation. Mark blocks only for a model with prompt caching (Claude, Nova). A marker on the instructions is ignored.
- The other providers ignore the marker.

Anthropic and Bedrock allow four breakpoints in a request. Markers are stored with their messages and add up over a long thread, so beyond four the provider keeps the ones on the instructions and tools, then the most recent of the conversation.

A marker is a fixed point. Marking the last block of each user message reuses the conversation from one turn to the next (see Turn Context in the skill). The growing end of a request, the steps of a tool loop, is cached without markers: OpenAI, Gemini and Deepseek do it on their own, `Anthropic` and `AnthropicVertex` only when the request carries a top-level `cache_control`. Anthropic then places a breakpoint on the last block of the request and moves it forward on every request.

```php
// Anthropic: also cache the steps of a tool loop, next to the marked blocks
new Anthropic(key: $key, model: $model, parameters: ['cache_control' => ['type' => 'ephemeral']]);
```

A cache write costs 1.25 times the input price and a read 0.1 times, so this breakpoint pays off when the next request follows within five minutes and reuses what was written, as the steps of a tool loop do. On a turn answered in one request it writes the turn context and reads nothing back: an agent without tools, RAG included, should rely on the marked user message alone. This breakpoint takes one of the four slots, leaving three for marked blocks. Add `'ttl' => '1h'` only when no block is marked: `cache()` writes five-minute breakpoints, and Anthropic requires the longer TTL to come first.

Tool definitions are part of the start of a request, where a provider's cache begins, so a request reuses only what was cached with the same tool list. With a tool added, removed, reordered or reworded, it reads nothing from the cache and is written to it again, as Anthropic and OpenAI both document. Keep the tool list the same on every request of a thread. `ToolSearchMiddleware` changes it by design: the tools a search finds join the list for the rest of the turn, and the next user message starts without them. The request after a search that found new tools is a full cache write. The next turn goes back to the shorter list and reuses only what earlier requests wrote with that same list, typically the conversation up to the previous question.

With every tool listed up front, the definitions are written once and read on each request after that. So with caching on, tool search costs less for a pool of hundreds of tools, and a few dozen usually cost less listed in full. Do not give a tool of the pool a `cache_control` of its own through `setParameters()`: tools keep their breakpoints before the conversation does, so the tools a search finds would take the slots of the marked messages.

## HTTP client

Every HTTP-based provider builds a `CurlHttpClient` unless one is passed as `httpClient` or installed later with `setHttpClient()`. Use the client for proxies and CA bundles (`curlOptions`), for request and response taps (`onRequest()` / `onResponse()`), or to swap the transport:

```php
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;

// guzzlehttp/guzzle: HandlerStack middleware for retries, logging, recording
$provider->setHttpClient(new GuzzleHttpClient(handler: $handlerStack));
```

`NeuronAI\HttpClient\Amp\AmpHttpClient` (amphp/http-client) is the async adapter. Streaming stays incremental with every client.

## Stopping a streamed answer

A "stop generating" button stops the provider's stream from the application. Wrap the client in `StoppableHttpClient` with a predicate the provider asks before every event it reads; the "stop" endpoint only raises a flag the predicate reads, in any store both processes share:

```php
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\StoppableHttpClient;

protected function provider(): AIProviderInterface
{
    return new Anthropic(
        key: $this->apiKey,
        model: 'claude-sonnet-4-6',
        // Laravel's cache here; any shared store works (Symfony Cache, Redis, a database row)
        httpClient: new StoppableHttpClient(new CurlHttpClient(), fn (): bool => Cache::get("stop:{$this->getThreadId()}", false)),
    );
}
```

Keep the predicate cheap: it runs once per streamed event. Read the flag without consuming it, and clear it when the next turn starts: a consumed flag lets a retry of a queued turn generate the answer the user stopped. Stopping closes the connection, so the vendor stops generating, and the turn completes normally: the answer keeps the text streamed so far, its stop reason is `StoppableHttpClient::STOP_REASON` (`'stopped'`), and the reasoning and tool calls it left incomplete are dropped. A stop before the first word fails the turn with a `ProviderException`, since there is no answer to keep. Bedrock streams through the AWS SDK and cannot be stopped this way.

Any other early end is a cut connection (a proxy, a load balancer, the vendor's edge): every stream must end with the vendor's closing event, so a cut raises a `ProviderException` instead of saving half an answer, and the failed run can be retried.

## Speech and image providers

These classes implement the same interface, so custom nodes hold them like an LLM provider (see [workflow-extension.md](workflow-extension.md)). Only `chat()` applies: the last message is the input, the reply carries the media, and `structured()` throws.

| Class | Input to output | Distinctive arguments |
|-------|-----------------|-----------------------|
| `NeuronAI\Providers\OpenAI\Audio\OpenAITextToSpeech` | text to base64 `AudioContent` | `voice` |
| `NeuronAI\Providers\ElevenLabs\ElevenLabsTextToSpeech` | text to base64 `AudioContent` | `voiceId` |
| `NeuronAI\Providers\OpenAI\Audio\OpenAISpeechToText` | `AudioContent` holding a readable file path, or base64 with a media type, to text | `language` (default `en`) |
| `NeuronAI\Providers\ElevenLabs\ElevenLabsSpeechToText` | `AudioContent` holding a readable file path, or base64 with a media type, to text | none |
| `NeuronAI\Providers\ZAI\Audio\ZAITranscription` | base64 `AudioContent`, or a URL source opened as a stream, to text | none |
| `NeuronAI\Providers\OpenAI\Image\OpenAIImage` | prompt to base64 `ImageContent` | `output_format` (`png`, `jpeg`, `webp`) |
| `NeuronAI\Providers\ZAI\Image\ZAIImage` | prompt to URL `ImageContent` | none |
