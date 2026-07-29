# Agent Module

AI agent built on Workflow. Provides chat, streaming, and structured output modes.

**Dependencies**: `src/Workflow/AGENTS.md`, `src/Chat/AGENTS.md`, `src/Providers/AGENTS.md`, `src/Tools/AGENTS.md`

## Extension Pattern (Recommended)

Create a custom agent class extending `Agent`:

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

$response = YouTubeAgent::make()
    ->chat(
        new UserMessage('Summarize this: https://youtube.com/watch?v=...')
    )
    ->getMessage();
```

## Fluent Definition (Alternative)

For quick prototyping or simple use cases:

```php
use NeuronAI\Agent;
use NeuronAI\Providers\Anthropic\Anthropic;

$agent = Agent::make()
    ->setAiProvider(new Anthropic(key: '...', model: '...'))
    ->setInstructions('You are a helpful assistant.')
    ->addTool($tool);

$response = $agent->chat(new UserMessage('Hello'))->getMessage();
```

## Execution Modes

`Agent.php` composes a Workflow internally with specialized nodes:

| Method | Node | Description |
|--------|------|-------------|
| `chat()` | `ChatNode` | Standard inference, returns full response |
| `stream()` | `StreamingNode` | Yields chunks via generator |
| `structured()` | `StructuredOutputNode` | Extracts typed output via JSON schema |

```php
// Chat
$response = YouTubeAgent::make()->chat(new UserMessage('Hello'))->getMessage();

// Streaming
$handler = YouTubeAgent::make()->stream(new UserMessage('Hello'));
foreach ($handler->events() as $chunk) {
    echo $chunk->content;
}
$response = $handler->getMessage();

// Structured output
$report = MyAgent::make()->structured($message, ReportSchema::class);
```

## Middleware (`Middleware/`)

Register via `$workflow->middleware(NodeClass::class, $middleware)`:

| Middleware | Purpose |
|------------|---------|
| `ToolApproval` | Human-in-the-loop for tool execution |
| `TodoPlanning` | Injects todo planning capabilities |
| `Summarization` | Adds conversation summarization |

Node matching is subclass-aware (`instanceof`), so middleware registered for a
class also applies to its subclasses. `ChatNode`, `StreamingNode`, and
`StructuredOutputNode` are siblings — the Agent instantiates exactly one per
mode (`chat()` / `stream()` / `structured()`). They all share the
`InferenceNode` base class, so register mode-agnostic inference middleware
(`Summarization`, `TodoPlanning`, `ToolSearchMiddleware`) against
`InferenceNode::class` to have it fire in **all three** modes rather than only
the one whose node class you named.

## Persistence & Tool Approval

`ToolApproval` gates tool execution behind human approval. Attach it to `ToolNode::class`
(subclass-aware matching covers `ParallelToolNode` too):

```php
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;

$agent->addMiddleware(ToolNode::class, new ToolApproval());
```

With no constructor config, **each tool decides** via its own `requiresApproval()`
declaration (ADR 0004); middleware config overrides that in both directions. When a gated
tool is requested, `chat()` returns suspended instead of completed — this requires **workflow
persistence AND a durable chat history** (chat history is the system of record for approval
state — ADR 0003). `InMemoryChatHistory` keeps the safety property but loses progress across
processes.

```php
use NeuronAI\Workflow\Persistence\FilePersistence;

$agent = YouTubeAgent::make()
    ->setPersistence(new FilePersistence($directory))
    // + a durable ChatHistory
    ->addMiddleware(ToolNode::class, new ToolApproval());
```

Resume delivers decisions as an **incremental** payload keyed by tool callId — only NEW
decisions, never a restatement:

```php
$agent->chat(payload: ['call_123' => 'approve', 'call_456' => ['reject', 'too expensive']]);
```

A tool runs iff explicitly approved; silence is never consent. Decisions are revisable
(last-write-wins) until the full set completes. The UI re-renders pending approvals by
reading chat history (last message, tools with `getApprovalState()`) — no workflow boot.
The thread must stay **locked** until every decision is delivered: starting a new turn on a
thread with pending approvals throws `AgentException`.
