# Upgrade: Agent middleware read AgentResources and edit AgentState::$request

## Summary

In 3.x an agent middleware implemented `NeuronAI\Workflow\Middleware\WorkflowMiddleware`. It checked `$state instanceof AgentState`, read `$state->getChatHistory()`, and changed an inference by editing the `AIInferenceEvent`: its instructions string, its tools array, its messages and `maxRetries`.

In 4.x `AIInferenceEvent` is an empty routing event, and `ToolCallEvent` holds only `$toolCallMessage`. The data lives in two places:

- The run's working request is `$state->request`, a `NeuronAI\Agent\InferenceRequest`. It holds `instructions` (a `SystemMessage`), `messages` and `options`.
- The segment's collaborators are in `NeuronAI\Agent\AgentResources`: `history`, `tools` (a `NeuronAI\Tools\ToolRegistry`), `provider` and `instructions`.

An agent middleware now extends `NeuronAI\Agent\Middleware\AgentMiddleware` and receives both in typed hooks.

| 3.x | 4.x |
|---|---|
| `class X implements WorkflowMiddleware` with `before()` / `after()` and `$state instanceof AgentState` checks | `class X extends AgentMiddleware` with `beforeAgentNode()` / `afterAgentNode()` (`before()` and `after()` are final) |
| `$event->instructions`, `$event->getMessages()`, `$event->maxRetries` | `$state->request->instructions`, `$state->request->messages`, `$state->request->options->maxRetries` |
| `$event->tools` | `$resources->tools` |
| `$state->getChatHistory()` | `$resources->history` |
| `$toolCallEvent->inferenceEvent` | `$state->request` |
| a middleware that adds tools, registered with `addMiddleware(ChatNode::class, ...)` | registered with `addGlobalMiddleware(...)` |
| `ToolSearchMiddleware` / `ToolSearchTool`: default `topN` is 10 | default is 5: pass `topN: 10` to keep 3.x results |
| `Summarization::summarizeHistory(AgentState $state, array $messages)`, `generateSummary(array $messages): string` | `summarizeHistory(ChatHistory $chatHistory, array $messages, AIProviderInterface $provider)`, `generateSummary(AIProviderInterface $provider, array $messages): ?string` |

