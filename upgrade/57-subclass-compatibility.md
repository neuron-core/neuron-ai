# Upgrade: Workflow, Agent and RAG subclasses: setters return static, removed, final and reserved members

## Summary

This guide finishes the classes that extend or implement Neuron's workflow types: `Workflow`, `Agent`, `RAG`, `Node`, `WorkflowState`, `AgentState`, `WorkflowInterface`, `AgentInterface` and `ExporterInterface`.

| 3.x | 4.x |
|---|---|
| Fluent setters return `WorkflowInterface`, `Workflow`, `AgentInterface`, `Agent`, `RAG` or `self` | They return `static`. `addNode()`, `addMiddleware()` and `addGlobalMiddleware()` also accept a `Closure`, and `parallelToolCalls()` defaults `$enabled` to `true` (Case 1) |
| `bootstrap()`, `getEventNodeMap()`, `getNodeForEvent()`, `getMiddlewareForNode()`, `$eventNodeMap`, and the protected `getNodes()`, `resolveStartEvent()`, `loadEventNodeMap()`, `validate()`, `validateInvokeMethodSignature()` | Removed. Every run builds and checks its own graph from the `nodes()` and `startEvent()` hooks (Case 2) |
| `getStartEvent()` can be overridden, and it memoizes the hook's event | `final`, and it calls `startEvent()` each time unless `setStartEvent()` was used (Case 2) |
| `RAG::resolveVectorStore()`, `resolveEmbeddingsProvider()`, `resolveRetrieval()` can be overridden | `final`. Override `vectorStore()`, `embeddings()`, `retrieval()` (Case 3) |
| Trait `NeuronAI\HandleContent` with `removeDelimitedContent()`, used by `Agent`, `InstructionsNode`, `Anthropic`, `BedrockRuntime` and `ZAI`, so their subclasses could call it | Removed (Case 4) |
| Subclasses could use any member name | The base classes declare new members, some of them `final`. `WorkflowState::deepCloneArray()` is removed (Case 5) |
| `ExporterInterface::export(array $eventNodeMap): string` | `export(WorkflowGraph $graph): string` (Case 6) |
| `WorkflowInterface`: 20 configuration and execution methods | 11 execution methods. Configuration is on `Workflow` only (Case 7) |
| `AgentInterface`: a standalone interface with 10 methods | Extends `WorkflowInterface` and declares 15 methods (Case 8) |

Calls and chains need no change. Most of these breaks are fatal errors when the class loads: `must be compatible with`, `Cannot override final method`, `Type of X::$y must be ...`. Some are silent: a method with a compatible signature and a framework name becomes a framework hook, and the framework calls it.

Stored data: this guide changes none. The built-in exporters produce different text, so saved `export()` output (test snapshots, diagram files) must be regenerated (Case 6).

Earlier guides already migrated these members. Do not redo them:
- `setPersistence()` overrides: guide 13. `setInstructions()`/`getInstructions()` and `$instructions`: guide 24. `toolMaxTries()` and `$preProcessors`/`$postProcessors`: guide 25.
- `resolveProvider()`/`getProvider()`, `compose()`, `ragNodes()`: guide 26. `startEvent()` overrides on agents: guide 27.
- `setChatHistory()`, `chatHistory()`, `getChatHistory()`, `$messageStore`, `$contextWindow`: guide 31. `setExecutor()`/`executor()`: guide 17.
- `run()`/`init()`/`start()`/`resume()` overrides: guide 12. `chat()`/`stream()`/`structured()` overrides: guide 23. `resolveState()`/`$state`: guides 18 and 23.
- Those guides wrote hooks and overrides with the 4.x signatures on purpose: `persistence()`, `workflowId()`, `serializer()`, `branchRunner()`, `streamAdapter()`, `resources()`, `messageStore()`, `contextWindow()`, `provider()`, `instructions()`, `nodes()`, `entryNodes()`, `startEvent()`, and an `events()` override. They are not collisions.

## What to Search For

Run from the application root:

