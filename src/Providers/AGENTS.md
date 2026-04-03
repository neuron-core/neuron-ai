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
$response = MyAgent::make()->chat(new UserMessage('Hello!'));
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