Unmigrated property writes do not reliably fail. `$event->instructions .= ...` raises an 'Undefined property' warning (Laravel's error handler turns it into an `ErrorException`) and, on PHP 8.2+, a dynamic-property deprecation. `$event->tools[] = ...` raises only the deprecation. 4.x ignores the values either way. PHPStan reports them as undefined properties.

Stored data: this guide changes nothing that is stored. Conversations are read as guide 30 describes. 3.x persisted runs held the 3.x inference event, and 4.x does not read those runs (guide 14).

Not in this guide:

- Workflow middleware that never touches agent data: guide 18, already applied.
- `TodoPlanning`: guide 10, already applied.
- `ToolApproval` registrations and subclasses: leave them for guide 28.
- `FakeMiddleware` in tests: guide 56.
- `StreamingNode::class` middleware keys, node constructors and `ChatNode::inference()`: guide 26 migrated them.

## What to Search For

Run from the application root:

```bash
# 1. Middleware classes and their registrations
grep -rnE 'WorkflowMiddleware|extends +(AgentMiddleware|Summarization|ToolSearchMiddleware|ToolSearchTool)([^A-Za-z0-9_]|$)|add(Global)?Middleware\(|function +(global)?[mM]iddleware\(' --include='*.php' --exclude-dir=vendor .

# 2. Agent events and start-event hooks
grep -rnE 'AIInferenceEvent|ToolCallEvent|AgentStartEvent|inferenceEvent|getStartEvent\(|function +startEvent\(' --include='*.php' --exclude-dir=vendor .

# 3. 3.x event and state reads, in the files that use middleware or agent events
grep -rlE 'WorkflowMiddleware|AgentMiddleware|AIInferenceEvent|ToolCallEvent|AgentStartEvent' --include='*.php' --exclude-dir=vendor . \
  | xargs grep -nHE -- '->(instructions|tools|maxRetries|inferenceEvent)([^A-Za-z0-9_(]|$)|->(getMessages|setMessages|getChatHistory)\(|\$state->getMessage\(|instanceof +StopEvent' \
  | grep -vE -- '\$resources->|->request->'

# 4. Built-in agent middleware and their subclasses
grep -rnE 'Summarization|ToolSearchMiddleware|ToolSearchTool|discoveredTools|defaultSearch|function +(summarizeHistory|generateSummary|extractDiscoveredTools|getToolNames|hasToolSearchTool)\(' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:

- For every middleware class found by search 1, open the class. Skip `ToolApproval`, its subclasses (guide 28) and `FakeMiddleware` (guide 56). A class is an agent middleware when it does any of these:
  - reads or edits an `AIInferenceEvent` or `ToolCallEvent`;
  - reads `$state->getChatHistory()` or `$state->getMessage()`;
  - checks `$state instanceof AgentState`.

  Apply Case 1 or Case 2 to it, then Case 3 to its body and Case 4 to its registrations. Other middleware classes are guide 18's. If one of them still declares the 3.x three-parameter `before()` / `after()`, give it the four-parameter signatures shown in Case 2.
- Find every registration of each agent middleware: `addMiddleware()` and `addGlobalMiddleware()` calls, including through a variable that holds the instance, and entries in the `middleware()` and `globalMiddleware()` hooks.
- Search 3 leaves out lines on `$resources` and `$state->request`, which are 4.x code. It still lists other methods with the same names, such as `$history->getMessages()` or methods of app classes. Keep only hits on a 3.x agent event, on an `AgentState`, or on the `$result` of an `after()` hook.
- Hits in nodes of the app (search 2 and 3): apply Case 3 to the body and Case 5 to event construction.
- Search 4: Case 6 (`ToolSearchMiddleware`, `ToolSearchTool`) and Case 7 (`Summarization`).

The `before()` / `after()` methods may already have a fourth `WorkflowResources $resources` parameter. The conversion is the same.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: A middleware that works on agent data becomes an `AgentMiddleware`

Before (3.x):

```php
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

class ReportingTools implements WorkflowMiddleware
{
    public function __construct(protected Metrics $metrics)
    {
    }

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if (!$event instanceof AIInferenceEvent || !$state instanceof AgentState) {
            return;
        }

        $event->instructions .= "\n\nUse the reporting tools for numbers.";
        $event->tools[] = new ReportTool();

        $this->metrics->gauge('history.size', count($state->getChatHistory()->getMessages()));
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        if ($result instanceof StopEvent) {
            $this->metrics->increment('answers');
        }
    }
}

$agent->addMiddleware(ChatNode::class, new ReportingTools($metrics));
```

After (4.x):

```php
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Workflow\Events\Event;

class ReportingTools extends AgentMiddleware
{
    protected const INSTRUCTIONS = 'Use the reporting tools for numbers.';

    public function __construct(protected Metrics $metrics)
    {
    }

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
    {
        $resources->tools->add(new ReportTool());

        if (!$event instanceof AIInferenceEvent) {
            return;
        }

        if (!$state->request->instructions->contains(self::INSTRUCTIONS)) {
            $state->request->instructions->addContent(new SystemContent(self::INSTRUCTIONS));
        }

        $this->metrics->gauge('history.size', count($resources->history->getMessages()));
    }

    protected function afterAgentNode(AgentNodeInterface $node, Event $result, AgentState $state, AgentResources $resources): void
    {
        if ($result instanceof AgentOutputEvent) {
            $this->metrics->increment('answers');
        }
    }
}

