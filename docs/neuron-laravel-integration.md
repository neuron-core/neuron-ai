# Neuron AI for Laravel: integration strategy

This document describes how Neuron AI 4.x integrates with Laravel 12 and 13, and is the foundation for the `neuron-core/neuron-laravel` package (namespace `NeuronAI\Laravel`, configuration file `config/neuron.php`). The package makes Neuron the durable orchestration layer of a Laravel application: agents, RAG pipelines and business workflows become container services whose runs survive queue redeliveries, worker restarts, deploys and client disconnects, pause on approvals, awaited events and timers, and recover by reusing every committed LLM and tool step. The package stays thin. Fences, leases, retries and recovery are decided by the core engine and the core reference gateway; the package maps them onto Laravel's idioms (the container, the query builder and Eloquent, queues and Horizon, the scheduler, Responsables, policies, Reverb, events, artisan and the testing helpers). Core changes the design depends on are referenced as "core improvement C<n>" and specified in [neuron-core-improvements.md](neuron-core-improvements.md); every section that depends on one also states how the package works against today's core.

How to read this document: sections 1 to 3 give the positioning and the architecture, sections 4 to 8 the container model, configuration, providers and the HTTP layer, storage and the durable runtime, sections 9 to 16 each integration area, and sections 17 to 21 the checklists, the package layout, the core dependencies and the roadmap. Every class in the `NeuronAI\Laravel` namespace is a proposal of this document. Core APIs that do not exist yet are marked "(proposed, C<n>)"; any other core class, method or hook named here exists on the current 4.x branch. Shared terms (definition, handle, run, segment, fence, lease, invocation, disposition, projection, sweep) are defined in the glossary at the end of section 1 of [neuron-core-improvements.md](neuron-core-improvements.md).

## 1. Purpose and positioning

Laravel now has a first-party AI layer. laravel/ai reached 1.0 in September 2026. It gives Laravel developers agent classes built on small contracts and the `Promptable` trait, tools, structured output, streaming with the Vercel AI SDK and AG-UI protocols, a conversation store, queued prompts, broadcasting, human tool approval, provider failover, and `Agent::fake()`. It is a good answer to "call a model and show the result". It does not execute durably. A turn that fails is stored as failed, and a tool call without a recorded result is sent back to the model "marked as interrupted, since Laravel cannot determine whether it ran". Queued prompts serialize the agent instance itself into the job. There are no leases, no run or attempt fences, no memoized steps, no timers, no waits on external events with deadlines, and no workflow graph.

Neuron's core value is exactly that missing part: fenced optimistic ownership of runs, persisted interruptions (approvals, awaited events, timers, deferred tool results), memoized steps that make recovery free, leases, and retained completions. The package is positioned accordingly. laravel/ai answers a prompt; Neuron finishes a process. The Laravel package does not compete on prompt ergonomics. It gives Laravel durable agents and workflows: a support agent whose thread survives a worker crash in the middle of a tool loop, an approval that expires after 24 hours and resumes the run on its own, an order workflow that waits three days for a webhook, and an ingestion pipeline that resumes at the last committed batch.

The closest durability benchmark in the Laravel ecosystem, durable-workflow/workflow, runs Temporal-style workflows whose activities are queued jobs, and it is not AI-aware. Neuron's engine is AI-native (the agent loop, tool approval, streaming protocols, RAG) and framework-neutral, and it persists runs through optimistic fenced writes rather than one job per activity.

Laravel's own job chains and batches remain the right tool for pipelines of deterministic jobs. A Neuron Workflow is the right tool when a process waits for a human or an external event with a deadline, when its steps are paid, non-deterministic LLM calls that recovery must not repeat, or when its progress streams to a user. The two compose: a node can dispatch a batch as a memoized side effect, and the batch's `then()` callback can signal the waiting workflow (section 8.11).

Coexistence with laravel/ai is a design requirement, not a migration path. Both can live in the same application. The package uses its own generator names (`make:neuron-agent` and friends, section 16) and its own default namespace, `App\Neuron`, so it never collides with laravel/ai's `make:agent`, `make:tool`, `make:agent-middleware` and `App\Ai`. A Neuron node can call a laravel/ai agent inside `memoize()`, which turns that call into a durable step whose committed result recovery reuses:

```php
final class DraftReplyNode extends Node
{
    public function __invoke(TicketReceived $event, WorkflowState $state): ReplyDrafted
    {
        // Once the memo commits, recovery reuses it; a crash before the commit repeats the call.
        $draft = $this->memoize('draft', fn (): string => (string) SupportDrafter::make()->prompt($event->body));

        return new ReplyDrafted($draft);
    }
}
```

The package also exposes Neuron tools and durable workflows through laravel/mcp (section 11), so they can sit next to the application's own laravel/mcp tools on one server.

The package targets `illuminate/*` ^12.47|^13 on PHP ^8.2 (Laravel 13 itself requires PHP 8.3, so PHP 8.2 applications get Laravel 12), is tested with Orchestra Testbench 10 and 11, and is released in lockstep with the core 4.x line. Laravel 12.47 is the first 12.x release with the vector schema and query methods that section 12 relies on.

## 2. Design principles for the package

The package follows the eleven principles that govern all three integration documents. The table states what each one means concretely in Laravel. The names are used consistently in the rest of this document.

| Principle | What it means in the Laravel package |
|---|---|
| Definitions are shared, executions are bound | Agents, workflows and RAG classes are container singletons. Controllers, jobs, Livewire components and commands inject them and call `$definition->for($id)` (proposed, C2) after authorization. The bound handle never enters the container. |
| Configure once, at the lowest precedence | One `afterResolving(Workflow::class)` callback applies a PSR-11 defaults locator with `setDefaults()` (proposed, C3). Explicit setters and a class's own hooks always win over the defaults tier; only per-definition options the application configures explicitly (such as the lease) are applied as setters. |
| Constructors belong to the application and do no I/O | Nothing touches the network in `register()` or `boot()`. Provisioning is `php artisan neuron:setup`, next to `migrate` in deploy scripts (C4). |
| Shared means stateless | Singletons hold configuration and shareable collaborators only. Per-call data travels in arguments. Singletons never capture the request, the `Application` or the config repository, which keeps Octane safe. |
| The returned outcome is the only scheduling signal | Timers, projections and completion handlers are derived from the state `run()` returns, or from `inspect()`, before a job returns. Listeners are telemetry, never schedulers. |
| Every delivery is a fenced continuation | Laravel queues deliver at least once. `WithoutOverlapping` and unique jobs only save cost; correctness comes from core fences. |
| Names and JSON cross process boundaries, PHP objects stay in the workflow store | The `InvokeWorkflow` job carries a definition name, a workflow ID, fences and a JSON payload. It never serializes an agent, a provider or a closure. |
| Monitoring never changes execution | The Laravel event bridge cannot fail a step, even when a Telescope or metrics listener throws (C22). |
| Time and failure are typed | The engine reads a Carbon-backed PSR-20 clock, so `travel()` drives leases and deadlines (C10). Jobs and exception rendering branch on exception types, never on messages (C6). |
| Persisted records survive deploys | Workflow state holds IDs, never Eloquent models. Versioned definitions route to versioned queues (C12). |
| Core owns contracts, schemas and conformance tests; adapters live where their conventions live | The package ships Laravel adapters (query builder and Eloquent stores, the HTTP client, the event bridge, the job) and proves them against core's contract test cases (C13). |

The corollary is a rule for the package's own code. If a piece of logic would be identical in the Symfony bundle and depends on engine semantics, it belongs in core or in the core gateway, not in this package. What remains here is idiom: registration, configuration, storage on Laravel's database layer, queue verbs, responses, authorization, broadcasting, events, commands and fakes.

## 3. Architecture at a glance

The integration has three layers, and dependencies point one way. The first is core (`neuron-core/neuron-ai`): definitions (Workflow, Agent, RAG), the `WorkflowEngine` with its fences, leases and memoized steps, the persistence contract, stream adapters, channels, providers and tools. The engine never sees a container, a queue or an HTTP request. The second layer is the reference invocation gateway, a leaf module of core, `NeuronAI\Gateway` (proposed, C14). It turns a transport-neutral `Invocation` into a fenced run, reconciles a run projection from the returned state, hands retained completions to a handler and classifies refusals into a `Disposition`. It is to platform integration what `Agent` is to agent composition: the reference assembly of core's public API. The third layer is this package, which contributes only what is idiomatic to Laravel.

```text
+--------------------------------------------------------------------------------+
| Laravel application                                                            |
|   controllers, jobs, Livewire, commands, routes/channels.php, policies         |
|   inject definitions, authorize, then call $definition->for($id)               |
+----------------------------------------+---------------------------------------+
                                         |
+----------------------------------------v---------------------------------------+
| neuron-core/neuron-laravel  (NeuronAI\Laravel)                                 |
|   NeuronServiceProvider, discovery manifest, NeuronDefaults (PSR-11)           |
|   ProviderManager, LaravelHttpClient, IlluminateEventBridge, CarbonClock       |
|   query-builder and Eloquent stores, migrations, projection store              |
|   InvokeWorkflow job, neuron:wake sweep, NeuronStream Responsable, policies    |
|   Reverb channel factory, artisan commands, generators, Neuron::fake()         |
+-------------------+----------------------------------------+-------------------+
                    | Disposition -> release/fail/return      | PSR-11, PSR-14, PSR-20,
                    |                                         | HttpClientInterface,
+-------------------v-----------------------------+           | stores
| NeuronAI\Gateway  (core leaf module, C14)       |           |
|   Invocation, Gateway::invoke/reconcile/due,    |           |
|   Disposition, RunProjection, stores contracts  |           |
+-------------------+-----------------------------+           |
                    | public engine API only                  |
+-------------------v-----------------------------------------v------------------+
| neuron-core/neuron-ai  (core)                                                  |
|   Workflow / Agent / RAG definitions, WorkflowEngine, PersistenceInterface,    |
|   fences, leases, memoized steps, interrupts, adapters, channels, providers    |
+--------------------------------------------------------------------------------+
```

Three kinds of things cross the layer boundaries. Downwards, the package hands core standard PHP contracts: a PSR-11 locator of defaults (proposed, C3) and of definitions by name (proposed, C14), a PSR-14 dispatcher, a PSR-20 clock (proposed, C10), PSR-3 loggers, an `HttpClientInterface` adapter and `PersistenceInterface` and `MessageStoreInterface` implementations. Upwards, core returns a `WorkflowState` (or raises a typed exception), which the gateway turns into a `Disposition` (done, retry at, discard, fail) that the job translates into `return`, `release()` or `fail()`. Sideways, the HTTP edge calls the same definitions synchronously and reconciles the returned state through the same gateway, so a turn that suspends inline gets its projection row and its timer exactly as a turn that ran in a worker does; only queued invocations write the projection ahead of execution.

The Symfony bundle has the same three layers and differs only in the third. Until C14 ships, the middle layer is not part of core: it is the `@internal` pre-release of `neuron-core/gateway`, written once against today's core with the surface of C14 and shared by this package and the Symfony bundle (section 8.12). Neither package re-implements it.

## 4. The container model

The IoC container is the seam between what developers declare and the platform that runs it, and it is where most integration bugs would come from, because Octane and queue workers keep singletons alive across requests and jobs. This section fixes the lifetime of every Neuron component, how definitions become shareable, how the package supplies defaults without overriding class decisions, and how identity enters a run.

### 4.1 Lifetimes

The table gives the target lifetimes, which assume core improvements C2 to C5 and C18, and the registration the package uses against today's core.

| Component | Target lifetime | Laravel registration (target) | Against today's core |
|---|---|---|---|
| Workflow, Agent and RAG definitions | Shared | `singleton()` for every manifest entry; defaults applied once in `afterResolving(Workflow::class)` | `bind()`, a fresh instance per resolution, bound with `setThreadId()`/`setWorkflowId()` immediately; never `singleton()` or `scoped()` |
| Execution handle, `$definition->for($id)` | Per call, never in the container | Built in the controller, job or command after authorization | The resolved prototype is the handle |
| `NeuronDefaults` (PSR-11) | Shared | `singleton()` over the package's configured bindings | Replaced by setters applied in `afterResolving` only where the class keeps the base hook, checked by reflection (section 4.3) |
| `PersistenceInterface`, `Serializer`, `ClockInterface`, `WorkflowEngine` | Shared | Singletons; SQL backends resolve their connection per operation | Same for the package's query-builder and Eloquent backends; core's PDO-capturing stores are built per job until C13 (section 7.4); the engine does not read the clock until C10 |
| `MessageStoreInterface` | Shared | Singleton | Same |
| `AIProviderInterface`, per configured name | Shared | `ProviderManager` caches one instance per name; `#[NeuronProvider('name')]` injects it | A new instance per call, because providers carry per-call state until C5 |
| `ClassifierInterface`, per configured name | Shared | Singletons built by `ProviderManager` (section 6) | Same: classifiers hold no per-call state today |
| `HttpClientInterface` | Shared per worker process | `LaravelHttpClient` singleton | Same |
| Embeddings providers, vector stores, `Indexer` (proposed, C21) | Shared, per configured name | Singletons (constructors do no I/O after C4) | Lazy singletons that are never resolved in `register()` or `boot()`; PDO-backed stores such as `MariaDBVectorStore` are built per job until C13; no `Indexer` until C21 |
| Tools and toolkits | Shared prototypes, cloned per call by `ToolNode` | Autowired singletons injected into definitions | Non-shared; clone before calling any configuration method |
| MCP connectors | Per worker with static credentials; per request or job with per-user credentials | `singleton()` for static credentials; per-user connectors are built per request or job from the actor captured at submission (section 11); `close()` on worker stop | Same lifetimes; no `close()` yet, references are dropped instead |
| `WorkflowResources` subclasses | Per segment | Resolved inside the `resources()` hook or a `setResources()` factory | Same |
| Nodes, middleware, stream adapters, channels | Per segment | Built by hooks and factories, never container services | Same |
| Gateway, projection store, event bridge, input translators, output mapper (proposed, C20) | Shared | Singletons | Until C14 the gateway comes from the `@internal` pre-release of `neuron-core/gateway`, shared with the Symfony bundle; there is no output mapper until C20 |
| Test fakes | Per test | `Neuron::fake()` in each test's fresh application | Same |