```bash
# 1. The classes this guide checks
grep -rnE 'extends ([\\A-Za-z0-9_]*\\)?(Workflow|Agent|RAG|[A-Za-z0-9_]*Node|WorkflowState|AgentState)([^A-Za-z0-9_\\]|$)' --include='*.php' --exclude-dir=vendor .

# 2. Setter overrides (Case 1)
grep -rnE 'function[[:space:]]+(setStartEvent|setState|addNodes?|addGlobalMiddleware|addMiddleware|setExporter|observe|setAiProvider|addTool|toolErrorHandler|toolMaxRuns|parallelToolCalls|setVectorStore|setEmbeddingsProvider|setRetrieval|setPreProcessors|setPostProcessors)[[:space:]]*\(' --include='*.php' --exclude-dir=vendor .

# 3. Removed and final Workflow members (Case 2)
grep -rnE -e '(->|::)bootstrap\(\)|getEventNodeMap|getNodeForEvent|getMiddlewareForNode|eventNodeMap|resolveStartEvent|getStartEvent\(\)->' -e 'function[[:space:]]+(bootstrap|getNodes|loadEventNodeMap|validate|validateInvokeMethodSignature|getStartEvent)[[:space:]]*\(' --include='*.php' --exclude-dir=vendor .

# 4. RAG resolver overrides (Case 3) and the removed content helper (Case 4)
grep -rnE 'function[[:space:]]+resolve(VectorStore|EmbeddingsProvider|Retrieval)[[:space:]]*\(|HandleContent|removeDelimitedContent' --include='*.php' --exclude-dir=vendor .

# 5. Member names 4.x reserves (Case 5), in the files of the classes from search 1
grep -rlE 'extends ([\\A-Za-z0-9_]*\\)?(Workflow|Agent|RAG|[A-Za-z0-9_]*Node|WorkflowState|AgentState)([^A-Za-z0-9_\\]|$)' --include='*.php' --exclude-dir=vendor . | xargs grep -HnE -e '(public|protected|private|var)[^;=(]*[$](initialState|listeners|persistence|serializer|branchRunner|leaseTimeout|leaseTimeoutConfigured|maxSteps|maxStepsConfigured|retainCompletion|channel|streamAdapter|resources|dispatcher|externalDispatcher|workflowId|startEvent|toolsOverridden|configuredRetrievalScope|payload|timedOut|resuming|memoizer|branchId|answerExpired|answeredWait|waitScope|waitsInScope|interruptRequest|status|runId|executionAttempt|request)([^A-Za-z0-9_]|$)' -e 'function[[:space:]]+(for|setWorkflowId|workflowId|inspect|submitInputs|events|acknowledge|abandon|retainCompletionUntilAcknowledged|setLeaseTimeout|setMaxSteps|setBranchRunner|setSerializer|setChannel|setStreamAdapter|setResources|subscribe|setEventDispatcher|getEventDispatcher|persistence|serializer|branchRunner|channel|streamAdapter|resources|leaseTimeout|maxSteps|execute|consume|requireWorkflowId|instantiateMiddleware|listenerRegistry|validateLeaseTimeout|validateMaxSteps|graph|getMiddleware|getGlobalMiddleware|newState|getEngine|getPersistence|getSerializer|getBranchRunner|resolveChannel|resolveStreamAdapter|resolveResources|getLeaseTimeout|getMaxSteps|shouldRetainCompletionUntilAcknowledged|setTools|setMessageStore|setContextWindow|resetConversation|getThreadId|setThreadId|pendingApprovals|submitApprovalDecisions|submitToolResults|messageStore|contextWindow|resolveMessageStore|resolveTools|validateTools|entryNodes|exitNodes|getProvider|getInstructions|getChatHistory|setRetrievalScope|retrievalScope|resolveRetrievalScope|validateBatch|memoize|recallMemo|awaitEvent|sleepUntil|consumePayload|markAsSuspended|markAsRunning|markAsFailed|clearInterrupt|isInterrupted|getInterruptRequest|getStatus|getWorkflowId|getRunId|getExecutionAttempt|setExecutionMetadata|deepCloneArray|setResponse|getResponse|restoreToolRunCount|callIds|__serialize|__unserialize)[[:space:]]*\(' -e 'ANSWER_MEMO'

# 6. Interface implementers, interface-typed values and exporters (Cases 6 to 8)
grep -rnE '(^|[^A-Za-z0-9_])(WorkflowInterface|AgentInterface|ExporterInterface|MermaidExporter|ConsoleExporter)([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .
```

Follow the hits:
- Search 1 lists the classes to check. Keep those whose parent is Neuron's class (check the `use` imports): `Workflow`, `Agent`, `RAG`, `WorkflowState`, `AgentState`, `Node`, or a built-in node such as `ChatNode` or `InstructionsNode`. Then grep `extends <ClassName>` for every application class it found, and repeat until no new subclass appears. Run search 5 again on the files of these indirect subclasses.
- Searches 2 and 3, `function` hits: keep those declared in Workflow, Agent or RAG subclasses from search 1. A `validate()` method in a node is unrelated.
- Search 3, call hits: keep the calls on Workflow, Agent or RAG instances. `bootstrap()` on a framework kernel or a service provider is unrelated.
- Search 4: `HandleContent` and `removeDelimitedContent` hits count in any class. `resolve*()` hits count in RAG subclasses.
- Search 5: keep the hits in classes from search 1, and compare each with the list for its base class in Case 5. A name reserved on `WorkflowState` is free on a `Node`.
- Search 6: follow every hit to its declaration. It can be a class that implements the interface (the `implements` list may span several lines), an interface that extends it, a class that extends a built-in exporter, a call to an exporter, or a parameter, property or return type. `$workflow->setExporter(new MermaidExporter())` followed by `export()` needs no change.

