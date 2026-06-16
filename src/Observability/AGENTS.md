# Observability Module

EventBus architecture for monitoring. All components emit events.

## Core

| File | Purpose |
|------|---------|
| `EventBus.php` | Event bus with global and scoped observers |
| `ObserverInterface.php` | Contract: `onEvent(source, event, data)` |

## Usage

```php
// Global observer (receives events from all workflows)
EventBus::observe(new LogObserver($logger));

// Scoped observer (receives events only from a specific workflow)
EventBus::observe(new LogObserver($logger), $workflowId);

// Emit events (internal)
EventBus::emit('custom-event', $this, $data);           // → global observers
EventBus::emit('custom-event', $this, $data, $workflowId); // → scoped observers only

// Cleanup
EventBus::clear($workflowId); // clear scoped observers for one workflow
EventBus::clear();            // clear all observers
```

## Built-in Observers

### ConsoleDebugObserver

Prints all LLM interactions to STDERR (bypasses PHPUnit output capture). Outputs:
- System prompt per round
- Available tools
- LLM responses (including tool calls)
- Tool execution inputs and results

```php
// Option 1: Fluent method
$agent->debug()->chat($message);

// Option 2: Environment variable (enables for all agents)
// NEURON_DEBUG=true php your-script.php
```

### LogObserver

PSR-3 logger integration:
```php
new LogObserver($psrLogger)
```

### InspectorObserver

Inspector APM integration. Tracks framework events as segments.

**Extending for custom events**:
```php
class CustomObserver extends InspectorObserver
{
    protected array $methodsMap = [
        ...parent::$methodsMap,
        'custom-event' => 'handleCustomEvent',
    ];

    public function handleCustomEvent(object $source, string $event, mixed $data): void
    {
        // Track custom event
    }
}
```

## Event Handlers

Traits for emitting events:

| Trait | Events |
|-------|--------|
| `HandleWorkflowEvents` | Workflow lifecycle |
| `HandleToolEvents` | Tool execution |
| `HandleSkillEvents` | Skill bootstrapping (`skills-bootstrapping`, `skills-bootstrapped`, `skill-activated`) |
| `HandleInferenceEvents` | AI inference |
| `HandleRagEvents` | RAG retrieval |
| `HandleStructuredEvents` | Structured output |

## Registration via Workflow

```php
$workflow->observe(new InspectorObserver($inspector));
```

## Events (`Events/`)

| Event | Data | Phase |
|-------|------|-------|
| **Workflow** | | |
| `WorkflowStart` | `eventNodeMap` | Workflow begins |
| `WorkflowEnd` | `state` | Workflow completes |
| `WorkflowNodeStart` | `node class, event` | Node execution starts |
| `WorkflowNodeEnd` | `node class, result` | Node execution ends |
| `MiddlewareStart` | `middleware, event` | Middleware before node |
| `MiddlewareEnd` | `middleware` | Middleware after node |
| **Inference** | | |
| `InferenceStart` | `message, instructions, tools, messages` | AI call begins |
| `InferenceStop` | `message, response` | AI response received |
| `InstructionsChanging` | `old, new` | Before instructions update |
| `InstructionsChanged` | `instructions` | After instructions update |
| **Skills** | | |
| `SkillsBootstrapped` | `skills[], instructions[]` | Skills indexed at startup |
| `SkillActivated` | `skillName, reason` | Skill activated by LLM |
| **Tools** | | |
| `ToolsBootstrapped` | `tools[], guidelines[]` | Tools resolved |
| `ToolCalling` | `tool` | Before tool execution |
| `ToolCalled` | `tool` | After tool execution |
| **Chat** | | |
| `MessageSaving` | `message` | Before message stored |
| `MessageSaved` | `message` | After message stored |
| **RAG** | | |
| `Retrieving` | `query` | Retrieval starts |
| `Retrieved` | `results` | Retrieval completes |
| **Structured Output** | | |
| `SchemaGeneration` | `class` | Schema extraction begins |
| `SchemaGenerated` | `class, schema` | Schema ready |
| `Extracting` | `message` | JSON extraction begins |
| `Extracted` | `message, schema, json` | JSON extracted |
| `Validating` | `class, json` | Validation begins |
| `Validated` | `class, result, errors` | Validation complete |
| `PreProcessing` | `class, message` | Pre-processing begins |
| `PreProcessed` | `class, message` | Pre-processing done |
| `PostProcessing` | `class, instance` | Post-processing begins |
| `PostProcessed` | `class, instance` | Post-processing done |
| **Serialization** | | |
| `Deserializing` | `class` | Deserialization begins |
| `Deserialized` | `class` | Deserialization done |
| **Error** | | |
| `AgentError` | `throwable` | Unhandled error |

## Dependencies

None. Self-contained.
