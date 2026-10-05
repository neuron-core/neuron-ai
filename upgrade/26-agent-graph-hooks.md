# Upgrade: Agent and RAG graph hooks, node collaborators and built-in nodes

## Summary

In 3.x each Agent verb passed its inference node to `compose()`, which added a `ToolNode`, and RAG added its retrieval chain through `ragNodes()`. Nodes received the provider, instructions and tools as constructor arguments and read the chat history from `AgentState`. In 4.x the whole graph comes from `nodes()`: `entryNodes()`, then `ChatNode`, `StructuredOutputNode`, `ToolNode` or `ParallelToolNode` (as `parallelToolCalls()` selects) and `AwaitToolResultsNode`, then `exitNodes()`. The graph is built again for every execution segment: every run, and every continuation after a pause. Nodes read the agent's collaborators from `NeuronAI\Agent\AgentResources`, a third `__invoke()` parameter.

| 3.x | 4.x |
|---|---|
| `protected function compose(array\|Node $nodes): void` override | `nodes()` returning `[...parent::nodes(), ...]`, or mapping over it to swap a node |
| `protected function ragNodes(): array` (RAG) | `protected function entryNodes(): array` |
| `nodes()` override on an Agent or RAG, merged with the agent's nodes | `nodes()` is the whole graph: spread `parent::nodes()` |
| `new ChatNode($provider)`, `new StreamingNode($provider)` | `new ChatNode()`. It streams when the run asks for it |
| `new StructuredOutputNode($provider, $class, $maxTries, $extractor)` | `new StructuredOutputNode($extractor)`. The class and retries come from `structured($messages, $class, $maxRetries)` |
| `new InstructionsNode($instructions, $tools)` | `new InstructionsNode()` |
| `StreamingNode::class` as a middleware key | `ChatNode::class` (or `InferenceNode::class` for every inference mode) |
| `ChatNode::inference(AIInferenceEvent $event, array $messages): Message` | `chat(AgentResources $resources, InferenceRequest $request, array $messages): ProviderResponse`, and `stream(...)` for streamed runs |
| `ToolNode::executeTools(ToolCallMessage, AgentState): Generator` | `executeLocalTools(array $calls, string $messageId, AgentState $state, ToolRegistry $tools): Generator` |
| `ToolNode::executeSingleTool(ToolInterface, AgentState): void` | `executeSingleTool(ToolCall $call, int $index, AgentState $state, ToolRegistry $tools): void` |
| `ToolNode::handleError(Throwable, ToolInterface): void` | `handleError(Throwable $e, ToolCall $call): void` |
| `PreProcessNode::__invoke(AgentStartEvent, AgentState): AIInferenceEvent\|QueryPreProcessedEvent` | `__invoke(AgentStartEvent, AgentState, AgentResources): QueryPreProcessedEvent` |
| Provider, instructions, tools passed to a node's constructor; `$state->getChatHistory()` in a node | `$resources->provider`, `->instructions`, `->tools`, `->history` |
| `addToChatHistory($state, $messages)`, `pendingConversation($state, $inbound)` | `addToChatHistory($resources->history, $state, $messages, 'history.<name>')`, `pendingConversation($resources->history, $inbound)` |
| `resolveProvider()` (public, overridable) | `getProvider()` (final); overrides move to `provider()` |
| `bootstrapTools()`, `$toolsBootstrapCache` | Removed |
| `NeuronAI\RAG\Events\QueryPreProcessEvent` | Removed |

Stored data is not affected: nodes are never persisted.

### Leave these to their guides

- **Agent event payloads: guide 27.** `$event->instructions`, `->tools`, `->maxRetries`, `getMessages()`/`setMessages()`, `new AIInferenceEvent(...)`, `new ToolCallEvent($message, $event)`, `->inferenceEvent` and `startEvent()` overrides, in nodes and middleware. Agent middleware as a whole is guide 27 too.
- **Provider return values: guide 41.** The After code below already calls `->message()` on the `ProviderResponse` a provider returns.
- **Observability: guide 46.** `$this->emit('name', $event)` calls and the `NeuronAI\Observability\Events\*` classes used inside node subclasses.
- **Constructing `ToolCallChunk`: guide 39.**
- **A Workflow of your own (not an Agent or RAG) that registers agent nodes, and middleware registered by instance: guide 18**, already applied. `FakeMiddleware`: guide 56.
- **Overrides of `chat()`, `stream()` and `structured()`: guide 23**, already applied.
- **Classes implementing `AgentInterface` directly, and other final or removed Agent members: guide 57.**