If searches 2 to 6 find nothing that these rules keep, this guide does not apply.

## How to Refactor

### Case 1: An override of a fluent setter

An override must declare the 4.x signature below. With the 3.x return type (in parentheses), or without the widened parameter, the class fails to load.

On `Workflow`, so also on `Agent` and `RAG`:
- `setStartEvent(Event $event): static` (`WorkflowInterface`)
- `setState(WorkflowState $state): static` (`WorkflowInterface`)
- `addNode(NodeInterface|Closure $node): static` (`Workflow`; the parameter was `NodeInterface`)
- `addNodes(array $nodes): static` (`Workflow`)
- `addGlobalMiddleware(WorkflowMiddleware|Closure|array $middleware): static` (`WorkflowInterface`; the parameter was `WorkflowMiddleware|array`)
- `addMiddleware(string|array $node, WorkflowMiddleware|Closure|array $middleware): static` (`WorkflowInterface`; the second parameter was `WorkflowMiddleware|array`)
- `setExporter(ExporterInterface $exporter): static` (`Workflow`)
- `observe(ObserverInterface $observer): static` (`WorkflowInterface`; deprecated, still works)

On `Agent`, so also on `RAG`:
- `setAiProvider(AIProviderInterface $provider): static` (`AgentInterface`)
- `addTool(ToolInterface|ToolkitInterface|ProviderToolInterface|array $tools): static` (`AgentInterface`)
- `toolErrorHandler(?callable $handler): static` (`Agent`)
- `toolMaxRuns(int $num): static` (`Agent`)
- `parallelToolCalls(bool $enabled = true, ?callable $beforeChild = null, ?callable $afterChild = null): static` (`AgentInterface`; `$enabled` had no default)

On `RAG` (all returned `RAG`):
- `setVectorStore(VectorStoreInterface $store): static`
- `setEmbeddingsProvider(EmbeddingsProviderInterface $provider): static`
- `setRetrieval(RetrievalInterface $retrieval): static`
- `setPreProcessors(array $preProcessors): static`
- `setPostProcessors(array $postProcessors): static`