$agent->addGlobalMiddleware(new ReportingTools($metrics));
```

Steps:

1. Replace `implements WorkflowMiddleware` with `extends AgentMiddleware`. Keep any other interface in an `implements` clause. If the class already extends another class, use Case 2 instead.
2. Move the body of `before()` into `protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void`.
3. Move the body of `after()` into `protected function afterAgentNode(AgentNodeInterface $node, Event $result, AgentState $state, AgentResources $resources): void`.
4. Delete `before()` and `after()`: they are final in `AgentMiddleware`, and redeclaring them is a fatal error. Do not declare a hook whose body would be empty.
5. Delete the `$state instanceof AgentState` checks, because the hooks receive an `AgentState`. Keep the event checks, such as `$event instanceof AIInferenceEvent`.
6. Rewrite the body with Case 3.
7. Imports: add `NeuronAI\Agent\Middleware\AgentMiddleware`, `NeuronAI\Agent\Nodes\AgentNodeInterface`, `NeuronAI\Agent\AgentResources` and `NeuronAI\Agent\AgentState`. Remove `WorkflowMiddleware`, `NodeInterface` and `WorkflowState` when nothing else uses them.
8. Fix the registration with Case 4.

The typed hooks run only for nodes that implement `NeuronAI\Agent\Nodes\AgentNodeInterface`:

- `ChatNode` and `StructuredOutputNode` (both `InferenceNode`);
- `ToolNode`, `ParallelToolNode` and `AwaitToolResultsNode`;
- RAG's `PreProcessNode` and `ConversationIngestionNode`.

If the middleware was registered on a node class of the app, add `implements AgentNodeInterface` to that node. If it must also run for other nodes, use Case 2. Such nodes include every node of a workflow, and RAG's `RetrievalNode`, `PostProcessNode` and `InstructionsNode`.

### Case 2: A middleware that must also run for non-agent nodes stays a `WorkflowMiddleware`

Keep `implements WorkflowMiddleware`. Use the 4.x signatures, with the fourth `WorkflowResources $resources` parameter, and read agent data only after checking both the state and the resources.

Before (3.x):

```php
use NeuronAI\Agent\AgentState;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

class NodeLogger implements WorkflowMiddleware
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        $context = ['node' => $node::class];

        if ($state instanceof AgentState) {
            $context['messages'] = count($state->getChatHistory()->getMessages());
        }

        $this->logger->info('Node started', $context);
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;

class NodeLogger implements WorkflowMiddleware
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function before(NodeInterface $node, Event $event, WorkflowState $state, WorkflowResources $resources): void
    {
        $context = ['node' => $node::class];

        if ($state instanceof AgentState && $resources instanceof AgentResources) {
            $context['messages'] = count($resources->history->getMessages());
        }

        $this->logger->info('Node started', $context);
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state, WorkflowResources $resources): void
    {
    }
}
```

Rewrite the rest of the body with Case 3.

### Case 3: Rewrite event and state access

This applies inside agent middleware and inside nodes. In a node, `$resources` is the `AgentResources $resources` third `__invoke()` parameter. Guide 26 added it.

| 3.x | 4.x |
|---|---|
| `$state->getChatHistory()` | `$resources->history` |
| `$state->getMessage()` (the history's last message) | `$resources->history->getLastMessage()` |
| `$event->instructions .= $text;` | `if (!$state->request->instructions->contains($text)) { $state->request->instructions->addContent(new SystemContent($text)); }` |
| `$event->instructions = $text;` | `$state->request->instructions = new SystemMessage($text);` |
| reading `$event->instructions`, `str_contains($event->instructions, $text)` | `$state->request->instructions->getContent()`, `$state->request->instructions->contains($text)` |
| `$event->tools[] = $tool;` | `$resources->tools->add($tool);` |
| reading `$event->tools` | `$resources->tools->all()`, or `$resources->tools->find($name)` for one tool |
| `$event->tools = array_filter(...)` to drop tools | `$resources->tools->remove($name);` for each tool to drop |
| `$event->maxRetries` | `$state->request->options->maxRetries` |
| `$event->getMessages()` | `$state->request->messages` |
| `$event->setMessages(...$messages);` | `$state->request->messages = $messages;` |
| `$toolCallEvent->inferenceEvent` | `$state->request` |
| `$toolCallEvent->toolCallMessage` | unchanged |
| `$event instanceof AgentStartEvent`, meant to match inference events | `$event instanceof AIInferenceEvent` |
| `$result instanceof StopEvent` in `after()` for `ChatNode`, `StreamingNode` or `StructuredOutputNode` | `$result instanceof AgentOutputEvent` |
| `$result->getMessages()` in `after()` for `ToolNode` | `$state->request->messages` |

Imports: `NeuronAI\Chat\Messages\ContentBlocks\SystemContent`, `NeuronAI\Chat\Messages\SystemMessage`, `NeuronAI\Agent\Events\AgentOutputEvent`.

Rules:

- **Instructions.** `$state->request` lasts for the whole run, including the tool loop and continuations after a pause. Add a text once, with the `contains()` guard. Drop a leading `"\n\n"`: each block is rendered separated from the previous one by a blank line.
- **Tools.** `$resources->tools` lasts one execution segment. Add the tools on every call; `add()` ignores a tool whose name is already registered. A toolkit is not accepted: add each tool of `$toolkit->tools()`.
- **Guard.** Guard every read or write of `$state->request` with `$event instanceof AIInferenceEvent`, which also matches `StructuredInferenceEvent`, or with `isset($state->request)`. Before RAG's `PreProcessNode` the request does not exist yet, and reading it throws.
- **History.** The history holds only committed messages. The messages waiting for the next inference are in `$state->request->messages`: the new user message, or the last tool call and its results. They join the history after that inference succeeds. The conversation the next inference sends is `[...$resources->history->getMessages(), ...$state->request->messages]`.

Other history changes:

- `ChatHistoryInterface` type declarations and `getLastMessage() === false` checks: guide 32.
- Tool calls inside messages (`getToolCalls()`): guide 4, already applied.

### Case 4: Register a tool-adding middleware globally

A middleware that adds tools to `$resources->tools` must run before every agent node, not only before the inference node. A continuation after an approval starts at `ToolNode`, which must find the tool. Otherwise it fails with `ToolException: The tool ... is not registered on this agent`.

Before (3.x):

```php
$agent->addMiddleware(ChatNode::class, new ReportingTools($metrics));

