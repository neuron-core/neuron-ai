# Providers Module

AI provider abstractions. All providers implement `AIProviderInterface`.

**Dependencies**: `src/Chat/AGENTS.md`, `src/HttpClient/AGENTS.md`

## Interface

```php
interface AIProviderInterface {
    public function chat(array $messages): Message;
    public function stream(array $messages): Generator;
    public function setTools(array $tools): self;
}
```

## Provider Implementations

| Directory | Provider |
|-----------|----------|
| `Anthropic/` | Claude API |
| `OpenAI/` | GPT models |
| `Gemini/` | Google Gemini |
| `Ollama/` | Local models |
| `Mistral/` | Mistral AI |
| `Deepseek/` | Deepseek |
| `Cohere/` | Cohere |
| `HuggingFace/` | Hugging Face |
| `XAI/` | xAI Grok |
| `ZAI/` | Zhipu AI |
| `AWS/` | AWS Bedrock |
| `ElevenLabs/` | ElevenLabs TTS |

Each provider has:
- `*Provider.php` - Main implementation
- `*MessageMapper.php` - Converts `Message` → API format
- `*ToolMapper.php` - Converts `Tool` → API format (if tools supported)

## Key Files

| File | Purpose |
|------|---------|
| `AIProviderInterface.php` | Main contract |
| `HandleWithTools.php` | Trait for tool management |
| `MessageMapperInterface.php` | Message conversion contract |
| `ToolMapperInterface.php` | Tool conversion contract |
| `SSEParser.php` | Server-Sent Events parsing for streaming |
| `OpenAILike.php` | Base for OpenAI-compatible APIs |
| `OpenAILikeResponses.php` | Response handling for OpenAI-like APIs |
| `BasicStreamState.php` | Stream state tracking |

## Multimodal Tool Results

A tool result is `string|ToolOutput` (see `src/Tools/AGENTS.md`). Each MessageMapper's
tool-result mapping checks `$tool->getResult() instanceof ToolOutput` — detection is on
the value, never the tool type — and maps the content blocks natively where the
underlying API accepts them, reusing the mapper's existing block-mapping code:

| Provider | Behavior with a `ToolOutput` result |
|----------|-------------------------------------|
| Anthropic | `tool_result.content` as native block array (`text`, `image`, `document`) |
| AWS Bedrock | `toolResult.content` as native block array (`text`, `image`, `document`, `video`, `audio`) |
| Gemini | `functionResponse.response.content` = `{parts: [...]}` (`text`, `inline_data`, `file_data`) |
| OpenAI Chat Completions | `content` as block array (`text`, `image_url`). Inherited by Cohere, Deepseek, ZAI |
| OpenAI Responses | `function_call_output.output` as block array (`input_text`, `input_image`, `input_file`) |
| Mistral | `content` as block array (`text`, `image_url`, `document_url`, `input_audio`) |
| Ollama | Text-only API — falls back to `ToolOutput::getText()` |

Block types a provider's API doesn't support fall out through the mapper's existing
null-filtering. Plain string results map exactly as before.

An error output (`ToolOutput::error()`) additionally sets the provider's
native error flag where one exists — `is_error: true` on Anthropic's `tool_result`,
`status: "error"` on Bedrock's `toolResult`. The other providers have no such concept;
the feedback text itself carries the error semantics.

## Usage with Agent Extension Pattern

Create a custom agent class extending `Agent`:

```php
use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\SystemPrompt;

class MyAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: env('ANTHROPIC_API_KEY'),
            model: 'claude-sonnet-4-6',
        );
    }

    public function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ['You are a helpful AI assistant.'],
            steps: ['Answer questions accurately and concisely.'],
            output: ['Be friendly and professional.']
        );
    }
}

// Usage
$response = MyAgent::make()->chat(new UserMessage('Hello!'))->getMessage();
```

### Alternative Providers

```php
// OpenAI
use NeuronAI\Providers\OpenAI\OpenAI;

protected function provider(): AIProviderInterface
{
    return new OpenAI(
        key: env('OPENAI_API_KEY'),
        model: 'gpt-4o',
    );
}

// Gemini
use NeuronAI\Providers\Gemini\Gemini;

protected function provider(): AIProviderInterface
{
    return new Gemini(
        key: env('GEMINI_API_KEY'),
        model: 'gemini-2.0-flash',
    );
}

// Ollama (local)
use NeuronAI\Providers\Ollama\Ollama;

protected function provider(): AIProviderInterface
{
    return new Ollama(
        model: 'llama3.2',
    );
}
```

## Adding New Provider

1. Create `src/Providers/NewProvider/`
2. Implement `NewProviderProvider.php` with `AIProviderInterface`
3. Create `NewProviderMessageMapper.php` implementing `MessageMapperInterface`
4. Create `NewProviderToolMapper.php` if tools supported
5. Use `HasHttpClient` trait for HTTP injection

## HTTP Client

Providers use `HttpClientInterface` via `HasHttpClient` trait. Default is `GuzzleHttpClient`.
