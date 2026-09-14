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
| `NeuronAI\Providers\HuggingFace\HuggingFace` | `inferenceProvider`: an `InferenceProvider` enum case (`HF_INFERENCE` default, `GROQ`, `TOGETHER`, `CEREBRAS`, `FIREWORKS_AI`, ...) |

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
| `NeuronAI\Providers\OpenAI\AzureOpenAI` | `key`, `endpoint` (the resource host), `model` (the deployment name), `version` (the `api-version` query value) |

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

`strict_response: true` on the OpenAI family turns on the vendor's strict JSON schema mode for `structured()`; the framework rewrites the schema to satisfy the strict-mode rules. Prompt caching follows the `cache()` marker on system blocks described in the skill: Anthropic turns each cached block into a `cache_control` breakpoint, the OpenAI Responses provider into a `prompt_cache_breakpoint`, and the other providers ignore the marker.

## HTTP client

Every HTTP-based provider builds a `CurlHttpClient` unless one is passed as `httpClient` or installed later with `setHttpClient()`. Use the client for proxies and CA bundles (`curlOptions`), for request and response taps (`onRequest()` / `onResponse()`), or to swap the transport:

```php
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;

// guzzlehttp/guzzle: HandlerStack middleware for retries, logging, recording
$provider->setHttpClient(new GuzzleHttpClient(handler: $handlerStack));
```

`NeuronAI\HttpClient\Amp\AmpHttpClient` (amphp/http-client) is the async adapter. Streaming stays incremental with every client.

## Speech and image providers

These classes implement the same interface, so custom nodes hold them like an LLM provider (see [workflow-extension.md](workflow-extension.md)). Only `chat()` applies: the last message is the input, the reply carries the media, and `structured()` throws.

| Class | Input to output | Distinctive arguments |
|-------|-----------------|-----------------------|
| `NeuronAI\Providers\OpenAI\Audio\OpenAITextToSpeech` | text to base64 `AudioContent` | `voice` |
| `NeuronAI\Providers\ElevenLabs\ElevenLabsTextToSpeech` | text to base64 `AudioContent` | `voiceId` |
| `NeuronAI\Providers\OpenAI\Audio\OpenAISpeechToText` | `AudioContent` holding a readable file path, to text | `language` (default `en`) |
| `NeuronAI\Providers\ElevenLabs\ElevenLabsSpeechToText` | `AudioContent` holding a readable file path, to text | none |
| `NeuronAI\Providers\ZAI\Audio\ZAITranscription` | base64 `AudioContent`, or a URL source opened as a stream, to text | none |
| `NeuronAI\Providers\OpenAI\Image\OpenAIImage` | prompt to base64 `ImageContent` | `output_format` (`png`, `jpeg`, `webp`) |
| `NeuronAI\Providers\ZAI\Image\ZAIImage` | prompt to URL `ImageContent` | none |
