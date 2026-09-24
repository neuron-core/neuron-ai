# Upgrade: Agent middleware edit the request and the segment's tools

## Summary

- **The inference event no longer carries instructions and tools.** In 3.x a middleware edited
  `AIInferenceEvent::$instructions`, a string, and `AIInferenceEvent::$tools`. The event is now a routing signal. The
  instructions of the current inference live in `$state->request->instructions`, a `SystemMessage` (guide 25); the
  tools live in the segment's `NeuronAI\Tools\ToolRegistry`, `$resources->tools`.
- **A tool a middleware adds lasts one execution segment.** The registry is rebuilt for every segment and tools never
  enter the state, so the middleware adds its tools in `before()` every time. Register it with
  `addGlobalMiddleware()`: a continuation after an approval or a deferred tool starts at the tool node, which must find
  the tools the model was offered.
- **`ToolSearchMiddleware` is registered globally.** Before every agent node it registers the `tool_search` tool and
  the tools found during the current turn; the next user message starts a turn without them. Its `after()` hook and
  its `extractDiscoveredTools()`, `getToolNames()` and `hasToolSearchTool()` helpers are removed, and `topN` defaults
  to 5 (10 in 3.x).
- **`Summarization` uses the segment's provider unless it is given one.** Its provider argument is optional, and the
  provider reaches the protected methods as a parameter. It extends `AgentMiddleware`.

| Before (3.x) | After |
|---|---|
| `$event->instructions .= $text` | `$state->request->instructions->addContent(new SystemContent($text))` |
| `$event->tools[] = $tool` | `$resources->tools->add($tool)` |
| `addMiddleware(ChatNode::class, new ToolSearchMiddleware($pool))` | `addGlobalMiddleware(new ToolSearchMiddleware($pool))` |
| `new Summarization($provider, ...)` | `new Summarization(...)` for the agent's provider, `new Summarization($provider, ...)` for another |
| `Summarization::summarizeHistory(AgentState $state, array $messages)` | `summarizeHistory(ChatHistory $chatHistory, array $messages, AIProviderInterface $provider)` |
| `Summarization::generateSummary(array $messages)` | `generateSummary(AIProviderInterface $provider, array $messages)` |

## How to Refactor

### Case 1: A middleware that adds instructions or tools

Extend `AgentMiddleware`. Add the tools on every call; `add()` ignores a tool whose name is registered already. The
request is part of the persisted state, so add the instructions once per run.

Before:

```php
class ReportingTools implements WorkflowMiddleware
{
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if (!$event instanceof AIInferenceEvent) {
            return;
        }

        $event->instructions .= "\n\nUse the reporting tools for numbers.";
        $event->tools[] = new ReportTool();
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
    }
}

$agent->addMiddleware(ChatNode::class, new ReportingTools());
```

After:

```php
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Workflow\Events\Event;

class ReportingTools extends AgentMiddleware
{
    protected const INSTRUCTIONS = 'Use the reporting tools for numbers.';

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
    {
        $resources->tools->add(new ReportTool());

        if ($event instanceof AIInferenceEvent && !$state->request->instructions->contains(self::INSTRUCTIONS)) {
            $state->request->instructions->addContent(new SystemContent(self::INSTRUCTIONS));
        }
    }
}

$agent->addGlobalMiddleware(new ReportingTools());
```

A middleware that only edits instructions keeps a node-specific registration, such as
`addMiddleware(InferenceNode::class, ...)`.

### Case 2: `ToolSearchMiddleware`

Before:

```php
$agent->addMiddleware(ChatNode::class, new ToolSearchMiddleware($pool));
```

After:

```php
$agent->addGlobalMiddleware(new ToolSearchMiddleware($pool));
```

A subclass overriding `after()` or the removed helpers moves its logic to `beforeAgentNode()`.
`discoverFromMessages()` re-derives the tools found by the `tool_search` calls it is given.

### Case 3: `Summarization`

Drop the provider argument to summarize with the agent's provider; keep it to use another one. A subclass overriding
`before()` overrides `beforeAgentNode()` instead, and one overriding `summarizeHistory()` or `generateSummary()`
declares the provider parameter and uses it rather than `$this->provider`, which is null when the middleware was built
without one.

Before:

```php
protected function generateSummary(array $messages): string
{
    return $this->provider
        ->chat(new UserMessage($this->formatMessagesForSummarization($messages)))
        ->getContent();
}
```

After:

```php
protected function generateSummary(AIProviderInterface $provider, array $messages): string
{
    return $provider
        ->chat(new UserMessage($this->formatMessagesForSummarization($messages)))
        ->message()
        ->getContent();
}
```

## What to Search For

```
grep -rnE "\\\$event->(instructions|tools)" --include="*.php" .
grep -rn "ToolSearchMiddleware" --include="*.php" .
grep -rn "Summarization" --include="*.php" .
grep -rnE "function (summarizeHistory|generateSummary)\(" --include="*.php" .
```

## Checklist

- No middleware reads or writes `$event->instructions` or `$event->tools`.
- A middleware that adds tools adds them to `$resources->tools` on every call and is registered with `addGlobalMiddleware()`.
- `ToolSearchMiddleware` is registered with `addGlobalMiddleware()`.
- `Summarization` subclasses declare the provider parameter of `summarizeHistory()` and `generateSummary()`.
