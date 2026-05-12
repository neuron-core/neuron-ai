# Tools Module

Tool system for agent capabilities. Tools are callable functions exposed to AI.

## Core

| File | Purpose |
|------|---------|
| `ToolInterface.php` | Contract: `getName()`, `getDescription()`, `getProperties()`, `invoke()` |
| `Tool.php` | Base class with property definitions |
| `ProviderTool.php` | Wrapper for MCP server tools |
| `ProviderToolInterface.php` | Contract for provider-exposed tools |

## Creating Custom Tools

Extend `Tool` and implement required methods:

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

class GetTranscriptionTool extends Tool
{
    public function __construct(protected string $apiKey)
    {
        parent::__construct(
            'get_transcription',
            'Retrieve the transcription of a YouTube video.',
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'video_url',
                type: PropertyType::STRING,
                description: 'The URL of the YouTube video.',
                required: true
            )
        ];
    }

    public function __invoke(string $video_url): string
    {
        // Your API call logic here
        return $transcription;
    }
}
```

## Custom Run Key Tracking

By default, Neuron tracks tool runs by tool name only. This means a tool called multiple times with different parameters counts against the same run limit.

For tools that need custom tracking (e.g., parameter-aware), implement the `RunKeyInterface`:

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\RunKeyInterface;

class ReadFileTool extends Tool implements RunKeyInterface
{
    public function __construct()
    {
        parent::__construct(
            'read_file',
            'Read a portion of a file.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make('path', PropertyType::STRING, 'File path', true),
            ToolProperty::make('offset', PropertyType::INTEGER, 'Byte offset', true),
            ToolProperty::make('length', PropertyType::INTEGER, 'Bytes to read', true),
        ];
    }

    public function __invoke(string $path, int $offset, int $length): string
    {
        // Read file portion
        return file_get_contents($path, false, null, $offset, $length);
    }

    public function getRunKey(): string
    {
        // Track runs by path and offset, allowing different offsets
        return $this->getName() . ':' . $this->getInput('path') . ':' . $this->getInput('offset');
    }
}
```

Alternatively, use the `TrackByInputs` trait for automatic input-based keys:

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\TrackByInputs;
use NeuronAI\Tools\RunKeyInterface;

class ReadFileTool extends Tool implements RunKeyInterface
{
    use TrackByInputs;
    // getRunKey() automatically uses all inputs via json_encode
}
```

**How it works:**

- Tools implementing `RunKeyInterface` provide a unique key via `getRunKey(): string`
- `ToolNode` and `ParallelToolNode` use the custom key for run tracking
- Tools without the interface use the tool name (backwards compatible)
- The `TrackByInputs` trait provides input-based key generation automatically

**Use cases:**

- Chunked file reading with different offsets
- Paginated API calls with different page numbers
- Database queries with different IDs

## Property Types

| Class | JSON Schema Type |
|-------|------------------|
| `ToolProperty` | string, number, boolean (via `PropertyType` enum) |
| `ArrayProperty` | array with item schema |
| `ObjectProperty` | object with nested properties |

## Usage with Agent Extension Pattern

Register tools in your custom agent class:

```php
use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\SystemPrompt;

class YouTubeAgent extends Agent
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
            background: ['You are an AI agent specialized in writing YouTube video summaries.'],
            steps: [
                'Get the URL of a YouTube video, or ask the user to provide one.',
                'Use the tools you have available to retrieve the transcription of the video.',
                'Write the summary.',
            ],
            output: [
                'Write a summary in a paragraph without using lists.',
                'After the summary add a list of three sentences as the most important takeaways.',
            ]
        );
    }

    protected function tools(): array
    {
        return [
            GetTranscriptionTool::make(env('SUPADATA_API_KEY')),
        ];
    }
}

// Usage
use NeuronAI\Chat\Messages\UserMessage;

$response = YouTubeAgent::make()->chat(
    new UserMessage('Summarize this: https://youtube.com/watch?v=...')
);
```

## Toolkits (`Toolkits/`)

Group related tools. Extend `AbstractToolkit`:

```php
use NeuronAI\Tools\Toolkits\AbstractToolkit;

class MyToolkit extends AbstractToolkit
{
    public function tools(): array
    {
        return [new ToolA(), new ToolB()];
    }
}

// In agent
protected function tools(): array
{
    return [
        new MyToolkit(),
    ];
}
```

### Built-in Toolkits

| Toolkit | Purpose |
|---------|---------|
| `Calculator/` | Math operations |
| `MySQL/` | MySQL database queries |
| `PGSQL/` | PostgreSQL queries |
| `Tavily/` | Web search API |
| `Zep/` | Zep memory integration |
| `AWS/` | AWS services (SES, etc.) |
| `Jina/` | Jina AI embeddings |
| `Supadata/` | Supadata API |
| `FileSystem/` | File operations |
| `Calendar/` | Calendar operations |

## Retrieval Tool

`RetrievalTool.php` - Generic tool for RAG document retrieval.

## Dependencies

None. Tools module is self-contained.
