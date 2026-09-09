# Tools Module

The tool system: callable capabilities exposed to the model. Self-contained.

## Tool vs ToolCall

A `Tool` is **capability**: schema, `__invoke()`, dependencies (DB connections, HTTP clients, closures). It lives on the agent's registry and never travels. A `ToolCall` is **conversation data**: the record of one invocation (name, callId, inputs, result guarded by `hasResult()`, per-call approval state). ToolCalls are what messages, stream chunks, observability events, persistence and the evaluation `Trajectory` carry; they are plain data and serialize natively. There is no separate "tool definition" value object: that role *is* `ToolCall`.

Providers build them (`HandleWithTools::newToolCall()`, validating the name against the registry), and `ToolNode` resolves each call back to a live tool at execution time against the inference request's tool list, the cycle's effective set (`src/Agent/AGENTS.md`). A call naming a tool outside that set is a loud `ToolException`, never a silent no-op. Nothing about a tool, closures included, is ever serialized.

## Defining a tool

Extend `Tool`. `name` and `description` are class property defaults, so the constructor stays free for dependencies; `properties()` describes the JSON schema (`ToolProperty`, `ArrayProperty`, `ObjectProperty`), and `__invoke()` receives the inputs as named arguments.

```php
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
            new ToolProperty(name: 'video_url', type: PropertyType::STRING, description: 'The URL of the YouTube video.', required: true),
        ];
    }

    public function __invoke(string $video_url): string
    {
        return $this->fetchTranscription($video_url);
    }
}
```

Toolkits (`AbstractToolkit`) group tools and contribute `guidelines()` to the system prompt; `only()` / `exclude()` / `with()` adjust the provided set per agent. Tool runs are counted by `getRunKey()`, the tool name by default, so `toolMaxRuns()` applies per tool; override it, or use the `TrackByInputs` trait, for parameter-aware limits.

## Results: return value vs exception

The split falls on the natural boundary of the language:

- **A return value is a conversational outcome.** A tool returns a string, an array (JSON-encoded) or a `ToolOutput`: a multimodal result built from the Chat module's content blocks (`ToolOutput::text/image/file/audio/video()`, or a block array). A failure the model should see and recover from is *returned*: `ToolOutput::error('Rate limited, retry after 60s')` carries the feedback as a text block with `isError()` true. Catch your own exceptions at the tool boundary and convert them visibly.
- **An escaped exception is a bug.** It propagates and aborts the run (fail-fast; history stays consistent). There is no framework exception that gets converted into a result. The agent-level `toolErrorHandler(fn (Throwable $e, ToolCall $call): string|ToolOutput|null)` is the cross-cutting override: a returned value settles as the call's result and the loop continues, `null` declines and the exception propagates.

Consumers detect multimodality on the **value** (`$call->getResult() instanceof ToolOutput`), never on the tool type. Providers whose API accepts content blocks map them natively and set their native error flag where one exists; text-only consumers (Ollama, stream adapters, token counting) fall back to `ToolOutput::getText()`, so include a `TextContent` in outputs meant to work everywhere (`ToolOutput` is `Stringable` for the same reason). `ToolNode`'s durable memo records the full `ToolOutput`, so a crash-replay restores multimodal results without re-running the tool.

## Approval

A tool declares its own intrinsic risk through the protected `approvalPolicy(array $inputs): bool|string` hook (default `false`); a string counts as `true` and doubles as the approval reason shown to the approver. Declarations are live: `ToolNode` asks every tool on every call, with the call's inputs bound, so the answer cannot drift across a suspend/resume boundary. There is no middleware and no agent-level switch to attach.

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

The agent developer overrides the declaration per instance at attach time, in both directions: `requireApproval()` forces the gate, `suppressApproval()` waives a declared one, `withApprovalPolicy(fn (ToolInterface $tool): bool|string)` replaces the policy. The last override wins. `ToolInterface::requiresApproval(array $inputs)` is the resolution point the node consults: override first, then declaration.

Per-call approval state (`ApprovalState`: pending / approved / rejected) is stamped on the `ToolCall` entries of the `ToolCallMessage` and persisted in **chat history**, the system of record for approvals; workflow state holds none of it. Two reasons travel with it in opposite directions: `approvalReason` (outbound, why the tool asked) and `rejectReason` (inbound, the approver's feedback, recorded on rejection only). The resume flow is described in `src/Agent/AGENTS.md`.