// or, in the agent class
protected function middleware(): array
{
    return [
        ChatNode::class => new ReportingTools($this->metrics),
    ];
}
```

After (4.x):

```php
$agent->addGlobalMiddleware(new ReportingTools($metrics));

// or, in the agent class
protected function globalMiddleware(): array
{
    return [
        new ReportingTools($this->metrics),
    ];
}
```

1. Replace every registration of the middleware with `addGlobalMiddleware(...)`, or with an entry in the `globalMiddleware()` hook. When it shares an array with other middleware, move only its element. Delete a `middleware()` hook that ends up empty, and the node imports that become unused.
2. Ask the developer when both of these hold:
   - its node key did not cover every inference mode: `ChatNode::class` covers `chat()` and `stream()`, `StructuredOutputNode::class` covers `structured()`, and `InferenceNode::class` covers all of them;
   - the app runs the agent in a mode the key left out.

   Ask: "Should `structured()` runs of this agent get these tools too?" Ask about `chat()` / `stream()` runs instead when the key was `StructuredOutputNode::class`. If the answer is no, return early at the top of `beforeAgentNode()`:

   ```php
   // tools only for chat() and stream() runs
   if (isset($state->request) && $state->request->options->outputClass !== null) {
       return;
   }
   ```

   For `structured()` runs only, use `if (!isset($state->request) || $state->request->options->outputClass === null) { return; }`.
3. A middleware that only edits instructions, messages or the history keeps its registration.

### Case 5: Nodes and agent hooks that build or read agent events

- **A node that sends the run back to the model.** It used to return `new AIInferenceEvent($instructions, $tools)`, or `$toolCallEvent->inferenceEvent` after `setMessages(...)`. Set `$state->request->messages` instead, and `$state->request->instructions` if the code changed them. Then return `AIInferenceEvent::fromRequest($state->request)`, which returns a `StructuredInferenceEvent` during `structured()` runs. Tools the old code passed in the event move to `tools()`, or to a middleware that adds them in `beforeAgentNode()` whenever the node's condition holds (for example a flag the node stores with `$state->set()`), registered globally as in Case 4. A tool added with `$resources->tools->add()` inside the node lasts only the current execution segment: after an approval pause, `ToolNode` fails with `ToolException: The tool ... is not registered on this agent`. Positional arguments passed to `new AIInferenceEvent(...)` are silently ignored in 4.x. Named arguments (`instructions:`, `tools:`, as the 3.x RAG `InstructionsNode` used) throw `Error: Unknown named parameter $instructions`.

  Before (3.x):

  ```php
  $next = $toolCallEvent->inferenceEvent;
  $next->setMessages(new UserMessage('Try again with a shorter answer.'));
  return $next;
  ```

  After (4.x):

  ```php
  $state->request->messages = [new UserMessage('Try again with a shorter answer.')];
  return AIInferenceEvent::fromRequest($state->request);
  ```

- **`new ToolCallEvent($message, $event)`** becomes `new ToolCallEvent($message)`.
- **`getMessages()` / `setMessages(...)` on an `AgentStartEvent`** become the public array `$event->messages`.
- **An Agent subclass that overrides `startEvent()` to return `new AIInferenceEvent(...)`.** Delete the override: `AIInferenceEvent` is no longer an `AgentStartEvent`, and the default returns `new AgentStartEvent()`. Add its extra instructions and tools from a middleware (Case 1), or return the tools from `tools()`. Messages come from the `chat()`, `stream()` and `structured()` arguments.
- **`$agent->getStartEvent()->getMessages()`.** In 3.x it returned the messages of the last `chat()` or `stream()` call. 4.x `getStartEvent()` returns a new `AgentStartEvent` without them. Keep a reference to the messages the app passes to `chat()` instead.
- **A plain Workflow of agent nodes started with `setStartEvent(new AIInferenceEvent(...))`**: guide 18 migrated it.

### Case 6: `ToolSearchMiddleware` and `ToolSearchTool`

Before (3.x):

```php
$agent->addMiddleware(ChatNode::class, new ToolSearchMiddleware($pool));
```

After (4.x):

```php
$agent->addGlobalMiddleware(new ToolSearchMiddleware($pool, topN: 10));
```

1. Register it globally, as in Case 4. This applies to an `addMiddleware()` call or a `middleware()` hook entry.
2. Where `topN` was not passed, pass `topN: 10` to keep the 3.x number of results. The 4.x default is 5. Do the same for a `ToolSearchTool` built directly.
3. In a `ToolSearchMiddleware` subclass:
   - a `before()` or `after()` override moves into `beforeAgentNode()`, which first calls `parent::beforeAgentNode($node, $event, $state, $resources)`;
   - `$this->hasToolSearchTool($event->tools)` becomes `$resources->tools->find('tool_search') !== null`;
   - `$this->extractDiscoveredTools($result)` becomes `$this->discoverFromMessages($state->request->messages)`;
   - `$this->getToolNames($tools)` is removed: map `$resources->tools->all()` to `getName()`.
4. In a `ToolSearchTool` subclass, an override of `protected function defaultSearch(string $query): array` becomes `public function search(string $query): array`. Calls to `parent::defaultSearch()` become `parent::search()`.
5. `$tool->discoveredTools()` is removed. Use `$tool->search($query)` with the query of that call.

Before (3.x):

```php
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\ToolSearchMiddleware;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