Two rules follow from the table and hold in every Laravel runtime. No singleton holds a workflow identity: identity lives on handles, which are values. And no singleton captures the request, the `Application` or the config repository: anything request-specific reaches a run as identifiers (the acting user's ID, the tenant ID) through its start event, its state or a submission record keyed by run ID (section 9.7), and a tool reads them there or derives them from `ToolContext` (proposed, C11), because the next segment of the same run may execute in a queue worker where no request exists. Credentials are never persisted: a per-user MCP token is looked up inside the worker from the actor captured at submission (section 11).

### 4.2 Definitions and handles

Today a Workflow instance fuses the definition (graph, hooks, collaborators) with its address. `setWorkflowId()` binds once and refuses to re-point, and executing an unbound instance generates an ID and keeps it on the receiver. A definition registered as a singleton therefore either throws on the second conversation, or, if it was never bound explicitly, continues the first caller's conversation for every later caller. Core improvement C2 separates the two. `WorkflowInterface::for(string $workflowId): static` returns a bound clone and never mutates the receiver, executing an unbound definition throws `UnboundWorkflowException` before any output, `setWorkflowId()` and `setThreadId()` disappear, and the per-segment hooks and factories receive the segment's `ExecutionContext`, so a container-owned closure never has to capture a definition to learn its address. With C2, C4 and C5 in place (all proposed), the resulting code is ordinary Laravel:

```php
#[AsWorkflow(name: 'support')] // proposed, C19
final class SupportAgent extends Agent
{
    public function __construct(
        #[NeuronProvider('support')] protected AIProviderInterface $llm,
        protected OrderLookupTool $orders,
        protected ReverbChannels $channels,
    ) {
        // No parent::__construct(): C4 (proposed) makes it unnecessary; call it against today's core.
    }

    protected function provider(): AIProviderInterface
    {
        return $this->llm;
    }

    protected function tools(): array
    {
        return [$this->orders];
    }

    // Proposed signature (C2): the hook receives the segment's context.
    protected function channel(ExecutionContext $context): ?StreamingChannelInterface
    {
        return $this->channels->thread($context->workflowId);
    }
}

final class ChatTurnController
{
    // The route authorizes $conversation with ->can('converse', 'conversation') before for() binds its ID (section 9.7).
    public function __invoke(ChatTurnRequest $request, Conversation $conversation, SupportAgent $agent): Responsable
    {
        $adapter = new VercelAIAdapter();
        $thread = $agent->for($conversation->neuronWorkflowId()) // proposed, C2: a bound clone, $agent is untouched
            ->setStreamAdapter(fn (ExecutionContext $context): VercelAIAdapter => $adapter);

        return NeuronStream::from($thread->stream($request->userMessage()), $adapter, 'support');
    }
}
```

A handle may be customized for one call, for example with `setStreamAdapter()` for a pull stream, without touching the shared definition. The converse is the rule that keeps a shared definition shared: definitions are configured at build time (constructor, hooks and the `afterResolving` callback) and customized per call only on handles. The setters stay public after C2, so a `setAiProvider()`, `setStreamAdapter()`, `subscribe()` or `addMiddleware()` call on an injected definition during a request changes it for every later request in the Octane or queue worker; call it on `$definition->for($id)` instead. Because `for()` clones, a subclass that keeps its own mutable fields (a cache, a counter) must define `__clone`, the same contract nodes and middleware already have. C2 also rebinds factory closures whose `$this` is the definition onto the handle, so closures registered inside the class keep working.

Against today's core the package registers every manifest class with `bind()`. Laravel 12 and 13 leave an unbound optional class-typed parameter at its default, so the inherited `?WorkflowState $state = null` of a subclass without its own constructor stays null. The package never binds `WorkflowState` in the container, because a binding would change that. Application code binds the instance right after resolution, `app(SupportAgent::class)->setThreadId($conversation->neuronWorkflowId())`, and never executes an unbound container-built instance. Long-lived services (Octane-resident singletons, commands that loop) hold the package's `WorkflowLocator` and resolve a fresh instance per call instead of holding a definition. Subclass constructors call `parent::__construct()` until C4 removes the Workflow constructor.

### 4.3 Defaults

Today setters beat hooks: `getPersistence()` returns `$this->persistence ??= $this->persistence()`, and the same holds for the serializer, the message store, the provider and the RAG services. A package that configured every definition through setters would silently override a class that declared its own `persistence()` hook. A package that did nothing would leave definitions on `InMemoryPersistence` and `InMemoryMessageStore`, which lose every run at the end of the request. Core improvement C3 adds one lowest-precedence tier, `setDefaults(Psr\Container\ContainerInterface $defaults): static` (proposed, C3), which the base hooks consult by interface FQCN before their core fallback. The precedence is explicit setter, then the class's own hook, then framework defaults, then core fallback, and handles made by `for()` carry the same defaults. The key list is closed and documented per module:

| Key | Package binding | Consulted by |
|---|---|---|
| `PersistenceInterface` | `neuron.persistence.driver` | Workflow |
| `Serializer` | `neuron.serializer.codec`, optionally signed (C12) | Workflow |
| `Psr\Clock\ClockInterface` | `CarbonClock` (C10) | Workflow and its engine |
| `Psr\EventDispatcher\EventDispatcherInterface` | `IlluminateEventBridge`, the forward target | Workflow |
| `BranchRunner` | `SequentialBranchRunner` unless configured | Workflow |
| `MessageStoreInterface` | `neuron.history.driver` | Agent |
| `AIProviderInterface` | `neuron.providers.default` | Agent |
| `OutputMapperInterface` | `LaravelOutputMapper` (C20) | Agent |
| `EmbeddingsProviderInterface` | `neuron.embeddings.default` | RAG |
| `VectorStoreInterface` | `neuron.vector_stores.default` | RAG |

The package implements the tier as a small PSR-11 class over its own bindings and applies it with one callback, which fires once per singleton because Laravel runs resolving callbacks only when it builds an instance:

```php
final class NeuronDefaults implements ContainerInterface
{
    /** @param array<class-string, string> $bindings interface FQCN => container abstract */
    public function __construct(protected array $bindings)
    {
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]);
    }

    public function get(string $id): mixed
    {
        // Resolved from the current container at call time: Octane sandboxes and fakes apply.
        return isset($this->bindings[$id])
            ? Container::getInstance()->make($this->bindings[$id])
            : throw new DefaultNotConfigured($id); // implements Psr\Container\NotFoundExceptionInterface
    }
}

// NeuronServiceProvider::register()
$this->app->afterResolving(Workflow::class, function (Workflow $definition, Application $app): void {
    $definition->setDefaults($app->make(NeuronDefaults::class)); // proposed, C3
    $app->make(DefinitionOptions::class)->apply($definition);   // explicit per-definition scalars
});
```

Laravel's container itself implements PSR-11, but the package does not hand it to definitions. A closed locator keeps the tier to the documented keys, so a binding the application makes for its own reasons never silently becomes a Neuron default, and `Neuron::fake()` swaps entries in one place. Scalars are not part of the tier. The lease and any other per-definition setting come from `neuron.workflows.options.<name>` and are applied with explicit setters such as `setLeaseTimeout()`, because explicit configuration is meant to win over the class's hook. Completion retention is not configuration at all: the gateway enables it on its own handles (section 8).

Against today's core, the same callback applies setters, but only where the class keeps the base hook. The check is `(new ReflectionMethod($definition, 'persistence'))->getDeclaringClass()->getName()` being `Workflow`, `Agent` or `RAG`, repeated for `serializer`, `messageStore`, `provider`, `embeddings`, `vectorStore` and `branchRunner`, and cached per class in the manifest that `neuron:cache` writes. `setEventDispatcher()` is always called, because the forward target has no hook. The provider is applied as a fresh instance per resolution (`setAiProvider($providers->provider())`), which is safe because interim definitions live for one call.

### 4.4 Identity

A workflow ID is the storage key of a run and, for an Agent, the thread ID of its conversation. The engine accepts any nonempty string of at most 255 characters without control characters that does not start with `__`. The package adds three rules. IDs are issued by the server: a thread is an application model (for example a `Conversation` with `HasUlids`) whose route key is the workflow ID, reported by `neuronWorkflowId()` (the package's `NeuronThread` contract, section 9.1) and created with its owner, and client-chosen IDs such as the Vercel `useChat` ID are never used as storage keys. The ID is untrusted input: route-model binding plus a policy authorizes it before `for()` is called (section 9). And a workflow that is naturally keyed by a business entity is addressed as `$orders->for('order-'.$order->getKey())`, while the `workflowId()` hook is reserved for singleton processes such as a nightly report, since `for()` with a different ID throws when the class declares one.

IDs that also name a broadcast channel must keep the whole channel name, `private-neuron.thread.` plus the ID, within Pusher's alphabet (`[-a-zA-Z0-9_=@,.;]`) and at most 164 characters, which `PusherChannel` enforces. With the default prefix that leaves 142 characters for the ID. ULIDs and keys such as `order-123` fit; `order:123` does not.

### 4.5 make() and resolution

`Workflow::make()` is `new static(...$arguments)`. It bypasses the container, so a definition built that way gets no constructor injection and no defaults, and falls back to in-memory persistence without an error. laravel/ai's `make()` resolves through the container, and Laravel developers will expect Neuron's to do the same. The package does not change `make()`, which would need global state in core; after C2, `make()` remains the script entry point that builds an instance bound to the ID its `workflowId()` hook declares, or to a fresh generated ID. Laravel code resolves definitions in one of three ways: constructor or method injection of the class, `app(SupportAgent::class)`, or by name through the facade, `Neuron::workflow('support')`, which reads the package's `WorkflowLocator`, a PSR-11 container of definitions keyed by their `#[AsWorkflow]` name. The locator is also what the gateway, the console commands and the optional routes use. Generator stubs never emit `make()` or `new` for definitions.

### 4.6 Composing definitions

An Agent or a RAG can run as a step inside a Workflow. The outer definition receives the inner one by injection and hands it to its nodes through a `WorkflowResources` subclass, because nodes do not know their workflow ID (`NodeContext` carries no `ExecutionContext`), so the child's address must come from the resources factory. The child is bound for each segment to an ID derived from the outer run, never to a generated one, so that recovery finds the same child thread:

```php
// Proposed signature (C2): the hook receives the segment's context; $this->drafter is injected.
protected function resources(ExecutionContext $context): DraftResources
{
    return new DraftResources($this->drafter->for($context->workflowId.'.draft'));
}
```

Today the hook takes no argument: it resolves a fresh instance from `WorkflowLocator` and binds it with `setThreadId($this->getWorkflowId().'.draft')`, which is safe because interim definitions are bound before they execute. The node wraps the child call in `memoize()`, so a committed child result is never requested again. Two limits remain with today's engine (section 21, open questions). A crash between the child's completion and the parent's memo commit starts the child again and repeats its inference and tool calls, because a synchronous child deletes its partition when it completes. And a child that suspends cannot suspend its parent, so children run without approval-gated tools or awaited events. Derived IDs count toward the 255-character limit and toward the channel-name budget of section 4.4.

### 4.7 Multi-tenant applications

Neuron has no tenant concept, and a workflow ID is a global key in its store. If the Neuron tables were shared across tenants while business keys were not, `for('order-42')` in a second tenant would drive the first tenant's run. Two layouts work. The recommended one keeps the Neuron tables central: `neuron.persistence.connection` and `neuron.history.connection` name a connection pinned to the central database, so tenancy packages that switch the default connection (stancl/tenancy, spatie/laravel-multitenancy) never redirect Neuron's writes, and one sweep serves every tenant. Thread IDs are ULIDs and stay unique; business keys carry the tenant, as in `$orders->for("t{$tenant->id}-order-{$order->id}")`. The tenant ID travels in the start event or the state, and tools, retrieval scopes (section 12) and channels read it there, or from `ToolContext` after C11. With per-tenant Neuron tables instead, the job must initialize tenancy before the gateway touches storage (for example with stancl/tenancy's queue bootstrapper), the projection lives in each tenant database, and the sweep runs once per tenant (`tenants:run neuron:wake` with stancl/tenancy). The one broken setup is the configuration default: a Neuron connection left at the application's default connection while a tenancy package switches it mixes both layouts, because every request, job and sweep then reads whichever database happens to be current. How each tenancy package behaves with both layouts is to verify.

## 5. Configuration

The package publishes one file, `config/neuron.php`. It is the only place where `env()` is called: under `config:cache`, `env()` outside configuration files returns only system variables, which is why generator stubs and Boost guidelines never read the environment in hooks (C23). Every section maps to the bindings described in the rest of this document. The sketch shows the full tree with its defaults.

```php
<?php

declare(strict_types=1);

return [

    // Definitions. Classes carrying #[AsWorkflow] (C19) under these paths are discovered and
    // cached by `php artisan neuron:cache`, which `optimize` runs. The map adds or overrides
    // names explicitly, and is the only source of names until C19 lands.
    'workflows' => [
        'paths' => [app_path('Neuron')],
        'map' => [
            // 'support' => App\Neuron\SupportAgent::class,
        ],
        // Explicit per-definition settings: they win over the class's hooks. 'versions' names the
        // queue that still serves each previous definition version (section 8.10).
        'options' => [
            // 'orders' => ['lease' => 900, 'mode' => 'queue', 'queue' => 'neuron-orders', 'prune_after_days' => 30,
            //              'versions' => ['1' => 'neuron-orders-v1']],
        ],
    ],

    // Durable workflow store. A dedicated connection (it may point to the same database)
    // keeps Neuron writes out of application transactions.
    'persistence' => [
        'driver' => env('NEURON_PERSISTENCE', 'database'), // database | eloquent | redis | file | memory
        'connection' => env('NEURON_DB_CONNECTION'),      // null: the default connection
        'table' => 'neuron_workflow_store',
        'model' => NeuronAI\Laravel\Storage\Models\WorkflowRecord::class, // eloquent driver
        'redis_connection' => 'neuron',                    // redis driver, phpredis only
        'path' => storage_path('neuron/workflows'),        // file driver, local development only
    ],

    // The codec of every durable record. Never change it while runs are suspended.
    'serializer' => [
        'codec' => 'php',           // php | igbinary
        'sign' => true,             // HMAC-sign records with APP_KEY, verify with APP_PREVIOUS_KEYS (C12)
        'accept_unsigned' => false, // transition mode for a store that already holds unsigned runs
    ],

    // Refuse to invoke inside an open transaction on the Neuron connection (section 7.3).
    'transaction_guard' => true,

    // Conversation store for Agents.
    'history' => [
        'driver' => env('NEURON_HISTORY', 'database'), // database | eloquent | file | memory
        'connection' => env('NEURON_DB_CONNECTION'),
        'table' => 'neuron_chat_messages',
        'model' => NeuronAI\Laravel\Storage\Models\ChatMessage::class, // eloquent driver
    ],

    // Named providers: 'driver' selects the class, every other key is a named constructor
    // argument. The shared HTTP client is injected by the package.
    'providers' => [
        'default' => env('NEURON_PROVIDER', 'anthropic'),
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        ],
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4.1'),
        ],
    ],

    'embeddings' => [
        'default' => 'openai',
        'openai' => ['driver' => 'openai', 'key' => env('OPENAI_API_KEY'), 'model' => 'text-embedding-3-small', 'dimensions' => 1536],
    ],

    'vector_stores' => [
        'default' => 'docs',
        'docs' => ['driver' => 'pgvector', 'connection' => env('NEURON_DB_CONNECTION'), 'table' => 'neuron_documents', 'dimensions' => 1536],
    ],

    // Classifiers (section 6): stateless, shared, built with the package's HTTP client.
    'classifiers' => [
        'default' => 'triage',
        'triage' => ['driver' => 'typesafe', 'key' => env('TYPESAFE_API_KEY'), 'model' => 'jev-latest'],
    ],

    'http' => [
        'client' => 'laravel', // laravel (Http factory) | curl | guzzle
        'timeout' => 300,      // H: must stay below every lease
        'connect_timeout' => 10,
    ],

    // Durable execution through the queue (section 8).
    'queue' => [
        'connection' => env('NEURON_QUEUE_CONNECTION'), // must be persistent: database, redis, sqs, beanstalkd
        'queue' => 'neuron',
        'timeout' => 540,           // T: job timeout, at most the shortest lease
        'retry_window' => 86400,    // retryUntil()
        'signal_window' => 3600,    // seconds from first dispatch a signal may wait for its wait to open
        'max_exceptions' => 5,      // node-failure budget; releases do not consume it
        'backoff' => [10, 60, 300], // delays between deliveries after a node failure
        'max_delay' => 900,         // longest delayed dispatch the transport accepts (SQS: 900)
        'encrypt' => false,         // ShouldBeEncrypted for payloads that carry personal data
        'sweep' => ['schedule' => true, 'batch' => 500],
    ],

    'broadcasting' => [
        'connection' => 'reverb',
        'channel_prefix' => 'neuron.thread.', // private-neuron.thread.{workflowId}
    ],

    // MCP servers: stdio ('command', 'args', 'env') or HTTP ('url', 'token'); 'only' and 'exclude' filter tools.
    // 'scope' is 'worker' (static credentials, one connector per worker) or 'actor' (per-user
    // credentials, a connector per request or job for the actor captured at submission, section 11).
    'mcp' => [
        'servers' => [
            // 'github' => ['url' => 'https://mcp.example.com', 'token' => env('GITHUB_MCP_TOKEN'), 'scope' => 'worker', 'only' => ['search_issues']],
        ],
        'expose' => ['tools' => []], // tools exposed through laravel/mcp (section 11), never the whole catalog
    ],

    'observability' => [
        'log_channel' => env('NEURON_LOG_CHANNEL'), // null: no LogListener
        'forward_events' => true,                   // IlluminateEventBridge
        'listeners' => [],                          // catch-all Neuron listeners (tracers, exporters)
    ],

    // Optional routes (section 9). Off by default: most applications write their own controllers.
    'routes' => [
        'enabled' => false,
        'prefix' => 'neuron',
        'middleware' => ['api', 'auth:sanctum'],
        'thread_model' => null, // an Eloquent model implementing NeuronAI\Laravel\Contracts\NeuronThread
        'ability' => 'converse', // the policy ability every thread route authorizes
        'signal_secret' => env('NEURON_SIGNAL_SECRET'),
    ],
];
```

Driver names map onto classes in one place per concern: persistence and history drivers in a `StorageFactory`, provider, embeddings and classifier drivers in `ProviderManager`, vector store drivers in `VectorStoreManager`. Each accepts custom drivers through `extend(string $driver, Closure $factory)`. After C5 every HTTP provider shares one constructor convention (key, model, parameters, base URI, HTTP client, then vendor extras by name), so a configuration entry maps one-to-one onto named arguments. Until then the keys must match each class's current parameter names, for example `max_tokens` for `Anthropic`.

The durability rules are validated by `neuron:cache` and when the gateway is first resolved, never with I/O at boot: in-memory and file persistence are refused outside the `local` and `testing` environments, the queue connection must be persistent (section 8.8), the timeouts must respect the leases (section 8.6), and `signal_window` must stay below `retry_window`, so an early signal is discarded before its job could fail (section 8.3). `neuron:cache` also checks that each vector store's `dimensions` matches the embeddings provider it is paired with (from configuration today, through `dimensions()` after C21), because a mismatch fails every insert and every search. A failed rule throws a descriptive exception, so a misconfigured deploy fails `optimize` instead of losing runs.

## 6. Providers and the HTTP layer

Providers are configured by name under `neuron.providers` and built by `ProviderManager`. An entry names a `driver` (one per built-in provider class, plus custom drivers registered with `extend()`), and every other key becomes a named constructor argument. The manager adds the shared HTTP client through each HTTP provider's `httpClient` argument. The default entry is registered under `AIProviderInterface` in the defaults tier, so an Agent that keeps the base `provider()` hook uses it without any code. A definition that needs a specific provider receives it by injection through a contextual attribute, the idiomatic Laravel way to inject a named service:

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
final class NeuronProvider implements ContextualAttribute
{
    public function __construct(public readonly ?string $name = null)
    {
    }

    public static function resolve(self $attribute, Container $container): AIProviderInterface
    {
        return $container->make(ProviderManager::class)->provider($attribute->name);
    }
}
```

The lifetime of what `provider()` returns depends on core improvement C5. Today a provider is mutable per call: `ChatNode` calls `systemPrompt()` and `setTools()` on it before every inference, `structured()` rewrites instance state, and stream state lives on the instance, where a later call on an OpenAI-family provider reads the reasoning metadata of an earlier stream. A cached provider shared by two agents, two Octane requests or two fibers would mix their prompts, tools and metadata. C5 moves instructions and tools into a per-call `ProviderRequest` and keeps stream state local, after which `ProviderManager` caches one instance per name, exactly like an Illuminate manager caches its drivers. Until C5 lands, `ProviderManager::provider()` builds a new instance on every call and shares only the HTTP client. Because an interim definition lives for one call and `getProvider()` re-reads the hook for every segment, this keeps every provider private to one execution. The same rule covers Vertex providers, which fetch an OAuth token in their constructor today (C4).

Classifiers are the one provider family that is already safe to share. The Classifier module (`ClassifierInterface::classify(ClassificationRequest): ClassificationResult`, with `Choice`, `Score` and `Boolean` questions) forbids storing request input or questions on a provider instance, and `TypeSafeAI` takes its HTTP client in the constructor. Classifiers are configured by name under `neuron.classifiers.<name>` (driver, key, model), built by `ProviderManager` with the shared HTTP client, so `Http::fake()` covers them, and registered as singletons today: `ClassifierInterface` resolves to the default entry and `ProviderManager::classifier($name)` returns a named one. A definition injects its classifier into a `WorkflowResources` subclass. Routing, thresholds and abstention stay in nodes, as the module prescribes, and a node wraps the call in `memoize()`, because a classification is paid and non-deterministic and recovery must reuse the committed answer:

```php
public function __invoke(TicketReceived $event, WorkflowState $state, TriageResources $resources): TicketRouted
{
    $team = $this->memoize('triage', fn (): string => $resources->classifier->classify(new ClassificationRequest(
        $event->body,
        ['team' => new Choice('Which team should handle this ticket?', ['billing' => 'Payments and invoices', 'tech' => 'Bugs and outages'])],
    ))->choice('team')->choice);

    return new TicketRouted($team);
}
```

Every HTTP-based provider, embeddings provider, vector store, classifier and MCP HTTP transport the package builds receives one `HttpClientInterface`: `LaravelHttpClient`, a package adapter over Laravel's HTTP client factory. It sends each request through a pending request of `Illuminate\Http\Client\Factory` with the `stream` option for SSE responses, and wraps the PSR-7 body in core's `GuzzleStream` (renamed `Psr7Stream` by C17). Going through the factory is what makes provider traffic a first-class Laravel citizen. `Http::fake()` and `Http::preventStrayRequests()` intercept it, `Http::assertSent()` sees it, the application's global HTTP middleware applies, and the `RequestSending`, `ResponseReceived` and `ConnectionFailed` events are dispatched; Telescope's HTTP client watcher records the last two (a streamed SSE body is recorded as a stream, not read). Bedrock (AWS SDK) and the SDK-backed stores (Elasticsearch, OpenSearch, Typesense, MongoDB) keep their own clients, so `Http::fake()` does not intercept them. Two details matter. Laravel's pending request disables Guzzle's `http_errors`, so the adapter checks the status itself and raises `HttpException` for every status of 400 or more, which is the uniform contract C17 makes mandatory for every client. And the pending request's own default timeout is 30 seconds, so the adapter always sets `neuron.http.timeout` and `neuron.http.connect_timeout` explicitly.

```php
final class LaravelHttpClient implements HttpClientInterface
{
    public function __construct(
        protected HttpFactory $http,
        protected float $timeout,
        protected float $connectTimeout,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $response = $this->pending($request)->send($request->method->value, $request->uri, $this->body($request));

        return $response->status() >= 400
            ? throw $this->failure($request, $response)   // HttpException, redacted (C17)
            : new HttpResponse($response->status(), $response->body(), $response->headers());
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        $response = $this->pending($request)->withOptions(['stream' => true])
            ->send($request->method->value, $request->uri, $this->body($request));

        return $response->status() >= 400
            ? throw $this->failure($request, $response)
            : new GuzzleStream($response->toPsrResponse()->getBody());
    }

    protected function pending(HttpRequest $request): PendingRequest
    {
        return $this->http->withHeaders($request->headers)
            ->timeout($request->timeout ?? $this->timeout)
            ->connectTimeout($this->connectTimeout);
    }
}
```

Two families are built in application code rather than by the managers, and receive the client there. The reranking post-processors (`JinaRerankerPostProcessor`, `CohereRerankerPostProcessor`, `LocalAIRerankerPostProcessor`) are created in a RAG's `postProcessors()` hook, so the RAG class receives `HttpClientInterface`, bound to `LaravelHttpClient`, by injection and passes it to their `httpClient` argument. The audio and image providers (OpenAI's speech, transcription and image classes, ElevenLabs, and ZAI's transcription and image classes) take the same argument today, and get their own configuration keys once C5 separates capabilities.

The `curl` and `guzzle` values of `neuron.http.client` select core's own clients for applications that do not want Laravel's HTTP stack in the path. The Amp client is not offered until C17's contract tests cover it: today it never checks the HTTP status (D3), and an error body parsed as an empty response would be memoized durably as a successful step. Two further provider defects are not worked around by the package, because they are fixed on the branch before its first release: `SSEParser` drops any `data:` line that contains the substring `DONE`, so a chunk such as "Task DONE." is lost (defect D2 in the core document), and the Anthropic and Chat Completions stream loops skip vendor error events instead of raising `ProviderException` (part of D3). C17 is the structural fix for both.

## 7. Durable storage

Durability is only as good as the store behind it, so the package makes the durable choice the default and refuses the non-durable ones in production.

### 7.1 Drivers

| Concern | Driver | Class | Notes |
|---|---|---|---|
| Workflow store | `database` (default) | `QueryBuilderPersistence` (package) | Query builder on the configured connection, resolved per operation. Lean path: an insert that treats `UniqueConstraintViolationException` as "already present", `lockForUpdate()` on the condition row inside a transaction for the conditional write and delete, one bulk delete to purge a partition, and the MySQL strict-mode check cached per PDO. It never uses `insertOrIgnore()` on MySQL, because `INSERT IGNORE` downgrades truncation errors to warnings. On SQLite, which ignores `lockForUpdate()`, a no-op `UPDATE` of the condition row first takes the writer lock, as core's backends do. |
| Workflow store | `eloquent` | `EloquentPersistence` with the `WorkflowRecord` model (moved from core by C13) | Model events, global scopes and casts participate. Costs more queries: purging a partition hydrates and deletes each row. |
| Workflow store | `redis` | core `RedisPersistence` over `Redis::connection(...)->client()` | Requires the phpredis client, checked at boot (Predis clients are refused). The backend sets no TTL, so Redis must use `noeviction` or a `volatile-*` eviction policy. |
| Workflow store | `file`, `memory` | core `FilePersistence`, `InMemoryPersistence` | Local development and tests only; refused elsewhere. |
| Conversation store | `database` (default) | `QueryBuilderMessageStore` (package) | `MessageStoreInterface` (`loadActive()`, `loadAll()`, `append()`, `archive()`, `clear()`) on the canonical `neuron_chat_messages` table through the query builder, on a connection resolved per operation. Available today. |
| Conversation store | `eloquent` | `EloquentMessageStore` with the `ChatMessage` model (moved from core by C13) | The model declares the `array` casts on `content` and `meta` and an insertion-ordered key, which the store requires. Model events and scopes participate. |
| Conversation store | `file`, `memory` | core `FileMessageStore`, `InMemoryMessageStore` | Local development and tests only. |
| Run projection | (always) | `QueryBuilderProjectionStore` (package) on `neuron_runs` | Implements the gateway's `ProjectionStoreInterface` (C14). |

After C13 core's `DatabasePersistence` also accepts `Closure(): PDO`, and `new DatabasePersistence(fn () => DB::connection('neuron')->getPdo(), 'neuron_workflow_store')` works on the same canonical table. The package still defaults to its query-builder backends because they participate in Laravel's query log, `DB::listen()`, Telescope and Pulse, and follow the connection manager through reconnects (for tenancy, see section 4.7). Core's `SQLMessageStore` gains the same connection closure with C13 and could then back the `database` history driver too, but the query-builder store keeps the same advantages. Every package backend extends the core contract test cases (`PersistenceContractTestCase`, `MessageStoreContractTestCase`, `ProjectionStoreContractTestCase`, proposed by C13); until core ships them, the package vendors a pinned copy of the repository's persistence and message store contract tests.

Workflow state must not contain Eloquent models. `PhpSerializer` serializes the whole object graph, so a model in state drags its relations and connection into every step record, and it may no longer unserialize after a deploy. State holds IDs; nodes load models through their resources.

### 7.2 Migrations and the canonical schema

`neuron:install` publishes three migrations through `publishesMigrations()`, which re-timestamps them: `neuron_workflow_store`, `neuron_chat_messages` and `neuron_runs` (section 8.4). They express the canonical schema that core defines in one place (C13): a surrogate `id` primary key, a unique `(partition, key)` or `(thread_id, message_id)` constraint, and `created_at`/`updated_at`. Rows are always physically deleted: no `SoftDeletes`, because the engine's cleanup must remove records. On MySQL and MariaDB, identifiers are ASCII with a binary collation. That makes them compare byte for byte, and it keeps the unique index on two 510-character columns within InnoDB's key-length limit, which a `utf8mb4` definition would exceed.

```php
return new class () extends Migration {
    public function getConnection(): ?string
    {
        return config('neuron.persistence.connection');
    }

    public function up(): void
    {
        $mysql = in_array(DB::connection($this->getConnection())->getDriverName(), ['mysql', 'mariadb'], true);
        $ascii = fn (ColumnDefinition $column): ColumnDefinition => $mysql ? $column->charset('ascii')->collation('ascii_bin') : $column;

        Schema::create(config('neuron.persistence.table', 'neuron_workflow_store'), function (Blueprint $table) use ($ascii): void {
            $table->id();
            $ascii($table->string('partition', 510));
            $ascii($table->string('key', 510));
            $ascii($table->longText('value')); // base64 of the serialized record
            $table->timestamps();
            $table->unique(['partition', 'key']);
        });
    }
};
```

The conversation table has an auto-incrementing `id` that orders the thread, `thread_id` (indexed), `message_id`, `role`, `content` and `meta` as long text, a nullable `archived_at`, timestamps, and a unique `(thread_id, message_id)` constraint. Table names carry the `neuron_` prefix and are configurable. Applications that do not use migrations for these tables can run `neuron:setup`, which provisions every store that implements `ManagedStoreInterface` (proposed, C4) with the same canonical DDL.

### 7.3 Transactions

Core's SQL backends deliberately join a transaction that is already open on their connection: `DatabasePersistence` runs each operation in a savepoint, and `EloquentPersistence` nests through Laravel's transaction manager. Inside an application transaction this is dangerous. A later rollback removes step commits whose LLM calls and tool side effects already happened, and other workers cannot see fences or claims until the outer commit. The package therefore adopts one rule: never run a workflow inside an application transaction on the persistence connection. It recommends a dedicated `neuron` connection, which may point to the same database; a separate connection has its own PDO and therefore its own transactions. The package's `InvokeWorkflow` job and its synchronous entry points (the streaming Responsable and the optional routes) refuse to invoke while `DB::connection($neuron)->transactionLevel() > 0`, and every queued continuation is dispatched after commit (section 8.8).

The guard, `neuron.transaction_guard` (default true), applies only when the persistence or history driver is a SQL driver on that connection, and `Neuron::fake()` disables it, since its stores are in memory. Tests need one more rule: `RefreshDatabase` wraps each test in a transaction on the connections listed in `$connectionsToTransact`, the default connection unless configured, so with `neuron.persistence.connection` left at null every invocation in such a test would be refused. Integration tests that exercise real SQL storage therefore use `DatabaseTruncation` or `DatabaseMigrations`, or a dedicated `neuron` connection left out of `$connectionsToTransact`.

### 7.4 Long-running workers

Under Octane and in queue workers the stores are singletons that live as long as the worker. The query-builder and Eloquent backends resolve their connection through Laravel's database manager on every operation, so `DB::reconnect()` and `DB::purge()` are followed automatically. Core's PDO-based stores (`DatabasePersistence`, `SQLMessageStore`, `MariaDBVectorStore` and the SQL toolkits) capture one PDO at construction today; the package never registers them as singletons until C13 lets them take a connection closure. Until then `MariaDBVectorStore` and the SQL toolkits are built per job or per segment (sections 11 and 12). `RedisPersistence` keeps the phpredis client of the named connection for the worker's lifetime and must never be handed a client in pipeline or transaction mode.

### 7.5 Retention

A completed run deletes its partition, immediately for a synchronous run, and after `acknowledge()` for a run driven by the gateway. Two kinds of run stay forever unless someone acts: a run suspended on an interruption without a deadline, and a failed run nobody recovers. `neuron:prune` walks the run projection and settles the runs of rows untouched for longer than the definition's `prune_after_days`. It abandons them through the definition's own `abandon()`, fenced by the projected run ID and attempt, so that Agent's guard, which refuses to abandon a conversation whose last message is an unanswered tool call, still applies.

That guard would refuse the most common stale case, an Agent thread suspended on an approval or a deferred tool result that nobody answers, on every run of the command, so the policy for those threads is explicit. The default, `--reject-pending`, answers every pending approval with `['reject', 'Expired by retention']` through `submitApprovalDecisions()` and every awaited tool result with an `error` through `submitToolResults()`, and dispatches the answer: the run completes with a recorded rejection and the history stays consistent, at the cost of one inference per thread. `--reset` calls `resetConversation()` instead, which bypasses the guard and also clears the history, and suits data erasure. The command reports every run it could not settle, such as a failed run whose history ends in a tool call, which only `--reset` or a `neuron:recover` settles. Until C9 it also removes the terminal rows that interim starts keep for `neuron.queue.retry_window` (section 8.12). Conversation messages follow the application's own policy: the `ChatMessage` model is `MassPrunable` for archived rows, and erasing a user's data settles each of the user's threads as the security checklist describes (section 18).

## 8. Durable execution runtime

This section describes how runs execute in queue workers: one job type, the reference gateway, the run projection that drives timers, the mapping from exceptions to queue verbs, the alignment of leases with queue timeouts, completion handling, Horizon, deploys, an end-to-end business workflow and the interim against today's core. The semantic part (fences, recovery, reconciliation, classification of refusals) lives in core's `NeuronAI\Gateway` (proposed, C14). The package supplies the transport, the storage of the projection, the scheduler and the translation of dispositions into Laravel's verbs.

### 8.1 The InvokeWorkflow job

There is exactly one job type, `NeuronAI\Laravel\Queue\InvokeWorkflow`, plus an `EncryptedInvokeWorkflow` subclass that only adds `ShouldBeEncrypted`. It carries `Invocation::toArray()` (proposed, C14), a JSON-safe array, and never an agent, a provider, a closure or a `PendingExecution`:

```json
{
  "kind": "resume|answer|signal|wake",
  "workflow": "support",
  "workflowId": "01J9Z7K2M4...",
  "runId": "run_01J9Z8Q4...",
  "attempt": 0,
  "interruptId": 3,
  "payload": {"call_01": "approve"},
  "signal": "order.approved"
}
```

`workflow` is the `#[AsWorkflow]` name, never a class name, so renaming or moving a class does not break jobs already in the queue. The worker resolves the definition by name through `WorkflowLocator`, which yields the same class configured with the same defaults as in the web process. One job type rather than one per kind keeps the queue configuration, Horizon tags and failure handling uniform; the kind is data.

```php
class InvokeWorkflow implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 0;          // unlimited attempts, bounded by retryUntil()
    public int $timeout;            // T, at most the shortest lease (section 8.6)
    public int $maxExceptions;      // node-failure budget; release() does not consume it
    public bool $failOnTimeout = false;

    /** @param array<string, mixed> $invocation Invocation::toArray() */
    public function __construct(
        public readonly array $invocation,
        public readonly int $firstDispatchedAt, // kept across release(), which re-queues this payload
    ) {
        $this->timeout = config('neuron.queue.timeout');
        $this->maxExceptions = config('neuron.queue.max_exceptions');
        $this->afterCommit();
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds(config('neuron.queue.retry_window'));
    }

    /** @return list<int> delays after a rethrown node failure; release() sets its own delay */
    public function backoff(): array
    {
        return config('neuron.queue.backoff');
    }

    public function handle(Gateway $gateway): void
    {
        $disposition = $gateway->invoke(Invocation::fromArray($this->invocation)); // proposed, C14

        match ($disposition->kind) {
            DispositionKind::Done => null,
            DispositionKind::RetryAt => $this->retryAt($disposition->retryAt),
            DispositionKind::Discard => $this->discard($disposition->reason),
            DispositionKind::Fail => $this->failOrReroute($disposition->error),
        };
    }

    /** Laravel's final failure: fail(), maxExceptions or retryUntil(). */
    public function failed(?Throwable $e): void
    {
        if ($e instanceof DefinitionVersionException) {
            return; // nothing is wrong with the run: its row keeps a finite recheck_at (section 8.10)
        }

        // Reconciles the projection row from inspect(); only a failed run is parked (section 8.4).
        app(Gateway::class)->exhausted(Invocation::fromArray($this->invocation)); // requested from C14
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['neuron', 'workflow:'.$this->invocation['workflow'], 'thread:'.$this->invocation['workflowId']];
    }

    protected function retryAt(int $timestamp): void
    {
        $signalWindowElapsed = $this->invocation['kind'] === 'signal'
            && now()->getTimestamp() - $this->firstDispatchedAt > config('neuron.queue.signal_window');

        // A signal that keeps arriving before its wait opens is dropped, never failed (section 8.3).
        $signalWindowElapsed
            ? $this->discard('signal window elapsed')
            : $this->release(max(1, $timestamp - now()->getTimestamp()));
    }

    protected function failOrReroute(Throwable $error): void
    {
        // A run ignited by another definition version goes to the queue that serves it (section 8.10).
        $rerouted = $error instanceof DefinitionVersionException
            && app(InvocationDispatcher::class)->dispatchToVersion($this->invocation, $error->recorded);

        if (!$rerouted) {
            $this->fail($error);
        }
    }

    protected function discard(string $reason): void
    {
        Log::channel(config('neuron.observability.log_channel'))->info('neuron.invocation.discarded', [
            'workflow' => $this->invocation['workflow'],
            'workflowId' => $this->invocation['workflowId'],
            'reason' => $reason,
        ]);
    }
}
```

The job reads the disposition through `kind` (a `DispositionKind`: `Done`, `RetryAt`, `Discard` or `Fail`), `retryAt`, `reason` and `error`, the read side C14 defines next to the static constructors `done()`, `retryAt()`, `discard()` and `fail()`; the Symfony bundle's handler reads the same four.

The job is never constructed directly by application code. Producers call the package's `InvocationDispatcher`, which stamps `firstDispatchedAt`, picks the connection and queue (the configured default, `neuron.workflows.options.<name>.queue`, or the queue of the run's definition version, section 8.10), selects the `ShouldBeEncrypted` subclass when `neuron.queue.encrypt` is on, clamps delays to `neuron.queue.max_delay`, and optionally adds `(new WithoutOverlapping($workflowId))->releaseAfter(5)->expireAfter($timeout + 60)`, where `$timeout` is T. The expiry is mandatory. A worker killed by its timeout never releases the lock, and a lock that never expires would hold every later invocation of the thread, the sweep's wakes included, until `retryUntil()` expires. The release delay avoids the default immediate re-release loop, and `dontRelease()` is never used because it deletes a legitimate answer or wake. The middleware only saves the cost of work a fence would refuse anyway; it is never relied on for correctness. Unknown exceptions are not caught by the job: the gateway rethrows them after the run is durably failed (section 8.5), so Laravel's `backoff()` and `maxExceptions` apply.

### 8.2 The gateway

`Gateway::invoke()` implements the handler algorithm once for every framework. The package constructs it as a singleton from the `WorkflowLocator`, the shared `WorkflowEngine`, the projection store, the clock and the completion handler, and never duplicates its logic; until C14 ships, the class comes from the `neuron-core/gateway` pre-release (section 8.12). The algorithm, specified in [neuron-core-improvements.md](neuron-core-improvements.md), is:

1. Resolve the definition by name, bind it with `for()`, and enable completion retention on the handle.
2. For a wake, `inspect()` first, which writes nothing. A stale fence is discarded; a deadline still in the future returns `retryAt(deadline)`.
3. Write the projection ahead: status executing, `recheck_at` = now + lease + sweep interval, so a worker killed before it reconciles is still found by the sweep.
4. Run the fenced request.
5. Reconcile from the returned state. A suspended run upserts the projection with the interrupt ID, type, event name, deadline and definition version. A completed run calls the completion handler, then `acknowledge($runId)`, then forgets the projection.
6. Map refusals by type (C6). On a fence refusal, inspect: if the run is the invocation's own, converge (a failed run gets a fenced inputless recovery, a suspended one is reconciled, a completed one has its retained outcome recorded and acknowledged, a running one under a lease returns `retryAt` at lease expiry); otherwise discard.

Around the gateway, the package adds the fast path for timers. Its projection store is decorated by `SchedulingProjectionStore`, which, when the gateway saves a suspended projection whose deadline falls within `max_delay`, dispatches a delayed `Invocation::wake()` after commit. SQS caps delays at 15 minutes, and Redis, database and Beanstalkd connections accept longer ones; anything the fast path cannot schedule is picked up by the sweep. Keeping this in the store decorator lets the core gateway stay free of any queue concept.

### 8.3 Producers

A new queued turn is admitted synchronously at the HTTP edge and executed by a worker. With C9 the edge calls `ignite()`, which persists the run as `Pending` without executing it. A thread that is busy therefore produces a 409 before the 202, not a failed job later, and a lost dispatch is visible as a pending run that the sweep re-dispatches. The worker receives a `resume` invocation fenced by the returned run ID and attempt 0. Before it pushes the job, `InvocationDispatcher` records the snapshot that `ignite()` returned as a projection row: status `pending`, the run ID and attempt 0, and `recheck_at` = now + one sweep interval plus a dispatch grace. The sweep reads only `neuron_runs`, so without this row a pending run whose dispatch was lost (or dropped by a rolled-back `afterCommit()`) could never be found. The row is written before the push, and a duplicate re-dispatch is harmless because the resume is fenced.

```php
public function store(ChatTurnRequest $request, Conversation $conversation, SupportAgent $agent, InvocationDispatcher $queue): JsonResponse
{
    $snapshot = $agent->for($conversation->neuronWorkflowId())->ignite(ExecutionRequest::start( // proposed, C2 and C9
        new AgentStartEvent([$request->userMessage()], new AgentRunOptions(stream: true)),
    ));

    $queue->dispatch(Invocation::resume('support', $snapshot)); // proposed, C14

    return response()->json([
        'workflowId' => $snapshot->workflowId,
        'runId' => $snapshot->runId,
        'channel' => 'private-neuron.thread.'.$snapshot->workflowId,
    ], 202);
}
```

Answers (approval decisions, deferred tool results, AG-UI and Vercel continuation envelopes) are validated eagerly on the bound handle: `submitApprovalDecisions()`, `submitToolResults()` and `submitInputs()` translate the payload against the current interrupt and throw before anything is queued, so malformed input is a 422 in the request (with C6's typed exceptions; section 9.5 gives the interim). The edge then queues `Invocation::answer('support', $id, $pending->request())`, where `PendingExecution::request()` (proposed, C9) exposes the fenced request the submission captured. With C8, answers, signals and timer wakes fence on the interrupt ID rather than the execution attempt, so an answer redelivered after a worker crash converges instead of being refused as stale. Signals come from HMAC-signed webhook endpoints (section 9) and become `Invocation::signal()`. Wakes come from the fast path and from the sweep. Synchronous SSE turns do not use the queue, but the streaming Responsable hands the returned state to `Gateway::reconcile()`, so a synchronous turn that suspends on an approval with an expiry gets its timer too.

Signals answer the current interrupt only. The engine buffers nothing for a wait that has not opened yet or that is deferred behind another interrupt: signals are neither broadcast nor queued for future waits. The gateway answers a signal whose wait is not open with `retryAt`, which covers a webhook that races the node opening its wait, and the job releases it until `neuron.queue.signal_window` has passed since the invocation's first dispatch, which the job carries. Then the signal is discarded with a log entry, never failed. A signal that arrives while the run waits on something else for longer than the window is therefore lost, for example a payment webhook that arrives while the run is still suspended on an approval. Workflows that must not lose such events record them in an application inbox from the webhook handler, and the node checks the inbox (inside `memoize()`) before it calls `awaitEvent()`.

### 8.4 Run projection, timers and the scheduler

The run projection is a table the package owns, `neuron_runs`, with one row per workflow ID:

| Column | Meaning |
|---|---|
| `workflow_id` (primary key) | The run's address |
| `workflow` | The `#[AsWorkflow]` name |
| `run_id`, `attempt` | The fences observed at the last reconciliation |
| `status` | `pending`, `executing`, `suspended` or `failed`; until C9 also `completed`, the terminal row of an interim start (section 8.12) |
| `interrupt_id`, `interrupt_type`, `event_name` | The current interruption, for routing signals and listing waits |
| `deadline_at` (indexed) | `InterruptRequest::deadline()` (proposed, C7) of the current interruption |
| `recheck_at` (indexed) | When the sweep should look at a pending, executing or failed run again |
| `definition_version` | The version stamped at ignition (C12) |
| `updated_at` | Last reconciliation |

A row is due when it is suspended with `deadline_at <= now`, or pending, executing or failed with `recheck_at <= now`. The `neuron:wake` command calls `Gateway::due()`, which inspects each due run and yields fenced wakes for due deadlines and expired executions, and a resume at attempt 0 for a pending run whose dispatch was lost (C9); it forgets rows whose run is gone. The command dispatches what it yields through `InvocationDispatcher`. The provider registers it with `$this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('neuron:wake')->everyMinute()->withoutOverlapping(10)->onOneServer())` unless `neuron.queue.sweep.schedule` is off; `onOneServer()` needs a cache store with atomic locks. The mutex expiry is set to a few minutes, because a sweep killed mid-run would otherwise hold the default 24-hour lock and stop every timer. Early or duplicate wakes are no-ops, because the handler inspects before it acts and every write is fenced.

The job's final failure (its `failed()` method, reached through a `fail` disposition, `maxExceptions` or `retryUntil()`) never writes the row blindly, because the projection is derived only from returned state or `inspect()`. It hands the invocation to `Gateway::exhausted()`, which applies C14's terminal-failure rule, the same rule the Symfony bundle applies when Messenger gives up; this document asks C14 to expose it as that method, and the pre-release does so until then. The gateway inspects the run and reconciles the row from the snapshot. Only a run that `inspect()` reports as failed is parked, with status `failed` and `recheck_at` null, and the sweep then leaves it to `neuron:recover` or `neuron:prune`. A run that is still suspended, for example after an answer that failed validation, keeps its suspended row and its deadline, so an approval expiry or a `sleepUntil()` still fires. A running run keeps the write-ahead's recheck, and a run that no longer exists has its row forgotten. A failed row keeps a finite `recheck_at` only while the queue still owns its retry, for example the `Retry-After` of a `RetryableHttpException`, as the backstop for a lost release.

Besides the three methods of C14's `ProjectionStoreInterface` (`save()`, `forget()`, `due()`), the package's projection store answers `find($workflowId)`, which the signal route (section 9.7), version routing (section 8.10) and the interim start rule (section 8.12) read. The core document is asked to add it to the interface, so that the shared gateway and the Symfony bundle read rows the same way; until then the pre-release declares it.

The projection is an expiring hint, never the truth. It is written only from returned state or from `inspect()`, never from event listeners: `WorkflowInterrupted` is reported through the isolating path, which swallows listener failures, so a timer scheduled by a listener could be lost silently. Every row the sweep must revisit carries a finite recheck time, so a lost projection write delays the run by at most the write-ahead's recheck horizon, the lease plus one sweep interval (section 8.6). The same table powers `neuron:runs`, the retention of `neuron:prune` and operational dashboards.

### 8.5 Exceptions, queue actions and HTTP statuses

Every refusal that depends on run state is a typed exception after C6, and both the job and the exception handler branch on the type. The names are C6's vocabulary, plus `UnboundWorkflowException` (C2), `DefinitionVersionException` (C12) and `RetryableHttpException` (C17); of these, only `RunInFlightException`, `StaleWorkflowRunException` (which C6 reparents under `StaleRequestException`) and `InputTranslationException` exist today. The table is the same in the Symfony bundle; only the queue verbs differ.

| Exception | Situation | Queue action in `InvokeWorkflow` | HTTP status at the edge |
|---|---|---|---|
| `RunInFlightException` | Running under a fresh lease | `release()` until `retryAt()` | 409 with `Retry-After` |
| `RunInFlightException` | Suspended on another run | Discard and log | 409 with the interrupt JSON |
| `RunInFlightException` | Completed and not yet acknowledged | Dispatch a wake for that run, then release | 409 with `Retry-After: 1` |
| `RunInFlightException` | Pending (C9): ignited, not yet executed | Not raised in workers, which continue pending runs and never start them | The edge saves the missing `pending` projection row, then answers 409 |
| `StaleRequestException` family | The run is the invocation's own | Inspect and converge (recover, reconcile, acknowledge or release) | 409 |
| `StaleRequestException` family | Another generation holds the address | Discard and log | 409 |
| `NoRunInFlightException` | No persisted run | Discard, forget the projection row | 404 |
| `InvalidInputException`, `InputTranslationException` | An answer | `fail()`: unrecoverable, goes to `failed_jobs` | 422 |
| `InvalidInputException` | A signal that arrived before its wait | `release()`, since early arrival is legitimate, until `neuron.queue.signal_window` has passed since the first dispatch; then discard and log, never fail | 202 at the webhook, then queued retries |
| `ConcurrentUpdateException` | A lost race | `release()` after one to three seconds of jitter | 409 with `Retry-After: 1` |
| `DefinitionVersionException` | The run was ignited by another definition version | Not executed on this worker. The job re-dispatches the invocation through `InvocationDispatcher` to the queue of the recorded version (`$e->recorded`), and uses `fail()` only when no queue serves that version, in which case the row keeps a finite `recheck_at` and is not parked | 409 |
| `RetryableHttpException` | Provider 429 or 5xx; the run is failed durably | `release()` until `Retry-After`; recovery reuses committed steps | 503 with `Retry-After` in synchronous mode |
| `UnrecoverableException` | Exhausted structured output, `ToolRunsExceededException` | `fail()` | 500, or 422 for input |
| `UnboundWorkflowException` | A definition executed without `for()` | `fail()`: a bug | 500 |
| Any other `Throwable` from a node | The run is failed durably | Rethrown: `backoff()` and `maxExceptions` apply; the next delivery recovers | 500 |

Version routing normally happens at dispatch, from the `definition_version` of the run's projection row (section 8.10), so a `DefinitionVersionException` in a worker only signals a missing or stale row.

### 8.6 Lease and timeout alignment

A lease is the engine's evidence that a worker is alive. It is renewed with every step commit, so it must outlive the longest silent stretch of a run, and queue timeouts must be chosen so that a duplicate delivery never takes over a live run. With L the lease (600 seconds for an Agent), H the HTTP client's timeout, S the longest silent node (until C11, the whole tool loop of one Agent step, since memo commits do not renew the lease yet), T the job timeout, R the connection's `retry_after` (the visibility timeout on SQS) and W the sweep interval, the rules are:

- H < L and S < L: the lease must outlive every silent stretch.
- T ≤ L: a job killed by its timeout leaves a lease that its redelivery respects.
- R > T: Laravel's own rule; otherwise a job still running is delivered twice.
- A stale projection row is rechecked after L + W.

For an Agent with the defaults, H = 300, L = 600, T = 540, R = 600 and W = 60 satisfy every rule, and the Laravel default `retry_after` of 90 seconds does not: the package's queue connection needs its own `retry_after`. A plain Workflow has no lease by default, and a redelivered inputless continuation could then claim a run that is still executing, so every plain Workflow run through the gateway must declare `leaseTimeout()` or receive `neuron.workflows.options.<name>.lease`. `neuron:cache` checks these rules against configuration and against the class hooks it can see by reflection, and fails `optimize` when they are violated.

### 8.7 Completion

The gateway always retains completions on its handles. The completion handler runs before `acknowledge()`, so a crash between the two replays the retained outcome on the next delivery: delivery is at least once, and handlers must be idempotent by run ID. The package's default `CompletionHandlerInterface` implementation dispatches a `NeuronAI\Laravel\Events\WorkflowCompleted` event (workflow name, workflow ID, run ID and the final state) synchronously; applications bind their own handler when they prefer. Listeners that queue work must copy what they need out of the state first, because a `WorkflowState` is not a queue payload. Synchronous HTTP runs do not retain: their caller already holds the returned state, and the partition is deleted as soon as the run completes. A synchronous turn that arrives in the short window between a gateway completion and its acknowledgement receives a 409 with `Retry-After: 1`.

### 8.8 Transactions and dispatch timing

Invocations are dispatched after the application's transaction commits (`afterCommit()` on the job), so a worker never picks up work for rows that were rolled back, and they never run inside a transaction on the Neuron connection (section 7.3). When a new thread depends on domain rows written in the same request, the controller ignites inside `DB::afterCommit()` or after its transaction, so the run and the domain data commit in a well-defined order. The queue connection must be persistent (database, Redis, SQS or Beanstalkd). `sync`, `deferred` and `background` run the job in or next to the request without redelivery, `null` discards it, and a `failover` connection that lists any of them inherits the problem, so all are refused for durable execution.

### 8.9 Horizon

Horizon manages Redis queues, and the package ships a recommended supervisor for the `neuron` queue whose `timeout` equals T and whose connection's `retry_after` equals R, so section 8.6 holds for Horizon workers as it does for `queue:work`. Tags `workflow:<name>` and `thread:<id>` make every invocation of a thread searchable. Two Horizon behaviours need documenting. A `release()` increments the job's attempt count, which Horizon displays, so a busy thread that is released until its lease expires shows many attempts even though nothing failed (how Horizon's dashboards count releases is to verify). And a job failed with `fail()` lands in `failed_jobs`, where `queue:retry` simply redelivers the same invocation, which the fences make safe (for interim starts, together with the start rule of section 8.12).

### 8.10 Deploys and definition versions

Runs suspended for days outlive every deploy, and a rolling deploy runs old and new workers side by side. A change is compatible when every record already stored still means the same thing to the new code: new definitions, and edits inside a node that keep the node classes, their order, and the event, state and interrupt classes and shapes a run has persisted. Renaming, moving or reordering nodes is incompatible, because step keys are the node class plus its traversal index (C12's `stepKey()` lets a renamed node keep its key). So is renaming or moving an event, state or interrupt class, or changing the shape of a stored property.

For an incompatible change, the definition declares a new version with `#[AsWorkflow(name: 'orders', version: '2')]` (proposed, C12 and C19), and configuration names a queue for each previous version the package still serves, `neuron.workflows.options.orders.versions => ['1' => 'neuron-orders-v1']`. `InvocationDispatcher` reads `definition_version` from the run's projection row and dispatches to that version's queue; runs of the current version use the definition's normal queue. The deploy keeps one Horizon supervisor, or one `queue:work` process, of the previous release consuming that queue until `neuron:runs --definition-version=1` is empty, then retires it and removes the entry. `queue:restart` and `horizon:terminate` let each worker finish the job in hand, which can take up to T. A worker killed sooner by the orchestrator's grace period is recovered through its lease, which delays the thread by up to L, so the grace period should exceed T. Two settings must never change while runs are suspended: `neuron.serializer.codec`, and signing, which is enabled on an existing store only through the transition mode (`neuron.serializer.accept_unsigned`), since the signing decorator would otherwise refuse every unsigned record already stored.

Until C12 there is no version stamp and no typed incompatibility: before an incompatible deploy, the affected definition's suspended runs are drained or abandoned, and moved classes keep `class_alias` shims.

### 8.11 Business workflows end to end

The sketches so far are Agent chat turns started from a controller, but the same runtime drives business processes that start from a domain event, wait for a third party with a deadline and report their outcome to the application. An order that waits up to three days for its payment:

```php
#[AsWorkflow(name: 'order-payment')] // proposed, C19
final class OrderPaymentWorkflow extends Workflow
{
    protected function nodes(): array
    {
        return [new AwaitPaymentNode(), new FulfilOrderNode(), new CancelOrderNode()];
    }

    protected function leaseTimeout(): ?int
    {
        return 300;
    }
}

final class AwaitPaymentNode extends Node
{
    public function __invoke(OrderPlaced $event, WorkflowState $state): PaymentCaptured|PaymentExpired
    {
        $deadline = $this->memoize('deadline', fn (): DateTimeImmutable => new DateTimeImmutable('+3 days'));
        $payment = $this->awaitEvent('payment.captured', expiresAt: $deadline);

        return $payment === null
            ? new PaymentExpired($event->orderId)
            : new PaymentCaptured($event->orderId, $payment['reference']);
    }
}

final class StartOrderPayment // listens to the application's OrderSubmitted event (ShouldDispatchAfterCommit)
{
    public function __construct(protected OrderPaymentWorkflow $workflow, protected InvocationDispatcher $queue)
    {
    }

    public function handle(OrderSubmitted $submitted): void
    {
        $orderId = $submitted->order->getKey();

        $snapshot = $this->workflow->for('order-'.$orderId) // proposed, C2
            ->ignite(ExecutionRequest::start(new OrderPlaced($orderId))); // proposed, C9

        $this->queue->dispatch(Invocation::resume('order-payment', $snapshot)); // proposed, C14
    }
}
```

The start comes from an application event rather than an HTTP request. Because `OrderSubmitted` implements `ShouldDispatchAfterCommit`, its listener runs after the order's transaction commits, so `ignite()` never runs inside the application's transaction (section 7.3). The deadline is memoized, so a recovery reuses it instead of computing a new one; with C10, the node reads the current time from a clock in its resources, so that `travel()` applies to it too. When the deadline passes, the sweep wakes the run and `awaitEvent()` returns null. Third-party webhooks that use their own signature scheme do not go through `VerifyNeuronSignature`, which covers senders the application controls. Stripe events, for example, enter through Cashier's `WebhookReceived` event, whose signature Cashier's middleware verifies when a webhook secret is configured, and an application listener maps `payment_intent.succeeded` to `Invocation::signal('order-payment', 'order-'.$orderId, 'payment.captured', ['reference' => $intent['id']])`, reading the order ID from the payment intent's metadata, and dispatches it through `InvocationDispatcher`. A batch dispatched by a node signals the workflow the same way from its `then()` callback. The outcome arrives in the completion handler (section 8.7), whose `WorkflowCompleted` event carries the final state. In a multi-tenant application the business key also carries the tenant (section 4.7).

### 8.12 Against today's core

The whole runtime ships before the core proposals land. Until C14 ships, the gateway lives in one `@internal` pre-release of `neuron-core/gateway`, written once against today's core with the surface of C14 and shared with the Symfony bundle. The package depends on it and never re-implements it, and the dependency is replaced by `NeuronAI\Gateway` when C14 ships. The pre-release holds the `start` kind, the `inspect()` checks, attempt re-fencing and the `instanceof` deadline ladder; the package keeps only what is idiomatic: `InvokeWorkflow`, `InvocationDispatcher`, the stores and `SchedulingProjectionStore`. The differences from the target are few and local:

- Starts (until C9): the invocation has a `start` kind carrying `input`, the start event encoded with the configured `Serializer` and base64-encoded, plus a reserved run ID minted at dispatch (`'run_'.Str::ulid()`, which fits the reserved-ID pattern of `ExecutionRequest::start()`). `InvocationDispatcher` writes a `pending` projection row naming the reserved run before it pushes the job. Its `recheck_at` lies at the end of `neuron.queue.retry_window`, because the sweep cannot re-dispatch a start whose event lives only in the message; a row that comes due with no run marks a start that was lost or expired, and is forgotten.
- Start deduplication (until C9): the pre-release applies one rule to every delivery of a start. A `start` ignites, with `ExecutionRequest::start($event, runId: $reserved)`, only while `inspect()` finds no run and the projection row still names the reserved run ID with a non-terminal status. A `RunInFlightException` naming the reserved run switches it to `ExecutionRequest::resume(expectedRunId: $reserved)`, which recovers a failed run, meets the lease of a live one or converges on a retained completion. Any other delivery is discarded. Before `acknowledge()`, the gateway marks the row terminal (status `completed`, which the due query never selects, and which exists only for interim starts) and keeps it for `neuron.queue.retry_window`; `neuron:prune` removes it. A delivery after the acknowledgement therefore finds no run and a terminal row and is discarded, and because the mark comes before the acknowledgement, no crash can re-ignite the run. The write-ahead moves the row to `executing` without changing the run it names, so a worker that dies between the write-ahead and the ignition leaves a row that still admits the redelivered start, and the turn is not lost.
- Admission (until C9): the edge's `inspect()` pre-check is racy, so a worker can still meet a busy thread. A refused admission opens no segment and therefore no channel, so the pre-release reports the refusal through a small publisher port that each package implements. The Laravel implementation publishes directly with `Broadcast::connection(config('neuron.broadcasting.connection'))->getPusher()->trigger()` on the thread channel, carrying the refusal's structured fields (status, run ID, retry time); without broadcasting, the client sees the refusal on the thread endpoint.
- Answers (until C8 and C9): `PendingExecution` hides its request, so the edge calls `inspect()` and the translator itself and queues the run ID, the execution attempt, the observed interrupt ID and the translated payload. A worker that meets a stale attempt inspects; if the same run still waits on the same interrupt ID, it re-fences with the current attempt and delivers again, and otherwise it discards.
- Refusals (until C6): the engine's only typed refusals today are `RunInFlightException` and `StaleWorkflowRunException` (`InputTranslationException` is raised at submission, at the edge); a stale attempt or a fresh lease on a continuation is a plain `WorkflowException`. The pre-release inspects before every continuation and compares run ID, attempt and interrupt ID itself, which removes most refusals before they happen; any other `WorkflowException` gets a bounded backoff and then fails. It never matches exception messages.
- Deadlines (until C7): an `instanceof` check on `SleepUntilRequest::getWakeAt()` and `WaitForEventRequest::getExpiresAt()` (which also covers approvals and tool-result waits). Wakes inspect first, because an inputless poll of a suspended run writes a checkpoint today.
- Lease delays: computed from `RunInFlightException::$leaseExpiresAt` where it is present; otherwise a fixed backoff equal to the lease.
- Time (until C10): the engine reads `time()`, so leases and deadlines follow the wall clock regardless of `travel()`.

## 9. The HTTP edge

The HTTP edge must authorize the thread before anything touches storage, report admission conflicts as status codes before the first byte, keep executing when the client disconnects so the answer is committed, and hand queued work to the gateway. The package ships the building blocks (a streaming Responsable, form requests, the exception mapping, a signature middleware) and an optional set of routes built from them.

### 9.1 Routes and controllers

Most applications write their own controllers from the building blocks, as the sketches in this document do. The optional routes, enabled with `neuron.routes.enabled`, cover the common endpoints under a configurable prefix and middleware group:

| Method and path | Purpose | Core calls |
|---|---|---|
| `POST /neuron/{workflow}/threads` | Create a thread with a server-issued ID and an ownership record | `NeuronThread::openFor()` |
| `POST /neuron/{workflow}/threads/{thread}/turns` | A new turn, streamed as SSE or answered with 202 and a channel | `stream()`, or `ignite()` (C9) and a `resume` invocation |
| `GET /neuron/{workflow}/threads/{thread}` | Status, pending approvals, the interrupt JSON, a history page, AG-UI hydration | `inspect()`, `pendingApprovals()`, `MessageStoreInterface::loadAll()`, `AGUIAdapter::hydrate()` |
| `POST /neuron/{workflow}/threads/{thread}/approvals` | Approval decisions | `submitApprovalDecisions()` |
| `POST /neuron/{workflow}/threads/{thread}/tool-results` | Deferred and frontend tool results | `submitToolResults()` |
| `POST /neuron/{workflow}/threads/{thread}/agui` | AG-UI protocol endpoint | `AGUIRequest::fromPayload()` (C15), `AGUIInputTranslator` |
| `POST /neuron/{workflow}/threads/{thread}/vercel` | Vercel AI SDK protocol endpoint | `VercelAIRequest::fromPayload()` (C15), `VercelAIInputTranslator` |
| `POST /neuron/signals/{workflow}/{workflowId}/{event}` | HMAC-signed signal webhook | `Invocation::signal()` (C14) |

The routes are bound to an application model named in `neuron.routes.thread_model`, which implements a small package contract. The thread record is application data (it belongs to a user, a team or a tenant), so the package only asks the model to create itself and to report its address:

```php
interface NeuronThread
{
    /** Create and persist a thread owned by $user for the named definition. */
    public static function openFor(Authenticatable $user, string $workflow): static;

    /** The definition this thread belongs to. */
    public function neuronWorkflow(): string;

    /** The server-issued workflow ID, usually the route key. */
    public function neuronWorkflowId(): string;
}
```

The route checks that `neuronWorkflow()` matches the `{workflow}` segment. Without that check, the same workflow ID could be driven by another definition, which would replay one definition's persisted steps under another definition's code. Every thread route also authorizes the bound model with `->can(config('neuron.routes.ability'), 'thread')`, where `neuron.routes.ability` defaults to `converse`. The application's policy for the thread model decides ownership, and the same policy authorizes the broadcast channel (section 10).

Whether a turn streams or queues is decided per route or per definition (`neuron.workflows.options.<name>.mode`). Streaming suits short chat turns. Queued mode, a 202 plus a push channel (section 10), is the default for business workflows, long tool loops and anything that may exceed a proxy's idle timeout, which is often around 60 seconds for nginx, load balancers and CDNs.

### 9.2 The streaming Responsable

`NeuronStream` turns a Neuron generator into a Laravel streamed response. Its factory primes the generator inside the controller, so admission, graph construction and the first protocol frame happen before Laravel sends any header, and an exception at that point becomes an ordinary exception that the handler renders as 409, 404 or 422. The first frame is the adapter's `start()` output when it has one (`AGUIAdapter` emits `RUN_STARTED`). `VercelAIAdapter` and `AgentChunkAdapter` start empty, so priming runs the first node until it streams, and on a continuation that can mean executing an approved tool before any header is sent. Only admission refusals surface as exceptions; a node failure during priming becomes the adapter's error frames under a 200. Continuations whose first step is a long tool therefore belong in queued mode. With C15 the priming, header merging and draining live in core's framework-neutral `SSEStream`:

```php
final class NeuronStream implements Responsable
{
    protected function __construct(protected SSEStream $stream, protected ?string $workflow)
    {
    }

    public static function from(Generator $events, ?StreamAdapterInterface $adapter = null, ?string $workflow = null): self
    {
        return new self(SSEStream::open($events, $adapter), $workflow); // proposed, C15: primes now
    }

    public function toResponse($request): StreamedResponse
    {
        // Under PHP-FPM, Laravel flushes after every chunk only when the closure is a generator.
        return response()->stream(function (): Generator {
            try {
                $state = yield from $this->stream->frames(); // ignore_user_abort(true), drains after a disconnect
            } catch (Throwable $e) {
                report($e); // the run is already failed durably, and the adapter has sent its error frame
                return;
            }

            if ($this->workflow !== null) {
                app(Gateway::class)->reconcile($this->workflow, $state); // proposed, C14: timers of a synchronous suspension
            }
        }, 200, $this->stream->headers()); // SSE headers merged with the adapter's headers() (C15)
    }
}
```

`response()->eventStream()` is never used. It frames every item itself as an `update` event, JSON-encodes non-string items, appends a `</stream>` end event, stops iterating as soon as `connection_aborted()` is true, and reports exceptions instead of letting them reach the stream. Each of these behaviours is wrong for `SSEEncoder`'s already framed output and for the AG-UI and Vercel protocols. The Responsable also never touches the session: Laravel saves the session before the streamed body is sent, and nothing in the callback may write to it.

Against today's core, the Responsable primes with `$events->current()`, which is safe because `SSEEncoder::encode()` iterates with `valid()`, `current()` and `next()` and never rewinds. It takes headers from the concrete adapter's `getHeaders()` when there is one (`AGUIAdapter` and `VercelAIAdapter` have it), drops the hop-by-hop `Connection` header that `AGUIAdapter` sends, which is invalid over HTTP/2 and HTTP/3, and otherwise sends `Content-Type: text/event-stream`, `Cache-Control: no-cache` and `X-Accel-Buffering: no`. It encodes with `SSEEncoder::encode()` inside its own drain loop.

### 9.3 Admission and disconnects

When the browser tab closes, PHP notices at the next write, and a stream that stops iterating destroys the generator. Today that leaves the run marked running under its lease: `Segment::run()` only reports `WorkflowEnd` in its `finally`, so the thread refuses new turns, inputless recovery, `abandon()` and `resetConversation()` until the Agent's 600-second lease expires. The package prevents it in the common case: `frames()` sets `ignore_user_abort(true)`, keeps consuming the generator after `connection_aborted()` without emitting anything, so the turn completes and the answer is committed to history, and restores the previous setting in `finally`, because Octane and FrankenPHP workers reuse the process. Core improvement C1 is the backstop for what draining cannot cover inside a live process (third-party code or a framework helper that stops iterating, an exception thrown by the consumer): the destroyed generator settles the run as failed with a fenced, best-effort `fail()`, which releases the lease, lets the next `chat()` supersede it and lets `run()` recover it. A killed process runs no `finally` block. PHP-FPM's `request_terminate_timeout` terminates the worker, Octane kills a Swoole worker that exceeds `max_execution_time`, and a `max_execution_time` fatal error or PHP's own abort on disconnect is an unclean shutdown that closes generators without running `finally`. For those cases lease expiry remains the only backstop, before and after C1. That is why long turns go through the queued mode, and why stream time limits and H must stay below L. Until C1 lands for abandoned streams, and always for killed processes, a stuck thread shows up as a 409 with `Retry-After` until its lease expires, and `neuron:inspect {workflow} {id}` shows the held lease (a synchronous stream has no projection row until it returns, so `neuron:runs` does not list it).

A turn is not idempotent by itself. A client that retries after a network timeout, or a double submit, receives a harmless 409 while the first turn runs, but once that turn has finished the retry starts a second turn with the same message, which duplicates the user message, the inference cost and any tool side effects. Answers do not have this problem, because they target an interrupt (C8). The turn endpoints therefore accept an optional `Idempotency-Key` header, scoped to the authenticated user and the thread. Before igniting, the controller claims the key atomically, with `Cache::add("neuron:turn:{$userId}:{$threadId}:{$key}", 'claimed', $ttl)` or a unique column on an application table, releases the claim if admission is refused, and stores `{workflowId, runId}` once the run is ignited. A repeat with the same key receives the stored 202 body for a queued turn, or a 409 carrying the run ID for a streamed turn, after which the client reloads the thread endpoint. The TTL covers the client's retry horizon, not the run.

### 9.4 Protocols

The AG-UI and Vercel AI SDK endpoints each receive one POST that is either a new turn or the continuation of a suspended run (an approval, a frontend tool result). With C15, `AGUIRequest::fromPayload()` and `VercelAIRequest::fromPayload()` return the thread ID, whether the request is a new turn or a continuation, the `UserMessage` with its attachments or the continuation payload, and the frontend tools, so the controllers stay a few lines long. The thread ID inside the payload is untrusted: it must equal the route-bound, authorized thread. A continuation goes through `submitInputs($payload, new AGUIInputTranslator())` or `new VercelAIInputTranslator()`, and a new turn through `stream()`. AG-UI frontend tools from `AGUIInputTranslator::tools($payload)` are added to the handle for that request only, and names that collide with backend tools are rejected. Until C15, the package carries the request parsing internally.

The adapters are built per request, `new VercelAIAdapter()`, `new AGUIAdapter($workflowId, $runId, $messages, $state)` or core's `AgentChunkAdapter` for Neuron's native vocabulary, and installed on the handle with `setStreamAdapter(fn (ExecutionContext $context): StreamAdapterInterface => $adapter)` (the context-aware factory of C2; today's factories take no argument, `fn (): StreamAdapterInterface => $adapter`). The factory runs once per segment (one request is one segment), so the instance handed to `NeuronStream::from()` for its headers is the one that shapes the stream. A pull stream always needs one, because `SSEEncoder` frames protocol events, not raw chunks. An adapter holds one stream's state and terminal guards, so it is never a container service.

### 9.5 Approvals, tool results and reloads

Approvals and deferred tool results use the Agent's native helpers on the bound handle. The decision payload maps call IDs to `'approve'`, `'reject'` or `['reject', $reason]`; tool results map call IDs to exactly one `result` or string `error`. Both helpers translate and validate eagerly and return a `PendingExecution`, so bad input is refused before anything runs or is queued. Until C6 introduces `InvalidInputException` and `NoRunInFlightException`, the package's form requests validate the shape themselves (exactly one `result` or string `error` per call ID), because `ToolResultsRequest::validateResults()` throws a plain `WorkflowException`. The controller also maps a missing run to 404 rather than letting its `InputTranslationException` render as 422. It then either streams the continuation with `NeuronStream::from($pending->events(), $adapter, 'support')`, after installing the adapter on the handle, or queues `Invocation::answer()` and returns 202 (section 8.3). A page reload rebuilds the interface from the thread endpoint: `inspect()` gives the status, run ID and attempt and the interrupt's `jsonSerialize()`, `pendingApprovals()` the open decisions, `MessageStoreInterface::loadAll()` a page of history, and `AGUIAdapter::hydrate($messages, $snapshot)` the AG-UI transcript including uncommitted input.

```php
Route::post('/conversations/{conversation}/approvals', ApprovalController::class)->can('converse', 'conversation');

final class ApprovalController
{
    public function __invoke(ApprovalDecisionsRequest $request, Conversation $conversation, SupportAgent $agent, InvocationDispatcher $queue): Response
    {
        $pending = $agent->for($conversation->neuronWorkflowId())->submitApprovalDecisions($request->decisions()); // proposed, C2; 422 eagerly

        $queue->dispatch(Invocation::answer('support', $conversation->neuronWorkflowId(), $pending->request())); // proposed, C9 and C14

        return response()->noContent(202);
    }
}
```

### 9.6 Exception rendering

The package registers render callbacks on the framework's exception handler, the same mechanism `withExceptions()->render()` uses in `bootstrap/app.php`, for requests that expect JSON or hit a Neuron route. They follow the status column of section 8.5 and produce `application/problem+json` with the same body as the Symfony bundle, so one browser client serves both: `type` is `about:blank`, `title` is the HTTP status phrase and `status` the code. Only refusals whose text describes the submitted payload (`InvalidInputException`, `InputTranslationException`) add a `detail`. A `RunInFlightException` adds `run: {status, retryAt}`, with a `Retry-After` header when a lease is held, and `interrupt` with the interrupt's `jsonSerialize()` when the run is suspended. Problem responses never carry exception messages: those carry run identifiers, operator instructions, provider URLs and response bodies (an `HttpException` message embeds the provider's body today, D13), so they go to the log, never to the wire. Applications that register their own callbacks for these types take precedence. Once a stream has started, a failure can no longer change the status: the adapter's `error()` frames report it, and the failed status was committed before the first error frame was sent.

### 9.7 Authorization

The workflow ID is an untrusted storage key: it selects which conversation is read, written and resumed, and core performs no access control. Every endpoint therefore resolves the thread through route-model binding and authorizes it with a policy before `for()` (proposed, C2; today `setWorkflowId()` on a fresh prototype) binds its ID, `Route::post('/conversations/{conversation}/turns', ...)->can('converse', 'conversation')`. Thread existence does not leak: the policy denies with `Response::denyAsNotFound()` (or the route binding is scoped to the owner), so a thread the user cannot see is a 404. A plain `false` from the policy would make the `can` middleware answer 403. The same policy authorizes the private broadcast channel (section 10). Queued work captures the actor at submission: the edge records the user ID with the run ID it ignites or answers (an application row keyed by run ID), or carries it in the start event or the payload. Nodes and tools running in a worker find the actor through state or through `ToolContext` (proposed, C11), never through `auth()` or the request, which do not exist there (section 11).

Signal webhooks authenticate the sender instead of a user: the package's `VerifyNeuronSignature` middleware checks an HMAC-SHA256 over the timestamp, the HTTP method, the full path (definition, workflow ID and event name) and the raw body with `neuron.routes.signal_secret`, with a five-minute tolerance, and rejects a signature it has already seen within that window (a cache entry per signature). Before dispatching `Invocation::signal()`, the endpoint checks that the projection row for the workflow ID names the same `{workflow}` and answers 404 otherwise, the same cross-definition check the thread routes perform. Applications with several webhook sources can give each sender its own secret. The signal route is registered outside the `neuron.routes.middleware` stack, since it has no user and therefore no `auth:sanctum`, and carries `VerifyNeuronSignature` and a rate limiter instead. An application that moves it into the `web` group must exclude it from CSRF, which is safe only because it is signed.

## 10. Broadcasting with Reverb

Queued turns stream to the browser through a push channel. Reverb is a Pusher-protocol server and Laravel's Reverb broadcaster is a `PusherBroadcaster` whose `getPusher()` returns the `Pusher\Pusher` client, so core's `PusherChannel` works with Reverb, and with Pusher itself, without new transport code. `PusherChannel` delivers through the Pusher HTTP API, fragments events to stay within the 10,000-byte budget per event and per request, and supports encrypted channels. The package adds a small factory and the channel authorization:

```php
class ReverbChannels // Neuron::fake() swaps it for one that hands out FakeChannel instances (section 15)
{
    public function thread(string $workflowId): StreamingChannelInterface
    {
        return new PusherChannel(
            Broadcast::connection(config('neuron.broadcasting.connection'))->getPusher(), // resolved per segment
            'private-'.config('neuron.broadcasting.channel_prefix').$workflowId,          // private-neuron.thread.{id}
        );
    }
}

// routes/channels.php (added by neuron:install): the same policy as the HTTP endpoints
Broadcast::channel('neuron.thread.{conversation}', fn (User $user, Conversation $conversation): bool => $user->can('converse', $conversation));
```

The definition builds its channel per segment from the segment's context, `channel(ExecutionContext $context)` after C2, so the same code runs in the web process and in a worker. Against today's core the hook takes no argument and reads `$this->getWorkflowId()`, which is safe because interim definitions are bound before they execute. Content reaches the channel only through a stream adapter, so the definition also returns the protocol adapter the frontend expects from its `streamAdapter()` hook (Vercel, AG-UI or the native `AgentChunkAdapter`). A synchronous turn of the same definition then mirrors its stream to the channel as well, which lets a second tab follow the conversation.

The browser subscribes before it posts the turn, with Laravel Echo or pusher-js, and feeds the events to `@neuron-core/streaming` (in `packages/streaming`), which reorders and reassembles fragments. Frames are ephemeral: on connect or reconnect, the client loads the thread endpoint and follows the next stream ID. Every segment opens a fresh stream ID, so a recovered attempt starts a new stream and the client discards the partial output of the failed one. A delivery failure is reported as a `ChannelError` event and never fails the run; durable state is always the source of truth.

`CallbackChannel` with `Broadcast::private(...)->send()` is explicitly rejected: it bypasses the channel envelope and fragmentation, so large events hit the roughly 10 KB message limit of Pusher and Reverb and are dropped. `PusherChannel::deliver()` always calls `triggerBatch()`, and Reverb serves Pusher's batch events endpoint: laravel/reverb 1.12 routes `POST /apps/{appId}/batch_events` to its `EventsBatchController` (`src/Servers/Reverb/Factory.php`), which dispatches every item of the batch. The existing transport therefore works unchanged with its default batch size, which settles the verification item of C16 for Reverb.

## 11. Tools and MCP

Tools are where an agent touches the application: Eloquent queries, payments, email, internal APIs. The package treats them as ordinary autowired services, with two constraints that come from durability. A tool may run again after a crash, so side effects need idempotency keys. And a tool may run in a worker with no request, so it cannot read the current user from `auth()`.

Discovery and grant are separate. With C18, a tool class can carry `#[AsTool(name, description)]`, readable without instantiation, and derive its schema from its `__invoke()` signature. The package discovers tool classes under the configured paths into a catalog that `neuron:cache` validates (duplicate names, and the signature errors that `ToolValidator::validateClass()`, also proposed by C18, reports) and that the laravel/mcp adapter can expose. The catalog never grants anything. An agent lists its tools explicitly in `tools()` or receives them in its constructor, so a new tool class never appears in every agent by accident: least privilege is the default.

A tool is shared after C18. `ToolNode` already clones the registered tool for every call, and C18 turns attach-time configuration into methods that return configured copies (`withApproval()`, `withMaxRuns()`, `withName()`, `withVisibility()`, and `only()`, `exclude()` and `with()` on toolkits and MCP connectors), so an approval policy set by one agent can no longer switch a gate off for another. Approval policies can also be container services implementing `ApprovalPolicyInterface` (proposed, C18), which lets a policy read configuration and thresholds through injection. Today `requireApproval()`, `withApprovalPolicy()`, `setMaxRuns()`, `visible()`, `setName()` and the toolkit filters mutate the instance they are called on, so the package registers tools non-shared and agents clone before configuring:

```php
protected function tools(): array
{
    return [
        $this->orders,
        (clone $this->refunds)->requireApproval(), // today; after C18: $this->refunds->withApproval(true)
    ];
}
```

An approval deadline is an ordinary interrupt deadline: when it passes, the projection and the sweep deliver the expiry through a fenced wake, with no controller logic. C24 makes the deadline configurable on the Agent. Today `ToolNode::buildApprovalRequest()` never passes an `expiresAt`, and `resolveToolApprovals()` ignores an expiry: the wake yields no decisions and the node re-suspends with a new request. An application that wants "auto-reject after 24 hours" therefore subclasses `ToolNode` and overrides both methods. `buildApprovalRequest()` passes a deadline computed once and memoized (for example before the first round), because the method runs again for every partial-decision round and must not extend the deadline. The memoized closure in `resolveToolApprovals()` checks `$this->timedOut` after `interrupt()` and returns a `['reject', 'Approval expired']` decision for every pending call, so the expiry is recorded durably and replays identically. The subclass is then swapped in `Agent::nodes()`. C24 makes both behaviours built in.

Tools running in workers get their identity from `ToolContext` (proposed, C11): the workflow ID, run ID, execution attempt, call ID and an idempotency key that is identical across recoveries of the same call. A refund tool passes the key to Stripe or Cashier, a mail tool to its provider, and a database write to a unique constraint, because a crash between the side effect and the step commit re-executes the call: execution is at-most-once after the commit and at-least-once inside that window. Tenant scoping reads the thread or tenant from the same context or from state. Today the `tools()` hook runs on the bound instance, so it can pass `$this->getThreadId()` into a tool's constructor, and `getCallId()` combined with the thread ID serves as a partial idempotency key.

Authorization inside a tool follows the same rule. The edge records the actor of every submission, for example as a row that holds the run ID and the user ID, written when it ignites the turn or dispatches the answer (section 9.7). A tool that needs authorization loads that actor through the `ToolContext` workflow and run IDs after C11 (today through the thread ID the bound `tools()` hook passes in), checks `Gate::forUser($actor)->allows('refund', $order)`, and returns `ToolOutput::error()` when the check is denied, so the model learns the call was refused and the run continues. It never consults `auth()`, which is empty in a worker and belongs to whoever sent the current request on the web. Grants at composition time (which agent the actor may talk to, which tools that agent has) remain the first line of defence; the check in the tool is the second.

Core's SQL toolkits (`MySQLToolkit`, `PGSQLToolkit`) take a PDO, so an agent builds them inside `tools()` from a dedicated read-only connection, `DB::connection('reporting')->getPdo()`; after C13 they accept a connection closure and can be shared. Today both toolkits include their write tool with no approval gate, so the package's documented default is `->exclude([MySQLWriteTool::class])` (or `PGSQLWriteTool::class`), and an agent that needs writes adds `->with(MySQLWriteTool::class, fn (Tool $tool): ToolInterface => $tool->requireApproval())`, until the toolkit defects listed in the core document are fixed and C18 makes write tools declare approval.

MCP servers are configured under `neuron.mcp.servers.<name>`, either stdio (`command`, `args`, `env`) or HTTP (`url`, `token`), and built as `McpConnector` services that receive `LaravelHttpClient` for HTTP transports. A connector with static credentials is a singleton per worker. A connector that authenticates as the end user is built per request or job by a package factory from the actor captured at submission (the same record the tools read, found through the thread and run IDs), which reads that actor's token from the application's credential store, never from the session or `auth()`, so the same connector works in a controller and in a worker; C18's header resolver (`Closure(): array`) is where the token is read for each request. `McpConnector` creates its client lazily, but `tools()` lists the server's tools on every call, which today means one `tools/list` round trip per segment; C18 caches the definitions, optionally in a PSR-16 cache that the package backs with a Laravel cache store, and adds `close()`, which the package calls on Octane's and the queue worker's `WorkerStopping` events.

Stdio servers need care in production. Today `StdioTransport` merges `getenv()` into the child's environment, so an MCP subprocess inherits `APP_KEY`, database passwords and every API key of the worker (D12). The minimal fix, an environment allowlist, is one of the defect fixes the first package release requires, and C18 makes the allowlist configurable. On a core without it, the package recommends HTTP transports, and for stdio a wrapper that clears the environment: `'command' => 'env', 'args' => ['-i', 'PATH=/usr/bin:/bin', 'HOME=/tmp', 'node', 'server.js']`. The same applies to two MCP defects (D8), which are also fixed before the first release: a nullable `anyOf` in a tool's input schema makes the whole connector throw, and an `isError` result is treated as success. Until C18, `only()` and `exclude()` mutate the connector, so the package applies them once, when it builds each configured connector (`only` and `exclude` keys under `neuron.mcp.servers.<name>`), and an agent that needs a different subset uses a separately configured connector. Filtering at configuration time also keeps an agent to the tools that are known to work.

The optional laravel/mcp adapter goes the other way and exposes Neuron to MCP clients. Tools the application lists explicitly (a `neuron.mcp.expose.tools` allowlist, never the whole catalog) become MCP tools: the schema comes from the tool's `getInputSchema()`, and invocation clones the tool, sets the inputs, executes it and maps its `ToolOutput` to MCP content. A tool whose `requiresApproval()` is not false is refused by the direct adapter, because the approval gate lives in `ToolNode`; such a tool is exposed only through a durable workflow that suspends for approval. Durable workflows become MCP tools whose `handle()` is a generator: it starts or continues a run, yields progress notifications from `events()`, and ends with the output or, when the run suspends, the interrupt JSON and the workflow ID, so the client can answer in a later call. laravel/mcp's own authentication (Sanctum or OAuth) identifies the caller, and the same thread policy authorizes it. How laravel/mcp registers tools is still to verify: servers list tool classes, and tool schemas are written with Laravel's JSON schema builder, so the adapter may need a generated class per exposed tool or a translation of raw JSON schema.

## 12. RAG

Embeddings providers and vector stores are configured by name (`neuron.embeddings.<name>`, `neuron.vector_stores.<name>`) and built by `ProviderManager` and `VectorStoreManager`. The default entries go into the defaults tier as `EmbeddingsProviderInterface` and `VectorStoreInterface`, and a RAG class that needs a specific store receives it with a contextual attribute, `#[NeuronVectorStore('docs')] VectorStoreInterface $store`. This also closes a silent trap: today a RAG class without a configured store falls back to a `MemoryVectorStore` and searches an empty index, which C21 turns into an exception. Several stores (Qdrant, Chroma, Weaviate, Meilisearch) perform network I/O in their constructors today and `FileVectorStore` creates its directory, so the package registers them as lazy singletons that are never resolved in `register()` or `boot()`. C4 makes their constructors pure and moves provisioning into `setup()`, which `neuron:setup` runs at deploy time.

PostgreSQL applications get a first-class store. C21 adds a PDO-based pgvector store to core, which the package's `pgvector` driver builds over a connection closure (C13). Its migration uses Laravel's vector column, `$table->vector('embedding', dimensions: 1536)->index()`, after `Schema::ensureVectorExtensionExists()`. The vector column type exists since Laravel 11, and `Schema::ensureVectorExtensionExists()`, the fluent vector index and the vector query clauses exist since Laravel 12.47, so the same migration runs on both supported majors with the package's `illuminate/database` ^12.47|^13 constraint; `neuron:setup` emits the store's own DDL only for applications that do not run migrations. The table remains an ordinary Laravel table, so the application can also query it with `whereVectorSimilarTo()` for features outside Neuron. MariaDB 11.7 applications use core's `MariaDBVectorStore`, which captures its PDO today and is therefore built per job until C13. Until C21 ships, a package-internal query-builder store over the query builder's `selectVectorDistance()` and `orderByVectorDistance()` (Laravel 12.47+ and 13) covers PostgreSQL, and is removed when the core store exists.

Ingestion is a queue concern, and it must converge under retries. C21 introduces a stateless `Indexer(VectorStoreInterface, EmbeddingsProviderInterface)` whose `index()` validates the whole batch before any write and upserts, and whose `reindexSource()` upserts the new chunks and then deletes only the stale ones, so retrieval never sees a gap. Chunk IDs become deterministic (a UUIDv5 of the source type, source name and chunk index) and every store's `addDocuments()` upserts by ID, so a redelivered ingestion job produces the same index instead of duplicate chunks. The package registers one `Indexer` per configured store and ships an `IndexSource` job, one per source, which applications fan out with `Bus::batch()` to get progress, cancellation and a completion callback. For pipelines that must resume at the last committed batch after a crash, C21's optional `IngestionWorkflow` runs through the gateway like any other workflow, with one committed step per batch; its workflow ID, derived from the vector store name and the source (for example `ingest-docs-page-42`, which also stays within the channel alphabet of section 4.4 when progress is broadcast), doubles as a per-source mutex for that store.

Today ingestion goes through a RAG instance built per job. `RAG::addDocuments()` validates chunk by chunk and `reindexBySource()` deletes a source before adding its replacement, and documents get random IDs. The interim rules are to assign deterministic IDs with `Document::setId()`, to run queued ingestion only against stores that upsert by ID, and otherwise to serialize ingestion per source with `Cache::lock()` and accept the gap during a reindex.

Documents often live in Laravel's filesystem. With C21, readers become instance services that read content or streams, so a loader reads `Storage::disk('s3')->readStream($path)` directly and works with any Flysystem disk. Today `FileDataLoader` takes a local path, so text sources use `StringDataLoader::for(Storage::disk('s3')->get($path))` and binary formats such as PDF are copied to a temporary file first.

The package adds a Scout-like `Embeddable` trait for Eloquent models. A model declares `toNeuronDocuments(): iterable` and its store name; an observer dispatches `IndexSource` after commit when the model is saved and removes its chunks when it is deleted. The source type is the model's morph class and the source name its key, which makes the chunk IDs deterministic and `reindexSource()` exact.

Retrieval in a multi-tenant application is scoped by tenant. When the tenant follows from the thread, the RAG's `retrievalScope()` hook (which runs on the bound instance and can read `$this->getThreadId()`) or `setRetrievalScope()` applies it. When the tenant lives in the run's state, middleware on `RetrievalNode` adds it per run through `QueryPreProcessedEvent::addFilters()`, because the scope hook has no access to state. The tenant never comes from `auth()`, since retrieval may run in a worker. `RetrievalTool` currently bypasses the RAG's retrieval scope, a cross-tenant leak that C21 fixes by making the scope mandatory; until then multi-tenant applications do not use it.

## 13. Structured output

Structured output maps a model response onto a PHP class. Core generates the JSON schema, deserializes the response and validates the object with hard-wired calls inside `StructuredOutputNode` (`JsonSchema::make()->generate()`, `Deserializer::make()->fromJson()`, `Validator::validate()`), and a validation failure re-prompts the model up to `maxRetries` times. C20 turns the three steps into one seam, `OutputMapperInterface` (`schema()`, `map()`, `validate()`), delivered through `AgentResources` and the defaults tier. The package binds a `LaravelOutputMapper` that keeps core's schema generation and deserialization and validates with Laravel's validator, using rules the output class declares and the application's localized messages, so the violations the model sees in a retry read like the application's own validation errors. Until C20, an application that needs this subclasses `StructuredOutputNode` and swaps it in `Agent::nodes()`, or validates the returned object itself.

Endpoints must not use `structured()` as their only path: it returns `$state->get('structured_output')`, which is null when the run suspends on an approval. A structured-output endpoint runs the start request explicitly and branches on the status, until C24 makes `structured()` throw a typed exception that carries the `InterruptRequest` (and accept a plain string):

```php
$state = $agent->for($conversation->neuronWorkflowId())->run(ExecutionRequest::start( // proposed, C2; today: $agent->setThreadId(...)->run(...)
    new AgentStartEvent([$request->userMessage()], new AgentRunOptions(outputClass: InvoiceData::class)),
));

return $state->getStatus() === WorkflowStatus::Suspended
    ? response()->json(['status' => 'suspended', 'interrupt' => $state->getInterruptRequest()?->jsonSerialize()], 202)
    : response()->json($state->get('structured_output'));
```

Queued structured output arrives through the completion handler (section 8.7), whose state carries the same `structured_output` key. An output class that has exhausted its retries raises an exception marked `UnrecoverableException` after C6, which the job fails immediately instead of retrying.

## 14. Observability

Neuron emits typed PSR-14 events (`WorkflowStart`, `WorkflowInterrupted`, `InferenceStart`, `InferenceStop`, `ToolCalling`, `ToolCalled` and many more). Each workflow runs its own listeners first and then forwards every event to one external dispatcher. Laravel's dispatcher does not implement PSR-14, so the package provides `IlluminateEventBridge`, registered under `EventDispatcherInterface` in the defaults tier as that forward target. The bridge resolves the dispatcher through the `Event` facade at every dispatch, so `Event::fake()` and Octane's per-request sandboxes are honoured, and forwards the raw event to the application's synchronous listeners, which type-hint the concrete Neuron classes. When a listener exists for `NeuronEventRecorded`, it also dispatches that event carrying an `EventRecord` (proposed, C22): a serializable projection with the event class, name, data, workflow ID, run ID, attempt, branch ID and time. Until C22 the bridge builds the same record itself from the event class, `name()`, `toArray()`, the `execution` context (workflow ID, run ID, attempt) and `branchId`. Queued listeners, broadcasts and Pulse recorders use the record, because a raw event carries its live source (the definition, with its closures) and cannot be serialized onto a queue.

Laravel matches listeners by event class and by the interfaces an event implements, not by parent class, so `Event::listen(ObservabilityEvent::class, ...)` would receive nothing. Catch-all consumers (tracers, exporters, the log listener) are therefore subscribed through Neuron's own `subscribe(ObservabilityEvent::class, $listener)`, which matches with `instanceof`, from `neuron.observability.listeners`, in the same `afterResolving` callback that applies the defaults. With shared definitions a listener sees the events of many interleaved runs, so any state it keeps is keyed by `$event->execution->runId`.

Monitoring must never change execution. Lifecycle events already go through an isolating path that turns a listener failure into a `WorkflowError`, but the events nodes emit (`InferenceStart`, `ToolCalling` and the like) are dispatched without isolation today, so a Telescope watcher or a metrics listener that throws would fail an inference step and mark a durable run failed. C22 routes node emission through the same isolating path. Until then the package wraps every listener it subscribes on a definition (the `LogListener` and every entry of `neuron.observability.listeners`) in a small decorator that catches and `report()`s failures, and the bridge does the same for its forward. That protects the step from every listener the package installs. Listeners the application subscribes directly on a definition remain its own responsibility. C22 also adds a `replayed` flag to `InferenceStop` and `ToolCalled`: today a recovered run re-emits the inference events of its memoized steps, so token and cost dashboards double count after a recovery, which the package documents until the flag exists.

Logging uses core's `LogListener` bound to a dedicated channel, `Log::channel(config('neuron.observability.log_channel'))`. It logs every event with its name and data at one configured level today; C22 lets it choose the level per event. The job and the bridge also put the workflow name, workflow ID and run ID into Laravel's `Context`, which adds them to every log record and carries them into any job dispatched from inside a run. A run whose segments executed on three workers over two days can then be followed as one story.

The rest of Laravel's tooling comes almost for free: provider traffic appears in Telescope's HTTP client watcher, the query-builder stores in the query watcher and Pulse's slow queries, and `InvokeWorkflow` in the job watcher and in Horizon with its tags. A Pulse recorder for token usage per model, registered in `config/pulse.php`, listens to `NeuronEventRecorded` for `InferenceStop`, whose response message carries usage today and which gains the provider class and model with C22; a custom card displays the aggregates. Which public APIs Nightwatch offers for custom AI spans is still to verify.

## 15. Testing

The package's testing story mirrors what Laravel developers know from `Http::fake()`, `Queue::fake()` and laravel/ai's `Agent::fake()`. `Neuron::fake()` returns a `NeuronFake`. It swaps the defaults entries for test doubles (a `FakeAIProvider` answering for the default provider and for every name `ProviderManager` resolves, `InMemoryPersistence`, `InMemoryMessageStore`), replaces the queue path with an inline gateway that records every invocation, and disables the transaction guard (section 7.3). The fake then forgets every Neuron singleton already resolved over a swapped entry (definitions, the `WorkflowEngine`, the gateway and the managers' per-name caches), so they are rebuilt with the fakes. Its assertions (`assertPrompted()`, `assertToolCalled()` and its negation, `assertInvoked()`, and `preventStrayPrompts()`) build on core's `FakeAIProvider` (`assertSent()` with `RequestRecord`s, `assertCallCount()`, `assertSystemPrompt()`, `assertToolsConfigured()`) and on a recording listener (C22 ships a public one in `src/Testing`):

```php
public function test_an_unanswered_refund_approval_expires_after_a_day(): void
{
    // SupportAgent sets setApprovalTimeout(new DateInterval('PT24H')) (proposed, C24).
    $neuron = Neuron::fake([$this->refundToolCall(), new AssistantMessage('The refund was not approved.')])
        ->preventStrayPrompts();

    $this->postJson(route('conversations.turns', $this->conversation), ['message' => 'Refund order 42'])
        ->assertAccepted();

    $neuron->assertInvoked(InvocationKind::Resume, 'support'); // proposed, C9 and C14: ignite() at the edge, then a resume invocation
    $neuron->process();                      // runs the recorded invocations: the run suspends on the approval

    $this->travel(25)->hours();              // the Carbon-backed clock drives the engine (C10)
    $this->artisan('neuron:wake');           // the sweep finds the expired approval
    $neuron->process();

    $neuron->assertToolNotCalled('refund_order');
    $neuron->assertPrompted(fn (RequestRecord $request): bool => count($request->tools) === 1);
}
```

The scenario assumes C24's approval deadline and that an elapsed approval rejects its pending calls with an "expired" reason. Today `ToolNode` treats an expired approval as no decision and re-suspends on a new request (section 11), so C24 must define expiry as rejection.

The fake uses the rest of core's `src/Testing` as well, so every integration area has an offline story. `EmbeddingsProviderInterface` and `VectorStoreInterface`, and every name `VectorStoreManager` resolves, become `FakeEmbeddingsProvider` and `FakeVectorStore` (`$neuron->vectorStore($name)`), whose assertions include `assertSearchCount()`, `assertSearchedWithFilters()` and `assertDocumentCount()`. Configured MCP servers are built over `FakeMcpTransport` (through the client's `transport` option), which offers `assertToolCalled()` and `assertMethodSent()`. `ReverbChannels` hands out one `FakeChannel` per workflow ID (`$neuron->channel($workflowId)`), so a test can assert what a queued turn pushed with `assertSent()`, `assertSuspended()` and `assertCompleted()`. Each configured classifier name gets a `FakeClassifier`. A second scenario covers retrieval and push, and needs no time travel:

```php
public function test_a_queued_answer_is_scoped_to_the_tenant_and_pushed_to_the_thread(): void
{
    // The conversation's definition is a RAG class that filters retrieval by tenant (section 12).
    $neuron = Neuron::fake([new AssistantMessage('Priority support is included in your plan.')]);
    $neuron->vectorStore('docs')->setSearchResults([$this->document('Pro plans include priority support.')]);

    $this->postJson(route('conversations.turns', $this->conversation), ['message' => 'Do I get priority support?'])
        ->assertAccepted();
    $neuron->process();

    $neuron->vectorStore('docs')->assertSearchedWithFilters(Filter::eq('tenant_id', $this->tenant->getKey()));
    $neuron->channel($this->conversation->neuronWorkflowId())->assertCompleted();
}
```

Time is the part that needs core. The engine reads `time()` today, and Laravel's `travel()` only moves Carbon, so a test cannot fast-forward a `sleepUntil()`, an approval expiry or a lease takeover. With C10 the Workflow takes a PSR-20 clock from the defaults tier and hands it to the engine and the segment heartbeat, and the package binds `CarbonClock`, whose `now()` returns `Date::now()->toDateTimeImmutable()`; the same clock drives the gateway and the sweep. Until then, timer tests use deadlines a second or two in the future, or assert only the projection and the scheduled wake.

Everything else is plain Laravel. `Http::fake()` and `Http::preventStrayRequests()` intercept every provider the manager builds, because it injects `LaravelHttpClient` (with `neuron.http.client` left at `laravel`). A provider built outside the manager, for example `new Anthropic(...)` inline in a `provider()` hook, falls back to core's `CurlHttpClient` and escapes both. It escapes `Neuron::fake()` too, because a class hook wins over the defaults tier; this is one more reason the generator stubs inject providers (C23). `Queue::fake()` asserts `InvokeWorkflow` payloads by name and fence (`Queue::assertPushed(InvokeWorkflow::class, fn (InvokeWorkflow $job): bool => $job->invocation['workflow'] === 'support')`). `Event::fake()` works on the bridge. The package's own adapters are tested against Testbench 10 and 11 and extend core's contract test cases (C13), so the query-builder, Eloquent and projection stores prove the same semantics as core's backends.

## 16. Console and developer experience

Artisan is the operator's interface to durable runs. Every operational command works by name and ID through `WorkflowLocator` and the projection, and never needs the application's code to be modified.

| Command | Purpose |
|---|---|
| `neuron:install` | Publishes the configuration and the migrations, appends the `neuron.thread.{conversation}` channel definition to `routes/channels.php` unless it is already there (asking for `php artisan install:broadcasting` first when broadcasting is not installed, since that command creates and registers the file), and prints the next steps |
| `neuron:setup` | Provisions every store implementing `ManagedStoreInterface` (C4); today it calls the existing `MariaDBVectorStore::setupTable()` and `MongoDBVectorStore::setupVectorIndex()` and constructs the stores that provision themselves |
| `neuron:runs` | Lists runs from the projection, filtered by definition, status, `--definition-version` or overdue deadline |
| `neuron:inspect {workflow} {id}` | Prints the snapshot: status, run ID, attempt, the interrupt JSON and, with C7, the lease expiry |
| `neuron:abandon {workflow} {id}` | Fenced abandon through the definition, so Agent's guard applies |
| `neuron:acknowledge {workflow} {id} {runId}` | Releases a retained completion |
| `neuron:recover {workflow} {id}` | Dispatches a fenced inputless recovery of a failed run |
| `neuron:wake` | The sweep: dispatches fenced wakes for due deadlines and expired executions, and a resume at attempt 0 for a pending run whose dispatch was lost |
| `neuron:prune` | Projection-driven retention (section 7.5); `--reject-pending` (default) or `--reset` for threads suspended on unanswered approvals or tool results |
| `neuron:evaluate` | Runs evaluations with a container resolver: through core's `EvaluationCommand` today, and through the typed evaluation service after C23 |
| `neuron:cache`, `neuron:clear` | Build and remove the discovery manifest; registered with `optimizes(optimize: 'neuron:cache', clear: 'neuron:clear')` |

Operators map production symptoms to these commands:

| Symptom | Action |
|---|---|
| A thread answers 409 with `Retry-After` | `neuron:inspect` shows the held lease; wait, or `neuron:recover` once the lease has expired |
| A run parked as failed | `neuron:inspect`, fix the cause, then `neuron:recover` |
| A suspended run with no deadline, untouched for months | `neuron:prune` (section 7.5) |
| An incompatible deploy | Section 8.10: a new definition version and a queue that serves the previous one |
| An `APP_KEY` rotation | Keep the previous key in `APP_PREVIOUS_KEYS` while runs written before the rotation are suspended, so their signed records stay readable |
| A poison job in `failed_jobs` | `neuron:inspect` before `queue:retry`, which redelivers the same fenced invocation |

`neuron:evaluate` wraps core's `EvaluationCommand` with `fn (string $class): object => app($class)` as the resolver, so evaluators receive constructor injection. Today that command writes its report with `echo` and its errors to the stream set with `setErrorStream()`, so the wrapper buffers the first and passes a memory stream for the second, and copies both into artisan's output; C23 replaces it with a typed evaluation service that writes through an injected writer, shared by core's CLI, artisan and `bin/console`.

Resolving evaluators through the container has consequences beyond constructor injection, and the command handles them. Evaluators receive definitions configured with the application's defaults, so an evaluation would write runs and chat rows into the production `neuron_*` tables; `neuron:evaluate` therefore swaps the persistence and history entries of `NeuronDefaults` for in-memory stores in its own process, as `Neuron::fake()` does but with real providers, unless `--persist` is passed (a class whose own hooks name a store keeps it). Evaluators bind the definitions they receive once per dataset item, `Conversation::make($this->agent->for('eval-'.Str::ulid()))` after C2; today they resolve a fresh instance per item from `WorkflowLocator`, because one injected instance binds the ID of its first turn and would chain every item into one thread.

Judges need the same care: `AgentJudge` sends every judgment through the one judge agent it receives, so from the second judgment on the judge sees the earlier judgments in its history (reproduced on this branch and reported to the core document as a defect); until that is fixed, an evaluator builds a fresh judge per item. For `--concurrency`, which forks with spatie/fork, the command passes an `EvaluatorRunner` whose `beforeChild` hook purges and reconnects the database and Redis, as section 17 requires for forks. The run-output cache defaults to `storage_path('framework/neuron/evaluation')` instead of `.neuron/cache/evaluation` under the working directory, through a `ConfigLoader` subclass whose `getCachePath()` returns it. `FakeClassifier` makes assertions built on `ClassifierJudge` testable offline.

The generators use the `make:neuron-*` naming: `make:neuron-agent`, `make:neuron-tool`, `make:neuron-workflow`, `make:neuron-node`, `make:neuron-middleware`, `make:neuron-rag` and `make:neuron-evaluator`, writing into `App\Neuron`. They never collide with laravel/ai's `make:agent`, `make:tool` and `make:agent-middleware`. With C23 they render core's framework-neutral stubs, which use constructor injection and `#[AsWorkflow]` and never call `env()` or build a provider inline. Core's current agent stub builds `new Anthropic(key: ..., model: ...)` inside `provider()`, which is wrong for Laravel (it bypasses configuration, the shared HTTP client and the fakes), so the package ships its own stubs until C23.

`AboutCommand::add('Neuron', ...)` adds a section to `php artisan about` with the persistence and history drivers, the serializer, the default provider, the queue connection and queue, the default lease, whether the sweep is scheduled and the number of discovered definitions. Coding assistants get Laravel-flavoured knowledge through Laravel Boost: the package ships `resources/boost/guidelines/core.blade.php` and `resources/boost/skills/*/SKILL.md`, generated at release time from the repository's `skills/` directory with Laravel idioms (injection, `for()`, `config()`, the package's commands), so `boost:install` teaches assistants the API of the installed version and the two never drift.

## 17. Octane and long-running worker checklist

Octane (Swoole, RoadRunner, FrankenPHP), Horizon and `queue:work` keep one application alive across many requests or jobs. Core has no mutable static state, so the risks come only from lifetimes, and the package's registrations follow them. An application adding its own Neuron services checks the same list:

- Definitions are shared only after C2. Before it they are registered with `bind()`, bound right after resolution, never `singleton()` or `scoped()`, and long-lived services hold `WorkflowLocator`, not a definition.
- Definitions are configured at build time (constructor, hooks and the `afterResolving` callback) and customized per call only on handles. A setter called on an injected definition during a request changes it for every later request in the worker, so call it on `$definition->for($id)` instead.
- Providers are shared only after C5. Before it `ProviderManager` builds one per call and only the HTTP client is shared.
- Singletons never capture the request, the `Application` or the config repository. `NeuronDefaults`, `IlluminateEventBridge` and `ReverbChannels` resolve what they need from the current container or facade when they are called.
- SQL stores resolve their connection per operation. Core's PDO-capturing stores are never singletons before C13.
- MCP connectors are per worker, closed on Octane's and the queue worker's `WorkerStopping` events once C18 adds `close()`; connectors that authenticate as the end user are built per request or job from the actor captured at submission.
- `parallelToolCalls()` forks with spatie/fork and runs only in queue workers, never in a web request under FPM or Octane. Its `beforeChild` hook purges and reconnects the database (`DB::purge()`) and Redis, because forked children must not share the parent's sockets. `AsyncBranchRunner` stays opt-in: amphp/amp is not a core dependency, and its coexistence with Swoole coroutines is unverified.
- No connector, HTTP client or channel is shared across concurrent Swoole coroutines.
- `NeuronStream` restores `ignore_user_abort()` after every stream, because Octane and FrankenPHP reuse the process.
- Long turns go through the queue, since a web worker's execution time limit or a proxy idle timeout cuts silent streams. Until C1 an abandoned stream holds the thread until its lease expires, and a killed process always does.
- Queue workers recycle with `--max-time` and `--memory`, and their timeouts follow section 8.6.

## 18. Security checklist

- Every endpoint resolves the thread through route-model binding and authorizes it with a policy before `for()`. The policy denies with `Response::denyAsNotFound()` (or the binding resolves only the user's threads), so threads the user cannot see are 404s, not 403s.
- Thread IDs are issued by the server; client-chosen IDs, such as the Vercel `useChat` ID, are never storage keys; the `{workflow}` route segment must match the thread's definition.
- Private broadcast channels are authorized in `routes/channels.php` by the same policy as the HTTP endpoints.
- Signal webhooks are HMAC-signed over the method, the full path and the body with a timestamp tolerance, replayed signatures are rejected, the `{workflow}` segment must match the run's projection row, and the route sits outside the authenticated middleware stack; it may be exempt from CSRF only because it is signed.
- Queued continuations capture the actor at submission; nodes and tools in workers read state or `ToolContext` (C11), never `auth()`, and a tool that authorizes checks `Gate::forUser($actor)` and answers a denial with `ToolOutput::error()` (section 11).
- Problem responses never carry exception messages: the title is the status phrase, `detail` appears only for payload refusals, and run state travels as structured fields (section 9.6).
- In a multi-tenant application the Neuron connection is pinned explicitly (to the central database, or initialized per tenant in jobs and sweeps), and business keys carry the tenant (section 4.7).
- Durable records are unserialized with `PhpSerializer` without an allowed-classes list, so write access to the store is an object-injection vector. `neuron.serializer.sign`, on in the published configuration, wraps the serializer in a signing decorator keyed by `APP_KEY`, with `APP_PREVIOUS_KEYS` as the key ring so that key rotation does not strand suspended runs; signing is compatible with the engine's compare-and-swap because it compares the raw bytes it read. The package ships the decorator itself until C12 provides `SigningSerializer`. A store that already holds unsigned suspended runs turns signing on through `neuron.serializer.accept_unsigned`, a transition mode that accepts unsigned records and signs them when they are next written, and turns the mode off once those runs have finished.
- `ShouldBeEncrypted` (`neuron.queue.encrypt`) protects invocation payloads that carry personal data.
- Until D13 is fixed on the branch, `HttpException` exposes the request, including API keys in its headers, through a public property, and embeds response bodies in messages, which error trackers then capture. `LaravelHttpClient` builds its exceptions with redacted headers and truncated bodies from its first release; C17 makes redaction the rule for every client.
- MCP stdio servers must not inherit the worker's full environment: the D12 fix (an allowlist, configurable with C18) is required before the first release, and on a core without it the package recommends HTTP transports or the `env -i` wrapper (section 11).
- Multi-tenant RAG never uses `RetrievalTool` until C21 makes its scope mandatory, and scopes retrieval from the thread or state.
- `env()` appears only in `config/neuron.php`.
- Erasing a user settles each of the user's threads on its handle: `acknowledge()` for a retained completion, otherwise `resetConversation()`, which abandons the run without the Agent's unanswered-tool-call guard and clears the history through `MessageStoreInterface::clear()`. A thread still executing under its lease is retried after the lease expires. Finally the projection row is removed.
- Other core defects with security impact (server-side fetches of image URLs by `TokenCounter`, the environment of MCP children, exception payloads) are tracked in [neuron-core-improvements.md](neuron-core-improvements.md).

## 19. Package layout

```text
neuron-laravel/
├── composer.json                     # illuminate ^12.47|^13, php ^8.2, neuron-core/neuron-ai ^4.0,
│                                     # neuron-core/gateway (@internal pre-release) until C14 ships
├── config/neuron.php
├── database/migrations/
│   ├── 2026_01_01_000000_create_neuron_workflow_store_table.php  # placeholder timestamps,
│   ├── 2026_01_01_000001_create_neuron_chat_messages_table.php   # rewritten by publishesMigrations()
│   └── 2026_01_01_000002_create_neuron_runs_table.php
├── routes/neuron.php                 # optional routes (neuron.routes.enabled)
├── stubs/                            # generator stubs, the channel definition neuron:install appends
├── resources/boost/
│   ├── guidelines/core.blade.php
│   └── skills/*/SKILL.md             # generated from the core repository's skills/
├── src/
│   ├── NeuronServiceProvider.php
│   ├── Neuron.php                    # facade: workflow(), fake()
│   ├── NeuronDefaults.php            # PSR-11 defaults tier (C3)
│   ├── WorkflowLocator.php           # PSR-11 definitions by #[AsWorkflow] name
│   ├── DefinitionOptions.php         # explicit per-definition settings (lease, queue, mode)
│   ├── Attributes/                   # NeuronProvider, NeuronVectorStore (contextual attributes)
│   ├── Contracts/NeuronThread.php
│   ├── Discovery/                    # manifest builder and cache (neuron:cache)
│   ├── Providers/                    # ProviderManager (providers, embeddings, classifiers),
│   │                                 # VectorStoreManager
│   ├── Http/                         # LaravelHttpClient, NeuronStream, problem rendering,
│   │                                 # VerifyNeuronSignature, form requests, optional controllers
│   ├── Queue/                        # InvokeWorkflow, EncryptedInvokeWorkflow, InvocationDispatcher
│   ├── Storage/                      # StorageFactory, QueryBuilderPersistence, EloquentPersistence,
│   │   │                             # QueryBuilderMessageStore, EloquentMessageStore,
│   │   │                             # QueryBuilderProjectionStore, SchedulingProjectionStore,
│   │   │                             # SigningSerializer (until C12)
│   │   └── Models/                   # WorkflowRecord, ChatMessage
│   ├── Broadcasting/                 # ReverbChannels, refusal publisher (until C9)
│   ├── Events/                       # IlluminateEventBridge, NeuronEventRecorded, WorkflowCompleted,
│   │                                 # EventCompletionHandler
│   ├── Clock/CarbonClock.php
│   ├── Rag/                          # Embeddable trait, IndexSource job
│   ├── StructuredOutput/LaravelOutputMapper.php
│   ├── Mcp/                          # connector factory (static and per-actor), optional laravel/mcp adapter
│   ├── Console/                      # operational commands, Make/ generators
│   └── Testing/                      # NeuronFake, FakeReverbChannels
└── tests/                            # Testbench 10 and 11; adapters extend core contract test cases
```

## 20. Dependencies on core improvements

The package can ship against today's core once the defect fixes of the core document are on the branch. Each row names the package feature, the core improvement that makes it clean (specified in [neuron-core-improvements.md](neuron-core-improvements.md), with its priority: P0 before 4.0, P1 before the packages go stable, P2 soon after), and what the package does until it lands.

| Package feature | Core improvement | Priority | Interim against today's core |
|---|---|---|---|
| Abandoned SSE streams release their thread | C1 | P0 | Drain with `ignore_user_abort(true)`; long turns go through the queue; an abandoned stream holds the thread until its lease expires (a killed process does so even after C1) |
| Definitions as shared singletons, handles from `for()` | C2 | P0 | `bind()` per resolution, `setThreadId()`/`setWorkflowId()` right after resolution, `WorkflowLocator` in long-lived services |
| Defaults applied once at the lowest precedence | C3 | P0 | Setters in `afterResolving`, only where the class keeps the base hook (reflection check cached by `neuron:cache`); `setEventDispatcher()` always |
| Pure constructors, lazy stores, `neuron:setup` | C4 | P1 | Lazy singletons never resolved in `register()` or `boot()`; Vertex providers built per call; existing `setupTable()`/`setupVectorIndex()` in `neuron:setup`; subclasses call `parent::__construct()` |
| Shared providers per name, contextual injection | C5 | P1 | `ProviderManager` builds a new provider per call; only `LaravelHttpClient` is shared |
| Job verbs and HTTP statuses by exception type | C6 | P0 | Inspect before every continuation; classify `RunInFlightException` and `StaleWorkflowRunException`; bounded backoff for other refusals; no message matching |
| Timers from `deadline()`, lease on snapshots, read-only polls | C7 | P1 | `instanceof` on `SleepUntilRequest::getWakeAt()` and `WaitForEventRequest::getExpiresAt()`; inspect before waking; fixed lease backoff when the expiry is unknown |
| Redelivered answers converge | C8 | P1 | Inspect on a stale attempt and re-fence when the same interrupt is still current |
| 409 before 202, JSON-only invocations, `PendingExecution::request()` | C9 | P1 | A `start` kind with the Serializer-encoded event and a reserved run ID, deduplicated by the pre-release's start rule (section 8.12); racy `inspect()` pre-check, with refusals published on the thread channel; edges rebuild the fenced answer from `inspect()` and the translator |
| `travel()` drives leases, sleeps and expiries | C10 | P1 | None: timer tests use near-future deadlines |
| `ToolContext` and idempotency keys; lease renewed by memo commits | C11 | P1 | Thread ID passed into tools from the bound `tools()` hook; `getCallId()` as a partial key; leases sized above a whole tool loop |
| Deploy-safe records, signing, version routing | C12 | P1 | A package-owned signing `Serializer` decorator keyed by `APP_KEY` with `APP_PREVIOUS_KEYS`, with a transition mode for existing stores; no record versioning: drain or abandon suspended runs before renaming persisted classes or reordering nodes, `class_alias` shims for moved classes (section 8.10) |
| Connection closures for core SQL stores, canonical schema, contract tests | C13 | P1 | Package query-builder and Eloquent backends, including `QueryBuilderMessageStore`; vendored copies of core's contract tests |
| The gateway, projection contracts and completion handling | C14 | P1 | The `@internal` pre-release of `neuron-core/gateway`, written once and shared with the Symfony bundle, with the surface of C14 plus `ProjectionStoreInterface::find()` and `Gateway::exhausted()`; the dependency is replaced by `NeuronAI\Gateway` when C14 ships |
| Adapter headers, `SSEStream`, AG-UI and Vercel request objects | C15 | P2 | Prime with `current()`; headers from the concrete adapter without `Connection`; request parsing inside the package |
| Push transports | C16 | P2 | None needed for Reverb: `PusherChannel` works today, because laravel/reverb 1.12 serves the `batch_events` endpoint that `triggerBatch()` uses (section 10) |
| Uniform HTTP errors, redaction, `RetryableHttpException` | C17 | P1 | `LaravelHttpClient` raises and redacts `HttpException` itself; the Amp client is not offered; defects D2 (`SSEParser`) and D3 (status-blind client, skipped vendor error events) are fixed on the branch before the first package release |
| Shareable tools, policy services, MCP lifecycle and allowlist | C18 | P1 | Tools registered non-shared and cloned before configuration; the D12 allowlist fix, or HTTP transports and the `env -i` wrapper on a core without it; no `close()` |
| `#[AsWorkflow]` discovery | C19 | P2 | Names only from `neuron.workflows.map` |
| Laravel validation of structured output | C20 | P2 | Subclass `StructuredOutputNode` and swap it in `Agent::nodes()` |
| `Indexer`, deterministic chunk IDs, pgvector, stream readers | C21 | P1-P2 | RAG instance per job; `Document::setId()` with deterministic IDs; upserting stores only; package-internal pgvector store; temporary files for binary loaders |
| Isolated node events, `EventRecord`, replay flag | C22 | P1 | The bridge catches and reports its own forward failures and projects records by hand; dashboards may double count after recovery |
| Evaluation service and framework-neutral stubs | C23 | P2 | Wrap `EvaluationCommand` with a container resolver and buffer its `echo` output; package-owned stubs |
| String input, approval deadline, `structured()` suspension | C24 | P2 | Wrap input in `UserMessage`; a `ToolNode` subclass swapped in through `Agent::nodes()` that overrides `buildApprovalRequest()` with a deadline computed once per approval (the method runs again for each partial-decision round) and `resolveToolApprovals()` to reject on expiry (section 11); run structured starts explicitly |
| Removal of deprecated APIs, `protected` visibility | C25 | P2 | The package never exposes `observe()` or `Node::checkpoint()` |

## 21. Delivery roadmap

The package is delivered in milestones that each leave a usable release. The first two work against today's core and are published as experimental; the later ones follow the core priorities.

1. Foundations, against today's core plus the defect fixes of the core document (D1 to D4, D8, D12 and D13 at least). The service provider, `config/neuron.php`, the storage drivers and migrations (including `QueryBuilderMessageStore`), the signing serializer decorator, `LaravelHttpClient`, `ProviderManager` (a fresh provider per call), prototype registration with reflection-checked setters, `IlluminateEventBridge` with isolation, `NeuronStream` with priming and draining, `Neuron::fake()` for providers, stores and the other core fakes, the generators with package stubs, `neuron:install` and `neuron:setup`. Done when the storage adapters pass the contract tests on SQLite, MySQL, MariaDB and PostgreSQL under Testbench 10 and 11.
2. Durable runtime, against today's core. `InvokeWorkflow` over the `neuron-core/gateway` pre-release, the run projection with `SchedulingProjectionStore` and `neuron:wake`, the administrative commands, the Reverb channel factory and refusal publisher, the optional routes with the `NeuronThread` contract, policies and exception rendering, Horizon and deploy guidance, the manifest from the configuration map, `AboutCommand` and Boost resources. Done when fault-injection scenarios run through the real `InvokeWorkflow` job on the database and Redis queue drivers and assert provider call counts: a duplicate delivery, a worker killed between the run and the acknowledgement, a worker killed between the write-ahead and the ignition of a start, a lost dispatch found by the sweep, early and duplicate wakes, a signal before its wait, and an answer redelivered after a recovery.
3. Core P0 (C1, C2, C3, C6). Definitions become singletons used through `for()`, the defaults tier replaces the reflection checks, typed exceptions replace the interim classification, and the abandoned-stream caveats disappear. Done when the interim rows of section 20 for C1, C2, C3 and C6 are deleted and the milestone 2 scenarios still pass.
4. Core P1: C4, C5, C7 to C14, C17, C18, C22 and the P1 part of C21 (the `Indexer`, the upsert contract, deterministic chunk IDs, no memory fallback and a mandatory `RetrievalTool` scope). The pre-release dependency is replaced by `NeuronAI\Gateway`; invocations become JSON-only with `ignite()`; answers fence on interrupts; `CarbonClock` drives the engine; tools get `ToolContext`; providers and tools are shared; core SQL stores take connection closures and the contract tests come from core. The package goes stable with core 4.x. Done when every P1 interim row of section 20 is deleted and the milestone 2 scenarios pass against `NeuronAI\Gateway`.
5. Core P2 and the ecosystem (C15, C16, C19, C20, the rest of C21, C23, C24, C25). `SSEStream` and the protocol request objects, `#[AsWorkflow]` discovery, `LaravelOutputMapper`, the pgvector store, stream readers and the `Embeddable` trait, core stubs, and the optional laravel/mcp adapter. Done when the remaining interim rows of section 20 are deleted.

Four maintainer decisions recorded in the core document change this plan if they go the other way. If definitions stay prototypes (the alternative to C2), the interim registration of milestone 1 becomes permanent and long-lived services keep using `WorkflowLocator`. If the defaults tier is rejected (the alternative to C3), workflow-level services go through a typed `WorkflowRuntime` and the reflection-checked setters stay for the Agent and RAG keys. If `ignite()` and the Pending status are rejected (the alternative to C9), the `start` invocation kind with a reserved run ID becomes permanent, and admission at the edge stays a racy `inspect()` pre-check whose conflicts reach the client over the push channel. If the gateway ships as a separate `neuron-core/gateway` package pinned to the exact core minor version (the alternative to C14), the pre-release becomes that package's first stable release and the dependency stays; the package never duplicates the gateway either way.

### Open questions and items to verify

- That `Date::now()`, read by `CarbonClock`, follows `travel()` and `Date::setTestNow()` in Laravel 12 and 13 when exposed through PSR-20.
- That a pending request sent with the `stream` option leaves the body unread until Neuron consumes it, so `LaravelHttpClient` streams incrementally while Telescope still records the exchange.
- How laravel/mcp registers tools (class names or instances) and whether it accepts a raw JSON schema (section 11).
- Whether the package should offer an opt-in trait whose `make()` resolves through the container, matching laravel/ai's ergonomics at the price of global state in application code.
- Differences between Laravel 12.47+ and 13 that the package must branch on: the queue attributes such as `#[Timeout]` (the package uses properties and methods that work on both), and the availability of the `background` queue driver.
- How Horizon's dashboards count a `release()`, which increments the job's attempt count, and how they should present busy threads released until their lease expires (section 8.9).
- Whether `afterCommit()` dispatch behaves as in production inside `RefreshDatabase`, whose test transaction never commits (section 7.3).
- How stancl/tenancy and spatie/laravel-multitenancy behave with the central and per-tenant layouts of section 4.7, including their queue bootstrappers and per-tenant command runners.
- Durable composition (section 4.6): whether core should let a child definition's completion commit atomically with the parent's memo, and let a child's suspension suspend its parent. Today a crash in that window repeats the child, and children cannot wait for approvals.
- Generator streaming under Octane on Swoole and FrankenPHP, where Laravel hands the generator closure to `StreamedResponse::setCallback()` and the server may only invoke it without iterating. If it does not stream, `NeuronStream` builds its own `StreamedResponse` whose non-generator callback echoes each frame and calls `ob_flush()`/`flush()`, which also works under FPM.

