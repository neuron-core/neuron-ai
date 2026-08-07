# Tools Module

Tool system for agent capabilities. Tools are callable functions exposed to AI.

## Core

| File | Purpose                                                                   |
|------|---------------------------------------------------------------------------|
| `ToolInterface.php` | Contract: `getName()`, `getDescription()`, `getProperties()`, `execute()` |
| `Tool.php` | Base class with property definitions                                      |
| `ToolCall.php` | The value object a tool invocation travels as (see below)                 |
| `ToolOutput.php` | Multimodal tool result: wraps content blocks (text, image, file, audio, video) |
| `ProviderTool.php` | Wrapper for MCP server tools                                              |
| `ProviderToolInterface.php` | Contract for provider-exposed tools                                       |

## Tool vs ToolCall (ADR 0010)

A `Tool` is **capability**: schema, `__invoke()`, dependencies (DB connections, HTTP
clients, closures). It lives on the agent's registry and never travels. A `ToolCall` is
**conversation data**: the record of one invocation — name, callId, inputs, description,
result (`string|ToolOutput`, guarded by `hasResult()`), and per-call approval state
(`ApprovalState` + `approvalReason` + `rejectReason`). ToolCalls are what
`ToolCallMessage`/`ToolResultMessage`, stream chunks, observability events, persistence,
and the evaluation Trajectory carry; they are plain data and serialize natively.
Providers build them (`HandleWithTools::newToolCall()`, validating the name against the
registry), and `ToolNode` resolves each call back to a live tool at execution — against
the inference event's tool list only, the cycle's effective set (see
`src/Agent/AGENTS.md`). A call naming a tool outside that set is a loud `ToolException`,
never a silent no-op.
There is no `ToolDefinition` anymore: its data-only stand-in role IS `ToolCall`.

## Creating Custom Tools

Extend `Tool` and implement required methods:

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

class GetTranscriptionTool extends Tool
{
    protected string $name = 'get_transcription';

    protected ?string $description = 'Retrieve the transcription of a YouTube video.';

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

### Tools with Dependencies

For tools that need constructor dependencies, keep the constructor but set `name` and `description` as class property defaults:

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

class GetTranscriptionTool extends Tool
{
    protected string $name = 'get_transcription';

    protected ?string $description = 'Retrieve the transcription of a YouTube video.';

    public function __construct(protected string $apiKey)
    {
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

## Multimodal Tool Output

A tool result is `string|ToolOutput` (read from the settled `ToolCall`'s `getResult()`;
call it only when `hasResult()` is true — a call that never executed, e.g. pending or
rejected, has no result). Return a
`ToolOutput` from `__invoke()` to send content blocks (reusing the Chat module's
`ContentBlockInterface` implementations) back to the model instead of plain text —
no opt-in interface, the feature is first-class on every tool:

```php
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Tools\ToolOutput;

public function __invoke(string $symbol): ToolOutput
{
    return new ToolOutput([
        new TextContent("Price chart for {$symbol}"),
        new ImageContent($base64, SourceType::BASE64, 'image/png'),
    ]);
}
```

Single-block shortcuts: `ToolOutput::text(...)`, `::image(...)`, `::file(...)`,
`::audio(...)`, `::video(...)` — each mirrors the corresponding content block's
constructor.

Consumers detect multimodality on the **value**, never the tool type:
`$call->getResult() instanceof ToolOutput`. Providers whose API accepts content
blocks in tool results map them natively; text-only consumers (Ollama, stream
adapters, token counting) fall back to `ToolOutput::getText()` — the concatenated
text blocks (empty when there are none, so include a `TextContent` in outputs meant
to work everywhere). `ToolOutput` is `Stringable` (delegating to `getText()`), so
string interpolation degrades gracefully.

String and array returns from `__invoke()` behave exactly as before (arrays are
JSON-encoded). Chat history round-trips a `ToolOutput` result as a content block
array (see `src/Chat/AGENTS.md`), and `ToolNode`'s durable memo records the full
`ToolOutput`, so crash-replay restores multimodal results without re-running the
tool.

## Custom Run Key Tracking

By default, Neuron tracks tool runs by tool name only. This means a tool called multiple times with different parameters counts against the same run limit.

For tools that need custom tracking (e.g., parameter-aware), implement the `getRunKey()` method:

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

class ReadFileTool extends Tool
{
    protected string $name = 'read_file';

    protected ?string $description = 'Read a portion of a file.';

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
use NeuronAI\Tools\TrackByInputs;

class ReadFileTool extends Tool
{
    use TrackByInputs;
}
```

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
            new GetTranscriptionTool(env('SUPADATA_API_KEY')),
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
    public function guidelines(): ?string
    {
        return "Guidelines go into the system prompt of the agent to help the model use the tools provided below.";
    }

    public function provide(): array
    {
        return [
            new ToolA(),
            new ToolB()
        ];
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

## Tool Approval

A tool declares its own intrinsic risk by overriding the protected
`approvalPolicy(array $inputs): bool|string` hook (default `false`). Declarations are
**live by default** (ADR 0009): `ToolNode` asks every tool on every call — no middleware
or agent-level switch exists. The agent developer overrides the declaration per instance,
at attach time, in both directions:

- `requireApproval(bool $require = true)` — force the gate's answer either way.
- `suppressApproval()` — sugar for `requireApproval(false)`; waives a declared gate.
- `withApprovalPolicy(callable $policy)` — replace the policy with a
  `fn(ToolInterface $tool): bool|string` callback.

The last configured override wins (each clears the other). The public
`requiresApproval(array $inputs)` on `ToolInterface` is the *resolution point* the node
consults: override → declaration. The node always asks the LIVE registry tool with the
call's inputs bound (ADR 0010), so the answer cannot drift across a suspend/resume
boundary — and nothing about the tool (closures included) is ever serialized.

Returning a **string counts as `true`** and doubles as the approval reason — the outbound
"why am I asking" shown to the approver, surfaced on the `ApprovalRequest` actions and
persisted on the tool entry (`getApprovalReason()` / `approvalReason` in the serialized
message):

```php
class TransferMoneyTool extends Tool
{
    protected function approvalPolicy(array $inputs): bool|string
    {
        return ($inputs['amount'] ?? 0) > 100
            ? 'Transfers above $100 require a human sign-off'
            : false;
    }
}
```

Per-call approval state (`pending` / `approved` / `rejected`) is stamped on the
`ToolCall` entries of the `ToolCallMessage` and persisted in **chat history** — that is
the system of record (ADR 0003), not workflow state. See `ApprovalState`. Two reasons may
accompany it, with opposite directions: `approvalReason` (outbound, the requester's
purpose) and `rejectReason` (inbound, the approver's feedback — rejection-only, recorded
via `ToolCall::setApprovalState(ApprovalState::Rejected, $reason)` and read via
`getRejectReason()`).

## Dependencies

None. Tools module is self-contained.