Before (3.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Providers\AIProviderInterface;

class SupportAgent extends Agent
{
    public function setAiProvider(AIProviderInterface $provider): AgentInterface
    {
        $this->audit->record('provider', $provider::class);

        return parent::setAiProvider($provider);
    }

    public function parallelToolCalls(bool $enabled, ?callable $beforeChild = null, ?callable $afterChild = null): AgentInterface
    {
        $this->audit->record('parallel', $enabled);

        return parent::parallelToolCalls($enabled, $beforeChild, $afterChild);
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;

class SupportAgent extends Agent
{
    public function setAiProvider(AIProviderInterface $provider): static
    {
        $this->audit->record('provider', $provider::class);

        return parent::setAiProvider($provider);
    }

    public function parallelToolCalls(bool $enabled = true, ?callable $beforeChild = null, ?callable $afterChild = null): static
    {
        $this->audit->record('parallel', $enabled);

        return parent::parallelToolCalls($enabled, $beforeChild, $afterChild);
    }
}
```

1. Change the return type to `static`, and keep `return parent::...(...)` or `return $this`. Keep the application's parameter names.
2. Widen the parameters as the list shows, and import `Closure` where it is used. A `Closure` reaches the override only when application code passes one. If the body calls node or middleware methods on the argument, guard those calls with `instanceof`.
3. Remove imports this change leaves unused (`AgentInterface`, `WorkflowInterface`).
4. In 3.x the constructor passed the `middleware()` and `globalMiddleware()` hook results through `addMiddleware()` and `addGlobalMiddleware()`, and an Agent passed its built-in nodes through `addNodes()`. 4.x reads those hooks and builds agent nodes without calling these methods, so an override now sees only the application's own calls. If the override transformed what it received (wrapping, filtering), ask the developer whether the hook-declared middleware still needs that treatment, and if so apply it inside the hook.

### Case 2: Removed graph methods and the final `getStartEvent()`

Before (3.x):

```php
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Workflow;

class OrderWorkflow extends Workflow
{
    public function getStartEvent(): Event
    {
        return new OrderPlaced();
    }

    protected function getNodes(): array
    {
        return [new ChargeCard(), new ShipOrder()];
    }
}

$workflow->bootstrap();
echo $workflow->export();
```

After (4.x):

```php
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Workflow;

class OrderWorkflow extends Workflow
{
    protected function startEvent(): Event
    {
        return new OrderPlaced();
    }

    protected function nodes(): array
    {
        return [new ChargeCard(), new ShipOrder()];
    }
}

echo $workflow->export();
```

1. Delete `->bootstrap()` calls and `bootstrap()` overrides. Every `run()`, `events()` and `export()` builds and checks the graph, so graph errors now surface there.
2. Move a `getNodes()` override into `protected function nodes(): array`, and replace `parent::getNodes()` with `parent::nodes()`. Nodes added with `addNode()` are still added after the hook's nodes.
3. Move a `getStartEvent()` or `resolveStartEvent()` override into `protected function startEvent(): Event` (`AgentStartEvent` on an Agent or RAG). Replace `$this->resolveStartEvent()` with `$this->getStartEvent()`.
4. `getStartEvent()` no longer keeps the hook's event. Code that modified the event it returned before running (`$workflow->getStartEvent()->...`) has no effect now: build the event and pass it to `setStartEvent($event)` instead. Guide 27 already handled the Agent's start event.
5. `getEventNodeMap()`, `getNodeForEvent()`, `$this->eventNodeMap` and `getMiddlewareForNode()` have no replacement. To render the graph, call `$workflow->export()` (Case 6). Report any other use, such as finding a node instance or listing middleware at runtime, to the developer, and keep the application's own references to what it registered.
6. Delete overrides of `validate()`, `loadEventNodeMap()` and `validateInvokeMethodSignature()`. If they added application checks, report them to the developer: 4.x has no hook for graph validation.

### Case 3: A RAG subclass overrides a resolver

`resolveVectorStore()`, `resolveEmbeddingsProvider()` and `resolveRetrieval()` are `final`, so an override fails with `Cannot override final method`. Calls to them keep working.

| 3.x override | 4.x hook | When the framework calls the hook |
|---|---|---|
| `public function resolveVectorStore(): VectorStoreInterface` | `protected function vectorStore(): VectorStoreInterface` | Once per instance, unless `setVectorStore()` was called |
| `public function resolveEmbeddingsProvider(): EmbeddingsProviderInterface` | `protected function embeddings(): EmbeddingsProviderInterface` | Once per instance, unless `setEmbeddingsProvider()` was called |
| `public function resolveRetrieval(): RetrievalInterface` | `protected function retrieval(): RetrievalInterface` | At every resolution (every run segment), unless `setRetrieval()` was called |

Before (3.x):

```php
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Retrieval\RetrievalInterface;

class HandbookBot extends RAG
{
    public function resolveRetrieval(): RetrievalInterface
    {
        return $this->retrieval ??= new AuditedRetrieval(parent::resolveRetrieval());
    }
}
```

After (4.x):

```php
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Retrieval\RetrievalInterface;

class HandbookBot extends RAG
{
    protected function retrieval(): RetrievalInterface
    {
        return new AuditedRetrieval(parent::retrieval());
    }
}
```

1. Move the body into the hook. It returns the object: drop the `$this->store ??=`, `$this->embeddingsProvider ??=` or `$this->retrieval ??=` assignment.
2. Replace `parent::resolveX()` with the parent hook (`parent::vectorStore()`, `parent::embeddings()`, `parent::retrieval()`).
3. If the class also overrides that hook, merge the two methods: where the resolver called `parent::resolveX()`, use the body of the existing hook.
4. `retrieval()` runs at every run segment, so keep it free of side effects.

### Case 4: `removeDelimitedContent()` and the `HandleContent` trait

The trait and its method are removed. This affects subclasses of `Agent`, `RAG`, `NeuronAI\RAG\Nodes\InstructionsNode`, `Anthropic`, `AnthropicVertex`, `BedrockRuntime` and `ZAI` that call the method, and any class that uses the trait.

Before (3.x):

```php
use NeuronAI\Agent\Agent;

class ReportAgent extends Agent
{
    protected function stripNotes(string $text): string
    {
        return $this->removeDelimitedContent($text, '<notes>', '</notes>');
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;

class ReportAgent extends Agent
{
    protected function stripNotes(string $text): string
    {
        return $this->removeDelimitedContent($text, '<notes>', '</notes>');
    }

    protected function removeDelimitedContent(string $text, string $openTag, string $closeTag): string
    {
        return (string) preg_replace('/' . preg_quote($openTag, '/') . '.*?' . preg_quote($closeTag, '/') . '/s', '', $text);
    }
}
```

1. Delete `use NeuronAI\HandleContent;` and the in-class `use HandleContent;`.
2. Add the method above to each class that calls `removeDelimitedContent()`, or to one application trait that those classes use.

### Case 5: A member name that 4.x now reserves

The base classes declare these members. A subclass member with the same name fails to load when its type, signature or visibility differs, or when the parent member is `final`. With a compatible declaration it loads, and the framework now reads, writes or calls it. For example, it calls `markAsFailed()` on the state when a run fails, and treats `maxSteps()` as the run's step limit.

`Workflow` (so also `Agent` and `RAG`):
- Properties: `$initialState`, `$listeners`, `$persistence`, `$serializer`, `$branchRunner`, `$leaseTimeout`, `$leaseTimeoutConfigured`, `$maxSteps`, `$maxStepsConfigured`, `$retainCompletion`, `$channel`, `$streamAdapter`, `$resources`, `$dispatcher`, `$externalDispatcher`. Redeclare `$workflowId` only as `protected ?string` and `$startEvent` only as `protected ?Event` (3.x: `string` and `Event`).
- Methods: `for()`, `setWorkflowId()`, `workflowId()`, `inspect()`, `submitInputs()`, `events()`, `acknowledge()`, `abandon()`, `retainCompletionUntilAcknowledged()`, `setLeaseTimeout()`, `setMaxSteps()`, `setBranchRunner()`, `setSerializer()`, `setChannel()`, `setStreamAdapter()`, `setResources()`, `subscribe()`, `setEventDispatcher()`, `getEventDispatcher()`. The protected hooks `persistence()`, `serializer()`, `branchRunner()`, `channel()`, `streamAdapter()`, `resources()`, `leaseTimeout()`, `maxSteps()`. The protected helpers `execute()`, `consume()`, `requireWorkflowId()`, `instantiateMiddleware()`, `listenerRegistry()`, `validateLeaseTimeout()`, `validateMaxSteps()`.
- Final: `getStartEvent()` (Case 2), `graph()`, `getMiddleware()`, `getGlobalMiddleware()`, `newState()`, `getEngine()`, `getPersistence()`, `getSerializer()`, `getBranchRunner()`, `resolveChannel()`, `resolveStreamAdapter()`, `resolveResources()`, `getLeaseTimeout()`, `getMaxSteps()`, `shouldRetainCompletionUntilAcknowledged()`.

`Agent` (so also `RAG`):
- Property: `$toolsOverridden`.
- Methods: `setTools()`, `setMessageStore()`, `setContextWindow()`, `resetConversation()`, `getThreadId()`, `setThreadId()`, `pendingApprovals()`, `submitApprovalDecisions()`, `submitToolResults()`, and the protected `messageStore()`, `contextWindow()`, `resolveMessageStore()`, `resolveTools()`, `validateTools()`, `entryNodes()`, `exitNodes()`. On an Agent, `resources()` returns `AgentResources`.
- Final: `getProvider()`, `getInstructions()`, `getChatHistory()` (guides 26, 24 and 31 already handled them).

`RAG`: the property `$configuredRetrievalScope`, the methods `setRetrievalScope()`, `retrievalScope()`, `validateBatch()`, and the final `resolveRetrievalScope()` (and Case 3).

`Node`: the properties `$payload`, `$timedOut`, `$resuming`, `$memoizer`, `$dispatcher`, `$branchId`, `$answerExpired`, `$answeredWait`, `$waitScope`, `$waitsInScope`, the constant `ANSWER_MEMO`, and the protected methods `memoize()`, `recallMemo()`, `awaitEvent()`, `sleepUntil()`, `consumePayload()`.

`WorkflowState` (so also `AgentState`): the properties `$interruptRequest`, `$status`, `$workflowId`, `$runId`, `$executionAttempt`, and the methods `markAsSuspended()`, `markAsRunning()`, `markAsFailed()`, `clearInterrupt()`, `isInterrupted()`, `getInterruptRequest()`, `getStatus()`, `getWorkflowId()`, `getRunId()`, `getExecutionAttempt()`, `setExecutionMetadata()`.

`AgentState`: the property `public InferenceRequest $request`, and the methods `setResponse()`, `getResponse()`, `restoreToolRunCount()`, `callIds()`, `__serialize()`, `__unserialize()`.

Before (3.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

class OrderState extends WorkflowState
{
    protected string $status = 'new';

    public function getStatus(): string
    {
        return $this->status;
    }
}

class OrderWorkflow extends Workflow
{
    protected function maxSteps(): int
    {
        return 3; // payment attempts
    }
}

class SupportAgent extends Agent
{
    protected string $threadId;

    public function setThreadId(string $threadId): self
    {
        $this->threadId = $threadId;
        return $this;
    }

    public function getThreadId(): string
    {
        return $this->threadId;
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

class OrderState extends WorkflowState
{
    protected string $orderStatus = 'new';

    public function getOrderStatus(): string
    {
        return $this->orderStatus;
    }
}

class OrderWorkflow extends Workflow
{
    protected function maxPaymentAttempts(): int
    {
        return 3;
    }
}

class SupportAgent extends Agent
{
}
```

For each search 5 hit that the lists above reserve for its base class:
1. The member means something else (the usual case): rename it and all its uses (`$this->name`, `->name(`, `parent::name(`, `[$this, 'name']`, subclasses, docblocks, container configuration). Choose a name that is not in the lists.
2. The member is the same concept as the framework member: delete it when the framework member does its job, and keep its callers. For example, remove `getThreadId()`/`setThreadId()`/`$threadId` that held the conversation thread (guide 13 bound it as the workflow ID), and replace `$this->threadId` reads with `$this->getThreadId()`. Remove a redeclared `$workflowId`. Or keep the member with the exact 4.x signature and meaning, such as a `workflowId(): ?string` business key.
3. If the code does not show which case applies, ask the developer. Name the member and say what the framework now does with it, for example: "`OrderWorkflow::maxSteps()` is now the workflow's step limit: runs fail after 3 node steps. Is that intended, or should it be renamed?"
4. An override of `deepCloneArray()` in a state subclass is never called, and `parent::deepCloneArray()` is fatal: 4.x copies `$data` with `serialize()`. Delete the override. If it copied objects in a special way, do it in `public function __clone(): void` and call `parent::__clone()` first.

### Case 6: A custom exporter or a direct exporter call

Before (3.x):

```php
use NeuronAI\Workflow\Exporter\ExporterInterface;
use NeuronAI\Workflow\Exporter\MermaidExporter;
use NeuronAI\Workflow\NodeInterface;

class PlantUmlExporter implements ExporterInterface
{
    /** @param array<string, NodeInterface> $eventNodeMap */
    public function export(array $eventNodeMap): string
    {
        $lines = ['@startuml'];
        foreach ($eventNodeMap as $event => $node) {
            $lines[] = (new ReflectionClass($event))->getShortName() . ' --> ' . (new ReflectionClass($node))->getShortName();
        }
        $lines[] = '@enduml';

        return implode("\n", $lines);
    }
}

$diagram = (new MermaidExporter())->export($workflow->getEventNodeMap());
```

After (4.x):

```php
use NeuronAI\Workflow\Exporter\ExporterInterface;
use NeuronAI\Workflow\Exporter\MermaidExporter;
use NeuronAI\Workflow\Exporter\WorkflowGraph;

class PlantUmlExporter implements ExporterInterface
{
    public function export(WorkflowGraph $graph): string
    {
        $lines = ['@startuml'];
        foreach ($graph->getEdges() as $edge) {
            $lines[] = $graph->getVertex($edge->from)->label . ' --> ' . $graph->getVertex($edge->to)->label;
        }
        $lines[] = '@enduml';

        return implode("\n", $lines);
    }
}

$diagram = $workflow->setExporter(new MermaidExporter())->export();
```

1. A class that implements `ExporterInterface` or extends `MermaidExporter` or `ConsoleExporter` declares `export(WorkflowGraph $graph): string`. The graph holds vertices for events, nodes and parallel splits and joins (`getVertices()`, `getVertex($id)`, `$vertex->label`, `$vertex->type` as `WorkflowGraphVertexType`, `$vertex->class`) and edges between them (`getEdges()`, `getOutgoingEdges($id)`, `$edge->from`, `$edge->to`, `$edge->label`). `$graph->startVertexId` is the start event's vertex.
2. A direct call with an event-node map becomes `$workflow->setExporter($exporter)->export()`.
3. Regenerate saved output of `export()`: test snapshots and diagram files. The built-in exporters now render a different layout, and Mermaid declares each vertex with a hashed ID and a label.

### Case 7: `WorkflowInterface` implementers and `WorkflowInterface`-typed values

`WorkflowInterface` now declares only the execution API. Of the 3.x methods only `getWorkflowId()` remains, and it returns `?string`.

(a) A class that implements `WorkflowInterface` without extending `Workflow`, such as a decorator. If it only adds behaviour to one workflow, ask the developer whether it can extend that workflow class instead. Otherwise implement the 4.x methods below, and keep the application's own logic in the methods that had it. The 3.x methods (`start`, `init`, `setPersistence`, `bootstrap`, `getStartEvent`, `setStartEvent`, `setState`, `resolveState`, `addNode`, `addNodes`, `getNodeForEvent`, `addGlobalMiddleware`, `addMiddleware`, `getMiddlewareForNode`, `getEventNodeMap`, `getResumeToken`, `export`, `observe`, `setExecutor`) are no longer part of the interface: delete those that nothing calls. A configuration method the application still calls on the decorator can stay as the decorator's own method if the wrapped value is typed `Workflow` (part b); the removed ones are Case 2.

Before (3.x):

```php
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowHandlerInterface;
use NeuronAI\Workflow\WorkflowInterface;

class TracedWorkflow implements WorkflowInterface
{
    public function __construct(protected WorkflowInterface $workflow)
    {
    }

    public function init(?InterruptRequest $resumeRequest = null): WorkflowHandlerInterface
    {
        return $this->workflow->init($resumeRequest);
    }

    public function getWorkflowId(): string
    {
        return $this->workflow->getWorkflowId();
    }

    // ...one delegating method for each of the other 3.x WorkflowInterface methods
}
```

After (4.x):

```php
use Generator;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\PendingExecution;
use NeuronAI\Workflow\WorkflowInterface;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowState;
use Psr\EventDispatcher\EventDispatcherInterface;

class TracedWorkflow implements WorkflowInterface
{
    public function __construct(protected WorkflowInterface $workflow)
    {
    }

    public function run(?ExecutionRequest $request = null): WorkflowState
    {
        return $this->workflow->run($request);
    }

    public function events(?ExecutionRequest $request = null): Generator
    {
        return $this->workflow->events($request);
    }

    public function inspect(): ?WorkflowRunSnapshot
    {
        return $this->workflow->inspect();
    }

    public function submitInputs(array $payload, ?InputTranslatorInterface $translator = null): PendingExecution
    {
        return $this->workflow->submitInputs($payload, $translator);
    }

    public function acknowledge(string $expectedRunId): void
    {
        $this->workflow->acknowledge($expectedRunId);
    }

    public function abandon(?string $expectedRunId = null, ?int $expectedExecutionAttempt = null): bool
    {
        return $this->workflow->abandon($expectedRunId, $expectedExecutionAttempt);
    }

    public function retainCompletionUntilAcknowledged(bool $retain = true): static
    {
        $this->workflow->retainCompletionUntilAcknowledged($retain);
        return $this;
    }

    public function getWorkflowId(): ?string
    {
        return $this->workflow->getWorkflowId();
    }

    public function for(string $workflowId): static
    {
        $copy = clone $this;
        $copy->workflow = $this->workflow->for($workflowId);
        return $copy;
    }

    public function subscribe(string $eventClass, callable $listener): static
    {
        $this->workflow->subscribe($eventClass, $listener);
        return $this;
    }

    public function setEventDispatcher(EventDispatcherInterface $dispatcher): static
    {
        $this->workflow->setEventDispatcher($dispatcher);
        return $this;
    }
}
```

`for()` returns a bound copy and must not change the receiver. The other setters return `$this`.

(b) A parameter, property or return type declared `WorkflowInterface` on which the application calls `getStartEvent()`, `setStartEvent()`, `setState()`, `addNode()`, `addNodes()`, `addMiddleware()`, `addGlobalMiddleware()`, `export()`, `setExporter()` or `observe()`: retype it to `NeuronAI\Workflow\Workflow`, or to the application's concrete class. PHPStan reports these calls as `method.notFound`. Calls to the removed methods are Case 2.

Before (3.x):

```php
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\WorkflowInterface;

function startWith(WorkflowInterface $workflow, Event $event): WorkflowInterface
{
    return $workflow->setStartEvent($event);
}
```

After (4.x):

```php
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Workflow;

function startWith(Workflow $workflow, Event $event): Workflow
{
    return $workflow->setStartEvent($event);
}
```

### Case 8: `AgentInterface` implementers

4.x `AgentInterface` extends `WorkflowInterface`, so a class that implements it directly (a decorator or a hand-written test double) implements every method of both. Prefer extending `NeuronAI\Agent\Agent`. For a test double, use an `Agent` configured with `NeuronAI\Testing\FakeAIProvider` (guide 56 covers the fake), and ask the developer before replacing a double that records calls.

| 3.x `AgentInterface` | 4.x `AgentInterface` |
|---|---|
| `setAiProvider(AIProviderInterface $provider): AgentInterface` | `setAiProvider(AIProviderInterface $provider): static` |
| `resolveProvider(): AIProviderInterface` | `getProvider(): AIProviderInterface` |
| `setInstructions(string $instructions): AgentInterface` | `setInstructions(SystemMessage\|string $instructions): static` |
| `resolveInstructions(): string` | `getInstructions(): SystemMessage` |
| `addTool(ToolInterface\|ToolkitInterface\|array $tools): AgentInterface` | `addTool(ToolInterface\|ToolkitInterface\|ProviderToolInterface\|array $tools): static` |
| `getTools(): array` | `getTools(): array` |
| `setChatHistory(AbstractChatHistory $chatHistory): AgentInterface` | `setMessageStore(MessageStoreInterface $store): static` |
| none | `setTools(array $tools): static`, `getChatHistory(): ChatHistory`, `resetConversation(): static`, `getThreadId(): ?string`, `setThreadId(string $threadId): static` |
| `chat(Message\|array $messages = [], ?InterruptRequest $interrupt = null): AgentHandler` | `chat(Message\|array $messages = [], bool $stream = false): AgentState` |
| `stream(Message\|array $messages = [], ?InterruptRequest $interrupt = null): AgentHandler` | `stream(Message\|array $messages = []): Generator` |
| `structured(Message\|array $messages = [], ?string $class = null, int $maxRetries = 1, ?InterruptRequest $interrupt = null): mixed` | `structured(Message\|array $messages = [], ?string $class = null, int $maxRetries = 1): mixed` |
| none | the 11 `WorkflowInterface` methods of Case 7 |

Before (3.x):

```php
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

class LoggingAgent implements AgentInterface
{
    public function __construct(protected AgentInterface $agent, protected LoggerInterface $logger)
    {
    }

    public function setAiProvider(AIProviderInterface $provider): AgentInterface
    {
        $this->agent->setAiProvider($provider);
        return $this;
    }

    public function resolveProvider(): AIProviderInterface
    {
        return $this->agent->resolveProvider();
    }

    public function chat(Message|array $messages = [], ?InterruptRequest $interrupt = null): AgentHandler
    {
        $this->logger->info('agent chat');
        return $this->agent->chat($messages, $interrupt);
    }

    // ...setInstructions(), resolveInstructions(), addTool(), getTools(), setChatHistory(), stream(), structured()
}
```

After (4.x):

```php
use Generator;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

class LoggingAgent implements AgentInterface
{
    public function __construct(protected AgentInterface $agent, protected LoggerInterface $logger)
    {
    }

    public function setAiProvider(AIProviderInterface $provider): static
    {
        $this->agent->setAiProvider($provider);
        return $this;
    }

    public function getProvider(): AIProviderInterface
    {
        return $this->agent->getProvider();
    }

    public function setInstructions(SystemMessage|string $instructions): static
    {
        $this->agent->setInstructions($instructions);
        return $this;
    }

    public function getInstructions(): SystemMessage
    {
        return $this->agent->getInstructions();
    }

    public function setTools(array $tools): static
    {
        $this->agent->setTools($tools);
        return $this;
    }

    public function addTool(ToolInterface|ToolkitInterface|ProviderToolInterface|array $tools): static
    {
        $this->agent->addTool($tools);
        return $this;
    }

    public function getTools(): array
    {
        return $this->agent->getTools();
    }

    public function setMessageStore(MessageStoreInterface $store): static
    {
        $this->agent->setMessageStore($store);
        return $this;
    }

    public function getChatHistory(): ChatHistory
    {
        return $this->agent->getChatHistory();
    }

    public function resetConversation(): static
    {
        $this->agent->resetConversation();
        return $this;
    }

    public function getThreadId(): ?string
    {
        return $this->agent->getThreadId();
    }

    public function setThreadId(string $threadId): static
    {
        $this->agent->setThreadId($threadId);
        return $this;
    }

    public function chat(Message|array $messages = [], bool $stream = false): AgentState
    {
        $this->logger->info('agent chat');
        return $this->agent->chat($messages, $stream);
    }

    public function stream(Message|array $messages = []): Generator
    {
        return $this->agent->stream($messages);
    }

    public function structured(Message|array $messages = [], ?string $class = null, int $maxRetries = 1): mixed
    {
        return $this->agent->structured($messages, $class, $maxRetries);
    }

    // The 11 WorkflowInterface methods of Case 7 (a), delegating to $this->agent instead of $this->workflow,
    // with their imports.
}
```

1. Rename `resolveProvider()` to `getProvider()` and `resolveInstructions()` to `getInstructions()`, and replace `setChatHistory()` with `setMessageStore()` (guide 31 explains message stores).
2. Drop the `$interrupt` parameters. Callers continue paused runs as guide 29 describes.
3. Add the new methods, and the `WorkflowInterface` methods of Case 7 (a).
4. Return `$this` from the `static` setters, and a bound copy from `for()`.

## Checklist

- Every search 1 class loads. `vendor/bin/phpstan analyse` reports none of `method.parentMethodFinal`, `method.childReturnType`, `method.childParameterType`, `parameter.notOptional`, `parameter.missing`, `property.nativeType` or `method.visibility` on them. Without PHPStan, `php -r 'require "vendor/autoload.php"; class_exists(App\Workflows\OrderWorkflow::class);'` prints nothing for each class.
- Setter overrides return `static` with the Case 1 parameters. `parallelToolCalls()` declares `bool $enabled = true`.
- No `bootstrap()`, `getEventNodeMap()`, `getNodeForEvent()`, `getMiddlewareForNode()`, `eventNodeMap`, `resolveStartEvent()` or `getNodes()` remains on a workflow, and no class overrides `getStartEvent()`. Code that modified `getStartEvent()`'s result now calls `setStartEvent()`.
- No RAG subclass overrides `resolveVectorStore()`, `resolveEmbeddingsProvider()` or `resolveRetrieval()`.
- No `HandleContent` remains. Every class that calls `removeDelimitedContent()` declares it (or uses an application trait that does).
- Every search 5 hit on a reserved name was renamed, deleted, or kept with the 4.x signature and meaning. The developer confirmed the unclear ones. No `deepCloneArray()` remains.
- Custom exporters take `WorkflowGraph`. Saved `export()` output was regenerated.
- `WorkflowInterface` and `AgentInterface` implementers declare the full 4.x contract or extend `Workflow`/`Agent`. No value typed `WorkflowInterface` calls a configuration method.