## What to Search For

Run from the application root:

```bash
# 1. Graph hooks, and compose() calls still left in a method
grep -rnE 'compose\(|function (ragNodes|nodes)\(' --include='*.php' --exclude-dir=vendor .

# 2. Built-in nodes: constructions, subclasses, middleware keys, imports
grep -rnE '\b(ChatNode|StreamingNode|StructuredOutputNode|InferenceNode|ToolNode|ParallelToolNode|PreProcessNode|InstructionsNode|QueryPreProcessEvent)\b' --include='*.php' --exclude-dir=vendor .

# 3. Overrides of the changed node hooks
grep -rnE 'function (inference|executeTools|executeSingleTool|handleError|pendingConversation)\(' --include='*.php' --exclude-dir=vendor .

# 4. Agent collaborators read through the agent
grep -rnE '[rR]esolveProvider|function getProvider\(|bootstrapTools\(|toolsBootstrapCache|(resolve|get)Instructions\(' --include='*.php' --exclude-dir=vendor .

# 5. History and response read or written through the state
grep -rnE -e '\$state->(getChatHistory|getMessage)\(' -e 'addToChatHistory\(|pendingConversation\(' --include='*.php' --exclude-dir=vendor .

# 6. Nodes registered on an agent instance
grep -rnE -e '->addNodes?\(' --include='*.php' --exclude-dir=vendor .
```

Follow the hits:

- Searches 1 and 6: keep the hits in classes extending `NeuronAI\Agent\Agent` or `NeuronAI\RAG\RAG` (directly or through another app class), and `addNode()` calls on their instances. Hits on a plain Workflow are not part of this guide.
- Search 2: open every class that extends one of these nodes, every `new` of them, and every middleware registration (`addMiddleware(`, `middleware()` hook) that uses them as keys.
- Search 3: `handleError` and `inference` are common names. Keep only the methods declared in subclasses of the built-in nodes.
- Search 4: `(resolve|get)Instructions(` belongs here only where it is passed to a node constructor; guide 24 handled the other calls.
- Search 5: keep the hits inside node classes. Hits inside middleware belong to guide 27.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: A `compose()` override

