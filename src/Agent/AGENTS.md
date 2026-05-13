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

$response = YouTubeAgent::make()->chat(
    new UserMessage('Summarize this: https://youtube.com/watch?v=...')
);
```

## Fluent Definition (Alternative)

For quick prototyping or simple use cases:

```php
use NeuronAI\Agent;
use NeuronAI\Providers\Anthropic\Anthropic;

$agent = Agent::make()
    ->setAiProvider(new Anthropic(key: '...', model: '...'))
    ->setInstructions('You are a helpful assistant.')
    ->addTool($tool)
    ->debug(); // Print all LLM interactions to the console

$response = $agent->chat(new UserMessage('Hello'));
```

## Execution Modes

`Agent.php` composes a Workflow internally with specialized nodes:

| Method | Node | Description |
|--------|------|-------------|
| `chat()` | `ChatNode` | Standard inference, returns full response |
| `stream()` | `StreamingNode` | Yields chunks via generator |
| `structured()` | `StructuredOutputNode` | Extracts typed output via JSON schema |

```php
// Streaming
foreach (YouTubeAgent::make()->stream($message) as $chunk) {
    echo $chunk;
}

// Structured output
$report = MyAgent::make()->structured($message, ReportSchema::class);
```

## Key Files

| File | Purpose |
|------|---------|
| `Agent.php` | Main class, builds workflow per mode, composes system prompt |
| `AgentState.php` | Extends `WorkflowState` with message history |
| `SystemPrompt.php` | System prompt builder (background, steps, output) |
| `ResolveProvider.php` | Trait for provider injection |
| `HandleSkills.php` | Trait: skill registration, bootstrapping, activation |

## Skills (`Skills/`)

Skills are self-contained bundles of instructions and tools that can be registered with an agent. They follow a plugin architecture with lifecycle hooks.

### PHP Class Skills

Extend `AbstractSkill` and override what you need:

```php
use NeuronAI\Agent\Skills\AbstractSkill;

class WebSearchSkill extends AbstractSkill
{
    public function name(): string { return 'web-search'; }
    public function instructions(): string { return 'Always cite sources.'; }
    public function tools(): array { return [SearchTool::make()]; }
}
```

Register via `skills()` override or `addSkill()` at runtime:

```php
// Override in agent subclass
protected function skills(): array
{
    return [WebSearchSkill::make()];
}

// Or at runtime
$agent->addSkill(WebSearchSkill::make());
```

### Markdown Skills (agentskills.io)

Load skills from directories containing `SKILL.md` files following the [agentskills.io](https://agentskills.io/specification) specification:

```
skills/
├── web-search/
│   └── SKILL.md      # YAML frontmatter + Markdown instructions
├── translator/
│   └── SKILL.md
```

```php
// Auto-discover all skills from parent directories
$agent->addSkillDirectory(['/path/to/skills', '/path/to/custom-skills']);

// Register individual skill directories
$agent->addSkillPaths(['/path/to/skills/web-search', '/path/to/skills/docker']);
```

When skill names collide across paths, later paths take precedence.

### Key Files (`Skills/`)

| File | Purpose |
|------|---------|
| `SkillInterface.php` | Contract: `name()`, `priority()`, `instructions()`, `tools()`, `configure()` |
| `AbstractSkill.php` | Base class with defaults (priority 0, null instructions, empty tools) |
| `MarkdownSkill.php` | Parses `SKILL.md` (YAML frontmatter + body) into `SkillInterface` |
| `SkillLoader.php` | `discover()` scans parent dirs, `loadPaths()` loads individual skill dirs |
| `SkillActivationManager.php` | Tracks which skills are currently active |

### Skill Activation Model

Skills use an **LLM-initiated activation** model:

1. **Bootstrap**: All skills are indexed. The system prompt includes skill summaries with activation hints:
   ```
   # web-search: Search the web for information
   When you need to use this skill, respond with [ACTIVATE_SKILL: web-search]
   ```

2. **LLM triggers activation**: When the LLM responds with `[ACTIVATE_SKILL: name]`, the runtime:
   - Records the activation in `SkillActivationManager`
   - Adds the skill's tools to the agent tool pool
   - Appends the skill's full instructions as a `<skill>` context block
   - Continues the agent loop — the LLM sees the new tools and instructions on the next turn

3. **LLM is the orchestrator**: The runtime does NOT force execution phases, queue skills, or hijack control flow. The LLM decides which tools to call, when, and how many.

4. **Multi-skill**: Multiple skills can be activated in a single response. All their tools are exposed simultaneously. The LLM freely combines capabilities.

5. **Append-only**: Skill instructions are never removed once injected. The system prompt grows monotonically as skills are activated.

### Information Separation (LangChain-inspired)

Following LangChain's principle that the LLM only needs the **tool triplet** (name + description + args schema), skill information is split by purpose:

| What | Where the LLM sees it |
|------|----------------------|
| Behavioral instructions (e.g. "Always cite sources") | System prompt `<SKILL-GUIDELINES>` |
| Tool name, description, parameters | Tool JSON schema (via provider's `tools` API parameter) |
| Output expectations (e.g. "Returns: status_code, response_time") | Merged into the tool's `description` field |
| Activation hint | System prompt `[ACTIVATE_SKILL: name]` |
| `## Trigger`, `## Reasoning`, `## Plan`, `## Policy`, `## Fallback` | Included in skill instructions as LLM guidance |
| `## Tools` | **Never** reaches the LLM as text — parsed into Tool objects |

### System Prompt Composition

`composeSystemPrompt()` assembles: base instructions → `<TOOLS-GUIDELINES>` → `<SKILL-GUIDELINES>`. This is a pure computation — it re-reads the current instruction cache each time, picking up newly activated skill blocks automatically.

## Nodes (`Nodes/`)

| Node | Purpose |
|------|---------|
| `ChatNode` | Standard inference + inline skill activation |
| `StreamingNode` | Streaming inference + inline skill activation |
| `StructuredOutputNode` | JSON schema extraction |
| `ToolNode` | Executes tool calls |
| `ParallelToolNode` | Executes multiple tools concurrently |

## Middleware (`Middleware/`)

Register via `$workflow->middleware(NodeClass::class, $middleware)`:

| Middleware | Purpose |
|------------|---------|
| `ToolApproval` | Human-in-the-loop for tool execution |
| `TodoPlanning` | Injects todo planning capabilities |
| `Summarization` | Adds conversation summarization |

## Events (`Events/`)

| Event | Role |
|-------|------|
| `AgentStartEvent` | Base class, holds messages |
| `AIInferenceEvent` | Extends `AgentStartEvent`, carries instructions + tools; created in `startEvent()`, flows through all nodes |
| `ToolCallEvent` | Wraps `ToolCallMessage` + the originating `AIInferenceEvent` |

## Workflow Flow

```
startEvent()
  → bootstrapSkills() → bootstrapTools() → composeSystemPrompt()
  → returns AIInferenceEvent (instructions, tools)
     │
     ▼
  ChatNode.__invoke(AIInferenceEvent)
    → LLM inference
    ├─ If [ACTIVATE_SKILL: name]:
    │    activateSkill() → mutate same event (instructions, tools) → clear messages → return event (loop)
    ├─ If tool_call: wrap in ToolCallEvent → ToolNode executes tools → mutate original event → return event (loop)
    └─ If text response: return StopEvent
```