class AuditedToolSearch extends ToolSearchMiddleware
{
    public function __construct(array $toolPool, protected Audit $audit)
    {
        parent::__construct($toolPool);
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        parent::after($node, $result, $state);

        if ($result instanceof AIInferenceEvent && $this->hasToolSearchTool($result->tools)) {
            $this->audit->record($this->getToolNames($result->tools));
        }
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\ToolSearchMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Events\Event;

class AuditedToolSearch extends ToolSearchMiddleware
{
    public function __construct(array $toolPool, protected Audit $audit)
    {
        parent::__construct($toolPool, topN: 10);
    }

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
    {
        parent::beforeAgentNode($node, $event, $state, $resources);

        if ($event instanceof AIInferenceEvent && $resources->tools->find('tool_search') !== null) {
            $this->audit->record(array_map(
                fn (ToolInterface|ProviderToolInterface $tool): ?string => $tool->getName(),
                $resources->tools->all(),
            ));
        }
    }
}
```

The 3.x `after()` ran after `ToolNode` had added the discovered tools. In 4.x the parent `beforeAgentNode()` adds them before the next inference, so the subclass logic runs after the parent call.

### Case 7: `Summarization`

- `new Summarization($provider, $maxTokens, $messagesToKeep, $summaryPrompt)` needs no change. Keep the provider argument: without it, the agent's own provider writes the summaries.
- A registration that only summarizes keeps its node key.
- In a subclass:
  - a `before()` override becomes `beforeAgentNode()`, and an `after()` override becomes `afterAgentNode()`. Call the parent hook where the 3.x code called the parent method.
  - `summarizeHistory(AgentState $state, array $messages): void` becomes `summarizeHistory(ChatHistory $chatHistory, array $messages, AIProviderInterface $provider): void`. In the body, `$state->getChatHistory()` becomes `$chatHistory`, `$this->provider` becomes `$provider`, and `$this->generateSummary($oldMessages)` becomes `$this->generateSummary($provider, $oldMessages)`. If the override does not call `parent::summarizeHistory()`, add `if ($summary === null) { return; }` right after that call, before touching `$chatHistory`.
  - `generateSummary(array $messages): string` becomes `generateSummary(AIProviderInterface $provider, array $messages): ?string`. In the body, `$this->provider` becomes `$provider`.
  - Start the provider call in `generateSummary()` with `->systemPrompt(...)->setTools([])`, as the 4.x parent does: the provider can be the agent's own instance, which still carries the agent's instructions and tools. End it with `->message()->getContent()`. That is the guide 41 change, written here so the override compiles.
  - In the parent `summarizeHistory()`, a `null` from `generateSummary()` skips summarizing this time and leaves the history as it is. The 3.x placeholder summary written on a provider failure no longer exists.

Before (3.x):

```php
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;

class TicketSummarization extends Summarization
{
    public function __construct(AIProviderInterface $provider, protected LoggerInterface $logger)
    {
        parent::__construct($provider);
    }

    protected function summarizeHistory(AgentState $state, array $messages): void
    {
        parent::summarizeHistory($state, $messages);

        $this->logger->info('History summarized', ['kept' => count($state->getChatHistory()->getMessages())]);
    }

    protected function generateSummary(array $messages): string
    {
        return $this->provider
            ->systemPrompt('You summarize support tickets.')
            ->chat(new UserMessage($this->formatMessagesForSummarization($messages)))
            ->getContent();
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;

class TicketSummarization extends Summarization
{
    public function __construct(AIProviderInterface $provider, protected LoggerInterface $logger)
    {
        parent::__construct($provider);
    }

    protected function summarizeHistory(ChatHistory $chatHistory, array $messages, AIProviderInterface $provider): void
    {
        parent::summarizeHistory($chatHistory, $messages, $provider);

        $this->logger->info('History summarized', ['kept' => count($chatHistory->getMessages())]);
    }

    protected function generateSummary(AIProviderInterface $provider, array $messages): ?string
    {
        return $provider
            ->systemPrompt('You summarize support tickets.')
            ->setTools([])
            ->chat(new UserMessage($this->formatMessagesForSummarization($messages)))
            ->message()
            ->getContent();
    }
}
```

## Checklist

- No agent middleware implements `WorkflowMiddleware` directly, unless Case 2 applies. Classes extending `AgentMiddleware`, `Summarization` or `ToolSearchMiddleware` declare no `before()` or `after()`.
- No `->instructions`, `->tools`, `->maxRetries`, `->getMessages()`, `->setMessages()` or `->inferenceEvent` remains on an agent event, in middleware or in nodes. No `getChatHistory()` or `getMessage()` remains on an `AgentState` in middleware.
- Request reads and edits are guarded with `$event instanceof AIInferenceEvent` or `isset($state->request)`. Instruction text is added once, behind a `contains()` guard.
- Every middleware that adds tools is registered with `addGlobalMiddleware()` or the `globalMiddleware()` hook. The developer was asked about modes where Case 4 step 2 applies.
- `after()` checks for the final answer use `AgentOutputEvent`, not `StopEvent`.
- No `new AIInferenceEvent(` with arguments and no two-argument `new ToolCallEvent(` remains. No Agent subclass overrides `startEvent()` to return an `AIInferenceEvent`.
- Every `ToolSearchMiddleware` or `ToolSearchTool` built without `topN` now passes `topN: 10`. No `defaultSearch()`, `discoveredTools()`, `extractDiscoveredTools()`, `getToolNames()` or `hasToolSearchTool()` remains.
- `Summarization` subclasses use `summarizeHistory(ChatHistory $chatHistory, array $messages, AIProviderInterface $provider)` and `generateSummary(AIProviderInterface $provider, array $messages): ?string`.
- Re-run searches 2 to 4: every remaining hit is 4.x code. PHPStan reports no undefined property or method on `AIInferenceEvent`, `ToolCallEvent`, `AgentStartEvent` or `AgentState`. The leftover writes may not fail at runtime, so these checks are the reliable way to find them.