Before (3.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\Node;

class ReportAgent extends Agent
{
    protected function compose(array|Node $nodes): void
    {
        parent::compose([
            ...(is_array($nodes) ? $nodes : [$nodes]),
            new AuditNode(),
        ]);
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;

class ReportAgent extends Agent
{
    protected function nodes(): array
    {
        return [...parent::nodes(), new AuditNode()];
    }
}
```

1. Delete the `compose()` override. `parent::compose()` no longer exists, and an override that does not call the parent would silently never run.
2. Move the nodes it added into `nodes()`, after `...parent::nodes()`. In a RAG subclass, a node of the retrieval chain goes in `entryNodes()` after `...parent::entryNodes()` instead.
3. Where the override swapped a built-in node (the `$nodes` argument, or the `ToolNode`), use Case 4. Delete constructions that only registered a built-in node again: `parent::nodes()` already contains them.
4. A `$this->compose(...)` call still left in a method: register its nodes the same way and delete the call.
5. Migrate the constructor arguments of the nodes as Cases 5 and 9 describe.

One node per event class, as in 3.x: a second handler fails with `Node for event X already exists`. The hooks run at the start of every segment, so they only build nodes and have no side effects.

### Case 2: A `ragNodes()` override

Rename it to `entryNodes()` and call `parent::entryNodes()` instead of `parent::ragNodes()`. When it lists the retrieval chain itself (to replace one of its nodes), keep the arguments guides 19 and 25 set, and build `InstructionsNode` without arguments.

Before (3.x, processor arguments as guide 25 left them):

```php
use NeuronAI\RAG\Nodes\InstructionsNode;
use NeuronAI\RAG\Nodes\PostProcessNode;
use NeuronAI\RAG\Nodes\PreProcessNode;
use NeuronAI\RAG\RAG;

class DocsRAG extends RAG
{
    protected function ragNodes(): array
    {
        return [
            new PreProcessNode($this->preProcessors ?? $this->preProcessors()),
            new HybridRetrievalNode($this->resolveRetrieval()),
            new PostProcessNode($this->postProcessors ?? $this->postProcessors()),
            new InstructionsNode($this->resolveInstructions(), $this->bootstrapTools()),
        ];
    }
}
```

After (4.x):

```php
use NeuronAI\RAG\Nodes\InstructionsNode;
use NeuronAI\RAG\Nodes\PostProcessNode;
use NeuronAI\RAG\Nodes\PreProcessNode;
use NeuronAI\RAG\RAG;

class DocsRAG extends RAG
{
    protected function entryNodes(): array
    {
        return [
            new PreProcessNode($this->preProcessors ?? $this->preProcessors()),
            new HybridRetrievalNode($this->resolveRetrieval()),
            new PostProcessNode($this->postProcessors ?? $this->postProcessors()),
            new InstructionsNode(),
        ];
    }
}
```

The chain must start with a node handling `NeuronAI\Agent\Events\AgentStartEvent` (the RAG default is `PreProcessNode`) and end with one returning an `AIInferenceEvent` (`InstructionsNode`).

### Case 3: An existing `nodes()` override on an Agent or RAG

In 3.x the nodes it returned were added to the agent's nodes. In 4.x `nodes()` is the whole graph, and returning only the app's nodes fails with `No nodes found that handle NeuronAI\Agent\Events\AgentStartEvent`.

Before (3.x):

```php
protected function nodes(): array
{
    return [new AuditNode()];
}
```

After (4.x):

```php
protected function nodes(): array
{
    return [...parent::nodes(), new AuditNode()];
}
```

### Case 4: Replacing a built-in node

Swap it in the parent's list. Adding a subclass next to the original registers a second handler for the same event and fails.

Before (3.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Workflow\Node;

class SupportAgent extends Agent
{
    protected function compose(array|Node $nodes): void
    {
        parent::compose(array_map(
            fn (Node $node): Node => $node instanceof ChatNode
                ? new FallbackChatNode($this->resolveProvider(), $this->fallbackProvider())
                : $node,
            is_array($nodes) ? $nodes : [$nodes],
        ));
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Workflow\Node;

class SupportAgent extends Agent
{
    protected function nodes(): array
    {
        return array_map(
            fn (Node $node): Node => $node instanceof ChatNode ? new FallbackChatNode($this->fallbackProvider()) : $node,
            parent::nodes(),
        );
    }
}
```

- Test `instanceof ChatNode` for a 3.x ChatNode or StreamingNode subclass, and `instanceof StructuredOutputNode` for a structured output subclass or a custom `JsonExtractor` (`new StructuredOutputNode(new MyExtractor())`).
- For a ToolNode subclass, test `instanceof ToolNode`, which also matches `ParallelToolNode`, and pass the agent's settings: `new AuditedToolNode($this->toolMaxRuns, $this->toolErrorHandler ?? $this->resolveToolErrorHandler())`. If the agent calls `parallelToolCalls(true)`, the subclass extends `ParallelToolNode` and also receives `$this->beforeParallelToolChild, $this->afterParallelToolChild`.
- In a RAG subclass, map over `parent::entryNodes()` in `entryNodes()` to swap a retrieval chain node.
- Combine with Case 1 when the agent also adds nodes: `return [...array_map(...), new AuditNode()];`.

### Case 5: Built-in node constructions and `StreamingNode`

| 3.x | 4.x |
|---|---|
| `new ChatNode($provider)` | `new ChatNode()` |
| `new StreamingNode($provider)` | `new ChatNode()`, or nothing when a `ChatNode` is already registered |
| `new StructuredOutputNode($provider, Output::class, $maxTries, $extractor)` | `new StructuredOutputNode($extractor)`, or `new StructuredOutputNode()` for the default extractor. `null` is no longer accepted |
| `new InstructionsNode($instructions, $tools)` | `new InstructionsNode()` |
| `new ToolNode(...)`, `new ParallelToolNode(...)`, `new PreProcessNode(...)`, `new RetrievalNode(...)`, `new PostProcessNode(...)` | Unchanged |

The removed arguments need no replacement: `stream()` or `chat($messages, stream: true)` makes `ChatNode` stream, and `structured($messages, Output::class, $maxRetries)` gives `StructuredOutputNode` the class and retries.

`StreamingNode` no longer exists. Delete its imports, and retarget middleware keys.

Before (3.x):

```php
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\StreamingNode;

$agent->addMiddleware([ChatNode::class, StreamingNode::class], new PiiRedaction());

// or in the agent
protected function middleware(): array
{
    return [
        ChatNode::class => [new PiiRedaction()],
        StreamingNode::class => [new PiiRedaction()],
    ];
}
```

After (4.x):

```php
use NeuronAI\Agent\Nodes\ChatNode;

$agent->addMiddleware(ChatNode::class, new PiiRedaction());

// or in the agent
protected function middleware(): array
{
    return [
        ChatNode::class => [new PiiRedaction()],
    ];
}
```

- Replace a `StreamingNode::class` key with `ChatNode::class`. Use `InferenceNode::class` (`NeuronAI\Agent\Nodes\InferenceNode`) when the same middleware was also keyed on `StructuredOutputNode::class`, and remove those keys.
- Remove duplicates: a middleware listed twice for the same node runs twice.
- Middleware keyed on `ChatNode::class` runs for chat and streamed runs alike. Where a middleware was registered for only one of `ChatNode::class` and `StreamingNode::class`, ask the developer whether it should now run in both modes.
- A key left on `StreamingNode::class` fails when the graph is built: `Middleware is registered for 'NeuronAI\Agent\Nodes\StreamingNode', which is not a node class.`
- Guide 27 migrates the middleware classes themselves.

### Case 6: A `ChatNode` or `StreamingNode` subclass

The 3.x `inference()` hook is never called in 4.x. Move it to `chat()`, which serves the same runs (not streamed).

Before (3.x):

```php
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;

class FallbackChatNode extends ChatNode
{
    public function __construct(AIProviderInterface $provider, protected AIProviderInterface $fallback)
    {
        parent::__construct($provider);
    }

    protected function inference(AIInferenceEvent $event, array $messages): Message
    {
        try {
            return parent::inference($event, $messages);
        } catch (ProviderException $e) {
            return $this->fallback
                ->systemPrompt($event->instructions)
                ->setTools($event->tools)
                ->chat(...$messages);
        }
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;

class FallbackChatNode extends ChatNode
{
    public function __construct(protected AIProviderInterface $fallback)
    {
    }

    protected function chat(AgentResources $resources, InferenceRequest $request, array $messages): ProviderResponse
    {
        try {
            return parent::chat($resources, $request, $messages);
        } catch (ProviderException $e) {
            return $this->fallback
                ->systemPrompt($request->instructions)
                ->setTools($resources->tools->all())
                ->chat(...$messages);
        }
    }
}
```

1. Rename `inference(AIInferenceEvent $event, array $messages): Message` to `chat(AgentResources $resources, InferenceRequest $request, array $messages): ProviderResponse`, and `parent::inference($event, $messages)` to `parent::chat($resources, $request, $messages)`.
2. In the body: `$this->provider` becomes `$resources->provider`, `$event->instructions` becomes `$request->instructions` (a `SystemMessage`; `systemPrompt()` accepts it), and `$event->tools` becomes `$resources->tools->all()`.
3. Return the provider's `ProviderResponse` as it comes. A `Message` the method builds itself is returned as `new ProviderResponse($message)` (`NeuronAI\Providers\ProviderResponse`).
4. `ChatNode` has no constructor: remove the provider parameter and the `parent::__construct($provider)` call. App services stay constructor parameters.
5. `inference()` never ran for `stream()` in 3.x, and `chat()` does not run for streamed runs either. If the change must also apply to streamed runs, ask the developer, and override `stream()` as shown next.
6. Only one node handles `AIInferenceEvent`: an app with both a ChatNode subclass and a StreamingNode subclass merges them into one `ChatNode` subclass that overrides `chat()` and `stream()`.

A `StreamingNode` subclass becomes a `ChatNode` subclass that overrides `stream()`:

Before (3.x):

```php
use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Providers\AIProviderInterface;

class TimedStreamingNode extends StreamingNode
{
    public function __construct(AIProviderInterface $provider, protected Metrics $metrics)
    {
        parent::__construct($provider);
    }

    public function __invoke(AIInferenceEvent $event, AgentState $state): Generator|ToolCallEvent
    {
        $start = microtime(true);
        $result = yield from parent::__invoke($event, $state);
        $this->metrics->timing('llm.stream', microtime(true) - $start);

        return $result;
    }
}
```

After (4.x):

```php
use Generator;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\ChatNode;

class TimedChatNode extends ChatNode
{
    public function __construct(protected Metrics $metrics)
    {
    }

    protected function stream(AgentResources $resources, InferenceRequest $request, array $messages): Generator
    {
        $start = microtime(true);
        $response = yield from parent::stream($resources, $request, $messages);
        $this->metrics->timing('llm.stream', microtime(true) - $start);

        return $response;
    }
}
```

`stream()` must yield the provider's chunks and return its `ProviderResponse`, as `parent::stream()` does. Register the subclass with Case 4 (`instanceof ChatNode`).

Other node code that handled the final answer:

- An `__invoke()` override on a `ChatNode` subclass must declare `(AIInferenceEvent $event, AgentState $state, AgentResources $resources): Generator|AgentOutputEvent|ToolCallEvent`. Prefer moving its logic into `chat()`/`stream()`; if it cannot move, ask the developer.
- A node that returned `new StopEvent()` for the final answer, in place of a built-in inference node, returns `new AgentOutputEvent()` (`NeuronAI\Agent\Events\AgentOutputEvent`), so the exit nodes run. It also calls `$state->setResponse($providerResponse)`, otherwise `getMessage()` on the state `chat()` returns is null. Its reads of the event's messages, instructions and tools are guide 27. Prefer a `ChatNode` subclass with a `chat()` override over a node written from scratch.
- A node that must run after the final answer handles `AgentOutputEvent` and returns a `StopEvent`. It replaces `AgentEndNode`: `protected function exitNodes(): array { return [new MyEndNode()]; }`.

### Case 7: A `StructuredOutputNode` subclass

Before (3.x):

```php
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\StructuredOutput\JsonExtractor;

class AuditedStructuredNode extends StructuredOutputNode
{
    public function __construct(AIProviderInterface $provider, string $outputClass, int $maxTries, protected AuditLog $audit)
    {
        parent::__construct($provider, $outputClass, $maxTries, new JsonExtractor());
    }

    protected function processResponse(Message $response, array $schema, string $class): object
    {
        $output = parent::processResponse($response, $schema, $class);
        $this->audit->record($this->outputClass);

        return $output;
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Chat\Messages\Message;

class AuditedStructuredNode extends StructuredOutputNode
{
    public function __construct(protected AuditLog $audit)
    {
        parent::__construct();
    }

    protected function processResponse(Message $response, array $schema, string $class): object
    {
        $output = parent::processResponse($response, $schema, $class);
        $this->audit->record($class);

        return $output;
    }
}
```

- The constructor takes only `JsonExtractor $extractor = new JsonExtractor()`. Pass a custom extractor as `parent::__construct($extractor)`.
- The removed properties: `$this->outputClass` is `$class` in `processResponse()`, or `$state->request->options->outputClass` in `__invoke()`; `$this->maxTries` is `$state->request->options->maxRetries`; `$this->provider` is `$resources->provider`.
- An `__invoke()` override declares `(StructuredInferenceEvent $event, AgentState $state, AgentResources $resources): ToolCallEvent|AgentOutputEvent` (`NeuronAI\Agent\Events\StructuredInferenceEvent`).
- `processResponse()` is unchanged. Register the subclass with Case 4 (`instanceof StructuredOutputNode`).

### Case 8: A `ToolNode` or `ParallelToolNode` subclass

3.x hooks received executable tool objects. 4.x hooks receive `NeuronAI\Tools\ToolCall` data objects and the segment's `NeuronAI\Tools\ToolRegistry`. A 3.x `executeTools()` override is never called, and 3.x `executeSingleTool()`/`handleError()` overrides fail to load.

Before (3.x):

```php
use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ToolInterface;
use Throwable;

class AuditedToolNode extends ToolNode
{
    protected function executeTools(ToolCallMessage $toolCallMessage, AgentState $state): Generator
    {
        foreach ($toolCallMessage->getTools() as $tool) {
            AuditLog::toolCalled($tool->getName(), $tool->getInputs());
        }

        return yield from parent::executeTools($toolCallMessage, $state);
    }

    protected function handleError(Throwable $e, ToolInterface $tool): void
    {
        AuditLog::toolFailed($tool->getName(), $e);

        parent::handleError($e, $tool);
    }
}
```

After (4.x):

```php
use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolRegistry;
use Throwable;

class AuditedToolNode extends ToolNode
{
    protected function executeLocalTools(array $calls, string $messageId, AgentState $state, ToolRegistry $tools): Generator
    {
        foreach ($calls as $call) {
            AuditLog::toolCalled($call->getName(), $call->getInputs());
        }

        return yield from parent::executeLocalTools($calls, $messageId, $state, $tools);
    }

    protected function handleError(Throwable $e, ToolCall $call): void
    {
        AuditLog::toolFailed($call->getName(), $e);

        parent::handleError($e, $call);
    }
}
```

- `executeTools(ToolCallMessage $toolCallMessage, AgentState $state)` becomes `executeLocalTools(array $calls, string $messageId, AgentState $state, ToolRegistry $tools)`. `$calls` is every `ToolCall` of the message, keyed by position; `$messageId` is the message's ID. It yields the stream chunks and returns the settled calls (`array<int, ToolCall>`, same keys) instead of a `ToolResultMessage`: code that post-processed the returned message works on that array.
- `executeSingleTool(ToolInterface $tool, AgentState $state)` becomes `executeSingleTool(ToolCall $call, int $index, AgentState $state, ToolRegistry $tools)`. Call `parent::executeSingleTool($call, $index, $state, $tools)` to run it, then read `$call->getResult()` (check `$call->hasResult()` first).
- `handleError(Throwable $e, ToolInterface $tool)` becomes `handleError(Throwable $e, ToolCall $call)`. Settle a call with `$call->setResult($stringOrToolOutput)`.
- In the bodies, read `getName()`, `getInputs()` and `getCallId()` on the `ToolCall`. The executable tool is `$tools->find($call->getName())` (null when not registered); never call `execute()` on it yourself, the parent runs it.
- An `__invoke()` override declares `(ToolCallEvent $event, AgentState $state, AgentResources $resources): AIInferenceEvent|AwaitToolResultsEvent|Generator`.
- The constructors are unchanged. Register the subclass with Case 4 (`instanceof ToolNode`).

### Case 9: A node built with the agent's collaborators, or reading the history from the state

Before (3.x):

```php
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;

class SummaryNode extends Node
{
    use ChatHistoryHelper;

    public function __construct(
        protected AIProviderInterface $provider,
        protected string $instructions,
    ) {
    }

    public function __invoke(SummaryRequestedEvent $event, AgentState $state): StopEvent
    {
        $messages = $state->getChatHistory()->getMessages();

        $summary = $this->provider
            ->systemPrompt($this->instructions)
            ->chat(...$messages);

        $this->addToChatHistory($state, $summary);

        return new StopEvent();
    }
}

// in the agent's nodes()
new SummaryNode($this->resolveProvider(), $this->resolveInstructions());
```

After (4.x):

```php
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;

class SummaryNode extends Node
{
    use ChatHistoryHelper;

    public function __invoke(SummaryRequestedEvent $event, AgentState $state, AgentResources $resources): StopEvent
    {
        $messages = $resources->history->getMessages();

        $summary = $resources->provider
            ->systemPrompt($resources->instructions)
            ->chat(...$messages)
            ->message();

        $this->addToChatHistory($resources->history, $state, $summary, 'history.summary');

        return new StopEvent();
    }
}

// in the agent's nodes()
new SummaryNode();
```

1. Add `AgentResources $resources` (`NeuronAI\Agent\AgentResources`) as the third `__invoke()` parameter. If guide 18 already added `WorkflowResources $resources`, narrow its type to `AgentResources`.
2. Remove the constructor parameters that received the agent's collaborators, and the arguments at the construction site. Services of the app's own stay constructor dependencies.
3. Read the collaborators from `$resources`:
   - `$this->resolveProvider()` → `$resources->provider` (`AIProviderInterface`).
   - `$this->resolveInstructions()` (or `$this->getInstructions()->getContent()`) → `$resources->instructions`, a `SystemMessage` that already contains the toolkit guidelines. Use `->getContent()` where text is needed.
   - `$this->bootstrapTools()` → `$resources->tools`, a `ToolRegistry`: `->all()` for the array, `->find($name)` for one tool.
   - `$state->getChatHistory()` → `$resources->history` (`NeuronAI\Chat\History\ChatHistory`).
4. `$this->addToChatHistory($state, $messages)` becomes `$this->addToChatHistory($resources->history, $state, $messages, 'history.<name>')`. Give each write in the node its own stable name. The node must extend `NeuronAI\Workflow\Node`.
5. In `ChatNode`/`StructuredOutputNode` subclasses, `$this->pendingConversation($state, $inbound)` becomes `$this->pendingConversation($resources->history, $inbound)`, and an override declares `protected function pendingConversation(ChatHistory $history, array $inbound): array`.
6. Inside a node, `$state->getMessage()` now returns the run's last model response, or null before the first one; in 3.x it returned the last message of the history. Where the history's last message was meant, use `$resources->history->getLastMessage()`.

Agent and RAG provide `AgentResources` to every node of their graph. A node that declares it in a workflow that does not provide them fails when the graph is built (guide 18).

### Case 10: RAG node subclasses and `QueryPreProcessEvent`

Before (3.x):

```php
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\Nodes\PreProcessNode;

class LoggedPreProcessNode extends PreProcessNode
{
    public function __construct(array $preProcessors, protected QueryLog $log)
    {
        parent::__construct($preProcessors);
    }

    public function __invoke(AgentStartEvent $event, AgentState $state): AIInferenceEvent|QueryPreProcessedEvent
    {
        $result = parent::__invoke($event, $state);

        if ($result instanceof QueryPreProcessedEvent) {
            $this->log->record($result->query);
        }

        return $result;
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\RAG\Events\QueryPreProcessedEvent;
use NeuronAI\RAG\Nodes\PreProcessNode;

class LoggedPreProcessNode extends PreProcessNode
{
    public function __construct(array $preProcessors, protected QueryLog $log)
    {
        parent::__construct($preProcessors);
    }

    public function __invoke(AgentStartEvent $event, AgentState $state, AgentResources $resources): QueryPreProcessedEvent
    {
        $result = parent::__invoke($event, $state, $resources);
        $this->log->record($result->query);

        return $result;
    }
}
```

- A `PreProcessNode` override declares the third parameter and returns `QueryPreProcessedEvent`. Call `parent::__invoke($event, $state, $resources)`: it initializes `$state->request`, which inference needs. An override that does not call the parent must do the same (copy it from `vendor/neuron-core/neuron-ai/src/RAG/Nodes/PreProcessNode.php`), and must not write the incoming messages to the history as the 3.x node did: `ChatNode` writes them after the model answers.
- An `InstructionsNode` subclass: `InstructionsNode` has no constructor, so remove the `parent::__construct($instructions, $tools)` call and those parameters. The node no longer writes the documents to the instructions: it adds them to `$state->request->context` (guide 24, Case 12), so an override that edited the instructions to change the documents edits that block instead. Custom formatting of the retrieved documents moves to an override of `protected function buildBlockContent(array $documents): string`. Edits of the returned event's instructions are guide 27.
- Delete references to `NeuronAI\RAG\Events\QueryPreProcessEvent` (the class is removed). 3.x never emitted it, so a node handling it never ran: delete that node too, and tell the developer.
- Register the subclass in `entryNodes()` (Case 2 or Case 4).

### Case 11: Other `resolveProvider()` uses and `getProvider()` collisions

Before (3.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;

class SupportAgent extends Agent
{
    public function resolveProvider(): AIProviderInterface
    {
        return ProviderFactory::make('support');
    }
}

$provider = $agent->resolveProvider();
```

After (4.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;

class SupportAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return ProviderFactory::make('support');
    }
}

$provider = $agent->getProvider();
```

- Calls: `->resolveProvider()` becomes `->getProvider()`, on an agent instance or on `$this` (for example `new Summarization($this->getProvider(), ...)`; guide 27 covers `Summarization`). Inside a node, use `$resources->provider` (Case 9).
- `getProvider()` does not keep what the `provider()` hook returns: without `setAiProvider()`, each call builds a new provider, and each run builds its own. 3.x `resolveProvider()` cached the first one. Where the code configures the returned provider before a run (for example `$agent->resolveProvider()->setHttpClient($client)`) or inspects it after one (for example `$agent->resolveProvider()->assertCallCount(1)` on a `FakeAIProvider` returned by the hook), build the provider once, pass it with `$agent->setAiProvider($provider)`, and use that variable instead of calling `getProvider()`.
- An override of `resolveProvider()` would silently never run, and `getProvider()` is final: move its body into `protected function provider(): AIProviderInterface`. `setAiProvider()` still wins over the hook.
- An app method named `getProvider()` in an Agent or RAG subclass is a fatal error. If it only returned the agent's provider, delete it; otherwise rename it and its call sites.
- `use NeuronAI\Agent\ResolveProvider;` becomes `use NeuronAI\Agent\HandleProvider;`.

### Case 12: `bootstrapTools()` or `$toolsBootstrapCache` outside a node

There is no public replacement. Inside nodes and middleware use `$resources->tools->all()`. Elsewhere, build the list from `getTools()` the way the agent does:

Before (3.x):

```php
use NeuronAI\Tools\ToolInterface;

$names = array_map(fn (ToolInterface $tool): string => $tool->getName(), $agent->bootstrapTools());
```

After (4.x):

```php
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

$tools = [];
foreach ($agent->getTools() as $tool) {
    if ($tool instanceof ToolkitInterface) {
        $tools = [...$tools, ...array_values(array_filter($tool->tools(), fn (ToolInterface $inner): bool => $inner->isVisible()))];
    } elseif ($tool->isVisible()) {
        $tools[] = $tool;
    }
}

$names = array_map(fn (ToolInterface|ProviderToolInterface $tool): ?string => $tool->getName(), $tools);
```

Delete reads, writes and resets of `$this->toolsBootstrapCache`: tools are resolved again for every segment.

### Case 13: Nodes registered on an agent instance

Nodes passed to `addNode()`/`addNodes()` are cloned at the start of every segment, and nodes returned by the hooks are built again. In 3.x the same instances served every run of the agent instance, so a value a node stored in its own properties carried over; now it stays on a copy.

Before (3.x, after guide 23):

```php
$collector = new TranscriptNode();
$agent->addNode($collector);
$agent->chat(new UserMessage('Hi'));
$lines = $collector->lines();
```

After (4.x):

```php
use NeuronAI\Workflow\NodeInterface;

$collector = new TranscriptNode();
$agent->addNode(fn (): NodeInterface => $collector);
$agent->chat(new UserMessage('Hi'));
$lines = $collector->lines();
```

- A factory's return value is used as it is. Return the same instance, as here, only when the app reads it after the run; return a new node when each segment needs its own inner objects.
- Otherwise keep a run's data in the workflow state and longer-lived data in an injected service.
- Middleware registered by instance is cloned the same way: guide 18.

## Checklist

- No `compose(` or `ragNodes(` remains in the application.
- Every `nodes()` override in an Agent or RAG subclass spreads or maps `parent::nodes()`, and every `entryNodes()` override in a RAG subclass spreads or maps `parent::entryNodes()` or lists a full chain starting with `PreProcessNode`.
- No `StreamingNode`, `resolveProvider`, `ResolveProvider`, `bootstrapTools(`, `toolsBootstrapCache` or `QueryPreProcessEvent` remains, and no app class declares `getProvider()` in an Agent or RAG subclass.
- No built-in node subclass declares `inference(`, `executeTools(`, or a 3.x signature of `executeSingleTool(`, `handleError(`, `pendingConversation(` or `__invoke(`; subclasses replace the built-in node through `instanceof` mapping instead of being added next to it.
- Built-in nodes are constructed with the 4.x arguments.
- No code configures or asserts on the result of `getProvider()` when the agent's provider comes from the `provider()` hook.
- App nodes read the provider, instructions, tools and history from `AgentResources`, and every `addToChatHistory()` call passes the history and a distinct memo name.
- No middleware key names `StreamingNode::class`, and no middleware is listed twice for the same node.
- The graph builds: run the tests that exercise each agent. The errors name duplicate handlers (`Node for event ... already exists`), a missing entry node (`No nodes found that handle ...`), invalid middleware keys and nodes asking for resources the workflow does not provide.
