# Tools Module

Tool system for agent capabilities. Tools are callable functions exposed to AI.

## Core

| File | Purpose |
|------|---------|
| `ToolInterface.php` | Contract: `getName()`, `getDescription()`, `getProperties()`, `invoke()` |
| `Tool.php` | Base class with property definitions |
| `HasInterrupt.php` | Interface for tools that can signal workflow interrupts |
| `InterruptHandler.php` | Trait providing interrupt/resume storage for `HasInterrupt` tools |
| `FrontendTool.php` | Concrete tool that delegates execution to a frontend handler |
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

## Interrupt Signaling

Tools can pause the workflow and request external input (e.g., human approval, frontend interaction) by implementing `HasInterrupt`. Use the `InterruptHandler` trait for the standard implementation.

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\HasInterrupt;
use NeuronAI\Tools\InterruptHandler;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\Action;

class PurchaseTool extends Tool implements HasInterrupt
{
    use InterruptHandler;

    public function __invoke(float $amount): string
    {
        // On resume, handle the user's response
        if ($this->getResumeRequest() !== null) {
            return 'Approved: $' . $amount;
        }

        // Signal an interrupt requesting approval
        $this->setInterruptRequest(new ApprovalRequest(
            "Approve purchase of \${$amount}?",
            [new Action('approve', 'Approve', true)]
        ));

        return '';
    }
}
```

**Flow:**
1. Tool's `__invoke` calls `setInterruptRequest()` — workflow pauses, `WorkflowInterrupt` is thrown
2. `ToolNode` wraps the request in `ToolsInterruptRequest` (supports merging multiple in parallel)
3. On resume, `ToolNode` injects the user's response via `setResumeRequest()` before re-execution
4. Tool checks `getResumeRequest()` in `__invoke` and handles the response

**How it works:**
- Tools implementing `HasInterrupt` are checked after `execute()` in both `ToolNode` and `ParallelToolNode`
- `ParallelToolNode` collects all tools' interrupt requests into a single `ToolsInterruptRequest`
- Tools without `HasInterrupt` are unaffected — no overhead

### Multi-Step State Tracking

Tool properties survive serialization across interrupt/resume cycles, so tools can track progress through multiple confirmation steps using their own properties.

```php
class MultiStepTool extends Tool implements HasInterrupt
{
    use InterruptHandler;

    private int $step = 0;
    private array $steps = ['confirm action', 'confirm target'];

    public function __invoke(mixed ...$params): string
    {
        // Advance step on resume
        if ($this->getResumeRequest() !== null) {
            $this->step++;
        }

        // All steps done
        if ($this->step >= count($this->steps)) {
            return 'All steps completed';
        }

        // Signal interrupt for current step
        $this->setInterruptRequest(
            new ApprovalRequest($this->steps[$this->step])
        );

        return '';
    }
}
```

On each resume, the tool's properties (like `$step`) are restored from serialization, allowing it to pick up where it left off. The old `interruptRequest` is automatically cleared by `ToolNode` when injecting the resume request.

**Important:** Tools must use a **named class** (not anonymous) since anonymous classes cannot be serialized in PHP. Tools are serialized as part of the `WorkflowInterrupt` when persisted.

## FrontendTool

`FrontendTool` is a ready-made tool that delegates execution to a frontend handler. On first call, it signals an interrupt with a `FrontendRequest` containing the handler identifier and input parameters. On resume, it returns the frontend's response.

```php
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

$agent->addTool(new FrontendTool(
    'pick_user',
    'user-picker', // frontend handler ID
    'Open a modal to select a user',
    [ToolProperty::make('role', PropertyType::STRING, 'Filter by role', true)]
));
```

**Frontend receives:**
```json
{ "handler": "user-picker", "payload": { "role": "admin" }, "message": "Frontend tool: pick_user" }
```

**On resume**, the frontend sends back a `FrontendRequest` with the result in its payload. The tool returns the payload as JSON.

## Custom Run Key Tracking

By default, Neuron tracks tool runs by tool name only. This means a tool called multiple times with different parameters counts against the same run limit.

For tools that need custom tracking (e.g., parameter-aware), implement the `HasRunKey`:

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\HasRunKey;

class ReadFileTool extends Tool implements HasRunKey
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
use NeuronAI\Tools\HasRunKey;

class ReadFileTool extends Tool implements HasRunKey
{
    use TrackByInputs;
    // getRunKey() automatically uses all inputs via json_encode
}
```

**How it works:**

- Tools implementing `HasRunKey` provide a unique key via `getRunKey(): string`
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
