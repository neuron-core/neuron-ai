# Neuron AI for Symfony: integration strategy for neuron-core/neuron-bundle

This document describes how Neuron AI 4.x integrates with Symfony 7.4 LTS and 8.x through a new bundle, `neuron-core/neuron-bundle` (namespace `NeuronAI\Symfony`, `NeuronBundle extends AbstractBundle`, configuration key `neuron`). The bundle positions Neuron as the durable orchestration layer of a Symfony application: Workflow, Agent and RAG definitions become autoconfigured services, runs are stored through Doctrine DBAL, suspended runs resume through Messenger and the Scheduler, output reaches browsers through streamed responses or Mercure, and the profiler shows what each run did. It is written as the foundation the bundle's engineers implement against. Every section states what the bundle does, which core improvement from [neuron-core-improvements.md](neuron-core-improvements.md) makes it clean, and how the bundle behaves against today's core until that improvement lands.

How to read this document: sections 1 to 3 give the positioning, the principles and the architecture; sections 4 to 9 cover the container, storage and durable runtime, which are the heart of the bundle; sections 10 to 20 cover each feature area; sections 21 to 23 give the directory layout, the table of core dependencies and the delivery roadmap. Proposed APIs are marked "(proposed, C*n*)", shortened to "(C*n*)" in tables, diagrams and code comments, with the identifier used in the core improvements document; everything not marked that way exists in core today. Shared terms (definition, handle, run, segment, fence, lease, invocation, disposition, projection, sweep) are defined in the glossary at the end of section 1 of [neuron-core-improvements.md](neuron-core-improvements.md).

## 1. Purpose and positioning

Symfony now has a first-party AI layer. Symfony AI (the `symfony/ai-*` packages, 0.14 and still experimental) ships more than forty platform bridges, a constructor-configured `Agent` with a toolbox, stores, a chat component, `#[AsTool]` autoconfiguration, a profiler panel and `AiAssertionsTrait`. It is a good answer to the question "how do I send this prompt, with these tools, to that model". It has no durable execution. `AgentInterface::call()` returns an in-memory `Execution` that cannot be re-consumed; human-in-the-loop is a synchronous `ToolCallRequested` listener that denies a call or sets its result in the same process, and the official cookbook suggests that web applications "might store pending confirmations in a database and wait"; the chat message store is a `save(MessageBag)`/`load()` pair with no conversation key and no concurrency control.

Neuron answers a different question: "how do I finish this process". Its core value is durable execution: fenced optimistic ownership of runs, persisted interruptions (tool approvals, awaited events, timers, deferred tool results), memoized steps that make recovery free because a recovered run replays committed inference instead of paying for it again, execution leases, and retained completions that survive a lost response. The bundle's one-line positioning is therefore **"Symfony AI answers a prompt; Neuron finishes a process."** The bundle does not compete on prompt ergonomics or on the number of platform bridges. It coexists with Symfony AI in the same application and interoperates with it through two optional pieces: a provider adapter that runs Neuron agents on any configured `ai.platform.*` service (section 7), and exposure of Neuron tools and durable workflows as MCP tools through `symfony/mcp-bundle` (section 12).

Three naming collisions are worth stating up front. Symfony AI has its own `#[AsTool]` attribute (`Symfony\AI\Agent\Toolbox\Attribute\AsTool`); Neuron's framework-neutral attributes live in `NeuronAI\Attributes` (proposed, C18 and C19), and generated code always imports them explicitly. Symfony AI's store layer declares `Symfony\AI\Store\ManagedStoreInterface` with `setup(array $options = [])` and `drop(array $options = [])`, the same short name and verbs as core's proposed `NeuronAI\ManagedStoreInterface` (C4); generated code imports Neuron's explicitly, and `ai:store:setup` and `neuron:setup` stay separate commands that provision separate stores. The Symfony Workflow component is a state machine and marking store, unrelated to Neuron's durable Workflow: the bundle never registers `workflow.*` or `state_machine.*` service IDs and never touches `framework.workflows`, and every bundle service ID starts with `neuron.`.

The Workflow component and Neuron are complementary rather than competing. The Workflow component tracks an entity's marking (placed, paid, shipped); Neuron executes the durable process that moves it. A Neuron node applies a transition only when `can()` still allows it, which makes the step idempotent on replay. The open RFC for a generic Symfony Durable component (symfony/symfony#66257) describes an engine without AI semantics; Neuron's durability is AI-native (approvals, deferred tool results, memoized inference, protocol adapters) and runs on the application's existing database or Redis, so the bundle watches that RFC for convergence rather than competing with it. That RFC also proposes an `#[AsWorkflow]` attribute; if it lands, applications will have two attributes with that short name, so generated code imports `NeuronAI\Attributes\AsWorkflow` explicitly, as it does for `AsTool`.

Framework integration is where durability is won or lost in production. Messenger redelivers messages at least once, workers restart on `--time-limit` and `--memory-limit`, deploys replace code under suspended runs, browsers disconnect in the middle of a stream, FrankenPHP keeps the kernel alive across requests, and several hosts run the scheduler. Today's core allows a correct integration in each of these situations, but only through conventions every package would have to rediscover. The bundle's job is to make the correct integration the default one; the core improvements referenced below exist to make that job small.

The bundle targets Symfony 7.4 LTS on PHP 8.2 and Symfony 8.x on PHP 8.4, Doctrine DBAL 4, and Neuron core 4.x. It is distributed through a Flex recipe in `symfony/recipes-contrib` and is labelled experimental until core 4.x is stable, following Symfony AI's own practice.

## 2. Design principles

The three documents share one set of principles. The table maps each one to what it means for the bundle.

| Principle | What it means in the bundle |
|---|---|
| Definitions are shared, executions are bound | Workflow, Agent and RAG classes are ordinary shared services. Controllers and handlers call `$definition->for($threadId)` (proposed, C2) after authorization; the bound handle never enters the container. |
| Configure once, at the lowest precedence | One `neuron.defaults` PSR-11 locator is applied to every definition with a `setDefaults()` call (proposed, C3). An explicit setter or an overridden class hook always wins over it. |
| Constructors belong to the application and do no I/O | Agents autowire their own constructors. After C4, stores and connectors can be instantiated eagerly without touching the network; provisioning is `bin/console neuron:setup`. |
| Shared means stateless | Providers, tools and MCP connectors become shared services only when core makes them stateless (C5, C18). Anything that keeps per-worker state implements `ResetInterface`. |
| The returned outcome is the only scheduling signal | The Messenger handler reconciles timers and the run projection from the state `run()` returns, or from `inspect()`, before the message is acknowledged. Event listeners are telemetry. |
| Every delivery is a fenced continuation | Correctness comes from core fences on every invocation. `DeduplicateStamp` is an optional cost saving, never a correctness mechanism. |
| Names and JSON cross process boundaries, PHP objects stay in the workflow store | The Messenger message carries a definition name, a workflow ID, fences and a JSON payload, so it works with the JSON transport serializer; state, interrupts and (after C9) start events live only in the workflow store. |
| Monitoring never changes execution | Neuron listeners, the forward to `event_dispatcher`, the profiler collector and Stopwatch spans can never fail a step (C22). |
| Time and failure are typed | Symfony's `clock` service is Neuron's clock (C10). The handler and the `kernel.exception` listener map exception classes (C6), never message strings. |
| Persisted records survive deploys | Engine records are versioned and definitions can declare a version (C12); the bundle routes old versions to their own transport. |
| Core owns contracts, schemas and conformance tests; adapters live where their conventions live | DBAL storage, Messenger, Scheduler, security, profiler, console and makers live in the bundle. The gateway (proposed, C14), canonical schemas and contract tests (proposed, C13), `SymfonyHttpClient` (proposed, C17) and `MercureChannel` (proposed, C16) live in core. |

## 3. Architecture at a glance

The integration has three layers. The engine never crosses into the framework layer: identity enters through `for()` (proposed, C2), input through an `ExecutionRequest`, services through hooks that are already resolved, and time through a PSR-20 clock (proposed, C10).

```text
+----------------------------------------------------------------------------+
| Application                                                                |
|  #[AsWorkflow] definitions (Workflow, Agent, RAG), tools, controllers,     |
|  Twig / Live components, Messenger handlers, commands                      |
+----------------------------------------------------------------------------+
| neuron-core/neuron-bundle  (NeuronAI\Symfony)                              |
|  DI ........ NeuronBundle, config tree, neuron.workflow locator,           |
|              neuron.defaults, #[Target] aliases, compiler passes           |
|  Storage ... DBAL persistence, message store, projection store,            |
|              schema listener, neuron:setup                                 |
|  Runtime ... Messenger InvokeWorkflow + handler, InvocationDispatcher,     |
|              SchedulingProjectionStore, Scheduler sweep                    |
|  HTTP ...... NeuronStreamResponse, value resolvers, ThreadVoter,           |
|              problem+json listener, Mercure wiring                         |
|  Tooling ... listener registry, profiler, Stopwatch, Monolog channel,      |
|              neuron:* commands, make:neuron-* makers, test trait, recipe   |
+----------------------------------------------------------------------------+
| neuron-core/neuron-ai: NeuronAI\Gateway (leaf module, proposed C14)        |
|  Invocation, Gateway::invoke()/reconcile()/due(), Disposition,             |
|  RunProjection, ProjectionStoreInterface, CompletionHandlerInterface       |
+----------------------------------------------------------------------------+
| neuron-core/neuron-ai: core                                                |
|  Workflow engine, Agent, RAG, tools, MCP, providers, HttpClient            |
|  (+ SymfonyHttpClient, C17), channels (+ MercureChannel, C16),             |
|  contracts: PSR-11 defaults (C3), PSR-14 events, PSR-20 clock (C10), PSR-3 |
+----------------------------------------------------------------------------+
```

Core definitions and components form the bottom layer. After the proposals, everything a developer declares is container-native: a definition holds configuration and shareable collaborators, receives platform services from one lowest-precedence locator, and hands out bound execution handles. The reference gateway, core improvement C14 in [neuron-core-improvements.md](neuron-core-improvements.md), is a leaf module above the engine that nothing else in core depends on. It holds what is semantically identical in Laravel and Symfony: the transport-neutral `Invocation`, the handler algorithm that binds, runs, classifies refusals and reconciles, the `Disposition` a framework translates into queue verbs, and the run projection with its sweep. The bundle holds only Symfony idioms.

A queued chat turn shows how the layers cooperate. The controller resolves the `Conversation` entity, the `#[IsGranted('NEURON_THREAD', subject: 'conversation')]` attribute runs the bundle's voter on the resolved entity, and the controller binds the injected definition with `for()`. It ignites the run synchronously, so an admission conflict becomes a 409 before any 202 (proposed, C9), hands the invocation to the bundle's `InvocationDispatcher`, which records the run as `pending` in the projection and dispatches an `InvokeWorkflow` message on the `neuron.bus`, and returns 202 with the workflow ID, the run ID and the channel (the Mercure topic). A `messenger:consume neuron` worker hands the message to the core `Gateway`, which resolves the definition by its `#[AsWorkflow]` name, binds it, runs the fenced request and reconciles the run projection from the returned state. The handler turns the resulting `Disposition` into an acknowledgement, a delayed redispatch or a failure. While the run executes, its channel factory publishes protocol events to Mercure, and the browser reassembles them with `@neuron-core/streaming`. If the run suspends on an approval with a deadline, the bundle's projection store decorator schedules a delayed wake when the gateway saves the suspended projection, and the Scheduler sweep is the backstop.

## 4. Bundle architecture

`NeuronBundle` extends `AbstractBundle` and uses its three hooks the way Symfony AI's `AiBundle` does. `configure()` imports the configuration tree from a PHP file (XML configuration was removed in DependencyInjection 8.0). `prependExtension()` adds only the `neuron` Monolog channel when MonologBundle is installed. The bundle never writes Messenger routing: its messages reach the configured transport through a `TransportNamesStamp` that the bundle's `InvocationDispatcher` adds to every dispatch (section 9), so `neuron.gateway.transport`, plus `neuron.workflows.<name>.versions` for versioned definitions, is the only place transports are named. `loadExtension()` imports `config/services.php`, turns the configuration tree into service definitions, and removes whatever depends on optional packages that are not installed, using `ContainerBuilder::willBeAvailable()`. The bundle extends HttpKernel's `AbstractBundle`, which exists on 7.4 and 8.x; Symfony 8.1 moved bundle infrastructure into the DependencyInjection component, and switching the parent class is a concern for the Symfony 9 line.

The bundle deliberately does not prepend a Messenger bus. Prepending a `buses` entry in an application that declares none would make `neuron.bus` the only bus, and therefore the default one, silently changing where the application's own messages go. The Flex recipe writes the bus and the transports into the application's configuration instead (section 20), where the developer can see them. Because declaring any bus disables FrameworkBundle's implicit `messenger.bus.default`, the recipe also declares `messenger.bus.default` and names it as `default_bus` (section 20 covers applications that already declare buses), and it sets `failure_transport: neuron_failed` on the `neuron` transport, not globally, so the application's own failure transport is untouched. A compiler pass verifies that the bus the bundle is told to use has no `doctrine_transaction` middleware and is not the application's default bus.

```php
<?php

declare(strict_types=1);

namespace NeuronAI\Symfony;

use NeuronAI\Attributes\AsWorkflow;
use NeuronAI\Symfony\Attribute\AsNeuronListener;
use NeuronAI\Symfony\DependencyInjection\Compiler\DebugDecoratorsPass;
use NeuronAI\Symfony\DependencyInjection\Compiler\LegacyDefaultsPass;
use NeuronAI\Symfony\DependencyInjection\Compiler\ListenerRegistryPass;
use NeuronAI\Symfony\DependencyInjection\Compiler\ValidateDefinitionsPass;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Workflow\Workflow;
use Symfony\Bundle\MercureBundle\MercureBundle;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class NeuronBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import('../config/definition.php');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // No Messenger routing: InvocationDispatcher stamps the configured transport (section 9).
        if (ContainerBuilder::willBeAvailable('symfony/monolog-bundle', MonologBundle::class, ['neuron-core/neuron-bundle'])) {
            $builder->prependExtensionConfig('monolog', ['channels' => ['neuron']]);
        }
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        // Providers, persistence, message store, embeddings, vector stores, MCP servers,
        // gateway and streaming services are registered from $config here.

        if (!ContainerBuilder::willBeAvailable('symfony/mercure-bundle', MercureBundle::class, ['neuron-core/neuron-bundle'])) {
            $builder->removeDefinition('neuron.channel_factory.mercure');
        }

        $builder->registerAttributeForAutoconfiguration(
            AsWorkflow::class, // proposed, C19
            static function (ChildDefinition $definition, AsWorkflow $workflow): void {
                $definition->addTag('neuron.workflow', ['name' => $workflow->name]);
            },
        );
        $builder->registerAttributeForAutoconfiguration(
            AsNeuronListener::class,
            static function (ChildDefinition $definition, AsNeuronListener $listener): void {
                $definition->addTag('neuron.listener', ['event' => $listener->event]);
            },
        );
        $builder->registerForAutoconfiguration(Workflow::class)
            ->addMethodCall('setDefaults', [new Reference('neuron.defaults')]); // proposed, C3
        // ToolkitInterface does not extend ToolInterface, so both are tagged.
        $builder->registerForAutoconfiguration(ToolInterface::class)->addTag('neuron.tool');
        $builder->registerForAutoconfiguration(ToolkitInterface::class)->addTag('neuron.tool');
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new ValidateDefinitionsPass());
        $container->addCompilerPass(new ListenerRegistryPass());
        $container->addCompilerPass(new DebugDecoratorsPass());
        $container->addCompilerPass(new LegacyDefaultsPass()); // interim, removed with C3
    }
}
```

Compile time is where the bundle catches mistakes that would otherwise surface in the middle of a conversation. `ValidateDefinitionsPass` fails the container build on duplicate `#[AsWorkflow]` names or duplicate tool names in the catalog. It runs `ToolValidator::validateClass()` on every tagged tool (proposed, C18), applies per-workflow scalars from configuration (the lease), checks that the bus used by the gateway has no `doctrine_transaction` middleware and is not the application's default bus, verifies that the configured serializer's extension is loaded, and, where both values are static at compile time (a configured `neuron.workflows.<name>.lease`, or the Agent's 600-second default when the class does not override `leaseTimeout()`), compares the lease with the providers' HTTP `max_duration` (section 9); `neuron:setup --check` reports the rest. `ListenerRegistryPass` collects `#[AsNeuronListener]` services into Neuron's own listener registry (section 15). `DebugDecoratorsPass` runs only when `kernel.debug` is true and decorates providers, persistence and message stores with Traceable services for the profiler.

Against today's core, `loadExtension()` autoconfigures the bundle's own `NeuronAI\Symfony\Attribute\AsWorkflow` until C19, marks every `WorkflowInterface` service `shared: false` until C2, and replaces the `setDefaults()` call with `LegacyDefaultsPass`, which adds setters only where the class does not override the matching hook, until C3 (section 6).

The durable runtime is the bundle's purpose, so Messenger, the Scheduler, the Lock component, DoctrineBundle and the Doctrine Messenger transport are required dependencies (section 21), which lets the recipe configure them unconditionally. The optional integrations are detected at compile time, with `willBeAvailable()` for packages and `extension_loaded()` for extensions: MercureBundle (push), SecurityBundle (the voter), WebProfilerBundle (the collector), MonologBundle (the log channel), MakerBundle (the makers), `symfony/mcp-bundle` (MCP exposure), `symfony/ai-platform` (the provider adapter) and `ext-redis` (Redis persistence). A bundle with none of them installed still wires definitions, defaults, providers, the HTTP client, DBAL storage and the Messenger runtime.

## 5. Configuration tree

The tree is defined in `config/definition.php` and validated at compile time: provider drivers, persistence types and serializer names are enums, the `igbinary` serializer requires the extension, and the `file` and `memory` persistence types are refused when `kernel.environment` is `prod`. Credentials only appear as `%env()%` parameters. The sketch below shows the full tree with illustrative entries; the Flex recipe (section 20) writes only the default provider, persistence, serializer, message store and gateway blocks.

```yaml
# config/packages/neuron.yaml
neuron:
    # PSR-18 is not used: Neuron needs incremental streaming. This is the id of a
    # Symfony\Contracts\HttpClient\HttpClientInterface service (a scoped client is fine).
    http_client: http_client

    default_provider: main
    default_embeddings: default
    default_vector_store: docs
    providers:
        main:
            driver: anthropic              # anthropic | openai | gemini | mistral | ollama | ... | symfony_ai
            key: '%env(ANTHROPIC_API_KEY)%'
            model: '%env(NEURON_MODEL)%'
            parameters: { max_tokens: 4096 }
        fast:
            driver: openai
            key: '%env(OPENAI_API_KEY)%'
            model: gpt-4.1-mini
        platform:                          # optional Symfony AI interop (section 7)
            driver: symfony_ai
            platform: ai.platform.openai
            model: gpt-4.1

    persistence:
        type: dbal                         # dbal | redis | file | memory
        connection: default                # a doctrine.dbal connection; a dedicated 'neuron' one is recommended (section 8)
        table: neuron_workflow_store
        # redis: { dsn: '%env(NEURON_REDIS_DSN)%', prefix: 'neuron:workflow:' }

    serializer:
        type: php                          # php | igbinary; must stay stable across deploys
        signed: false                      # HMAC over %kernel.secret% (C12); enable before the first production run
        previous_secrets: []               # earlier kernel.secret values still accepted when verifying (rotation)

    message_store:
        type: dbal                         # dbal | file | memory
        connection: default
        table: neuron_chat_messages

    workflows:                             # per-definition settings, keyed by #[AsWorkflow] name
        support:
            lease: 600                     # seconds; must exceed the HTTP max duration
            mode: stream                   # stream | queue: how the optional turn route answers (section 10)
            channel: mercure               # push queued output (section 11)
            prune_after: '30 days'         # neuron:prune abandons idle suspended or parked runs older than this
        orders:
            lease: 300
            mode: queue
            prune_after: '90 days'
            versions: { '1': neuron_orders_v1 }   # recorded definition version => transport serving it (section 9)
        # legacy_flow:
        #     class: App\Neuron\LegacyFlow        # only for classes without #[AsWorkflow]

    gateway:
        bus: neuron.bus
        transport: neuron
        max_delay: null                    # transport delay cap in seconds (900 on SQS)
        signal_window: 3600                # seconds a signal may wait for its interrupt to open before it is discarded
        retry_window: 86400                # interim until C9: how long terminal start rows are kept
        sweep:
            every: '1 minute'
            batch: 500
        transaction_guard: true            # refuse to invoke inside a transaction on the Neuron connection

    streaming:
        transport: mercure                 # mercure | fake (tests: one shared FakeChannel records every push)
        mercure:
            hub: default
            topic_prefix: 'neuron/threads/'
            batch_size: 1

    embeddings:
        default:
            driver: openai
            key: '%env(OPENAI_API_KEY)%'
            model: text-embedding-3-small

    vector_stores:
        docs:
            type: qdrant
            collection_url: '%env(QDRANT_COLLECTION_URL)%'
            key: '%env(QDRANT_KEY)%'
            dimension: 1536

    classifiers:
        triage:
            driver: typesafe               # typesafe (TypeSafeAI) | fake
            key: '%env(TYPESAFE_API_KEY)%'

    mcp:
        servers:
            github:
                url: 'https://api.githubcopilot.com/mcp/'
                token: '%env(GITHUB_MCP_TOKEN)%'
            files:
                command: npx
                args: ['-y', '@modelcontextprotocol/server-filesystem', '%kernel.project_dir%/var/agents']
                env: { PATH: '%env(PATH)%' }  # explicit allowlist (C18)
                timeout: 30                   # configurable with C18; fixed at 30 s in StdioTransport today

    observability:
        log_channel: neuron
        log_state: false                   # false: logs keep identifiers, names and usage, never state, prompts or tool payloads
        profiler: '%kernel.debug%'
```

Scalars never go through the defaults tier. A lease is an explicit decision about one definition, so the bundle turns `neuron.workflows.<name>.lease` into a `setLeaseTimeout()` method call on that service, and explicit configuration wins over the class's `leaseTimeout()` hook exactly as a hand-written setter would. Definitions without an entry keep their own hooks: an `Agent` keeps its 600-second lease, and a plain `Workflow` has none, which the gateway refuses for definitions it drives (section 9). The other per-definition keys are read by the bundle, not by core: `mode` tells the optional turn route whether to stream or to queue (default `stream` for Agents, `queue` for plain Workflows), `prune_after` bounds how long `neuron:prune` leaves an idle run alone (section 8), and `versions` maps a recorded definition version to the transport whose consumers still run that version (section 9). Definition versions are not configuration: they are declared on the class with `#[AsWorkflow(version: ...)]` or the `version()` hook (proposed, C12), because a version taken from each deploy's environment would make the continuation of every suspended run throw `DefinitionVersionException` unless the old consumers kept running; a version changes only when the graph changes incompatibly.

`default_provider`, `default_embeddings` and `default_vector_store` name the entries aliased as `neuron.provider.default`, `neuron.embeddings.default` and `neuron.vector_store.default`, which the defaults tier references (section 6). When exactly one entry of a kind is configured it is the default; when several are configured and the setting is unset, the alias and its defaults-tier key are absent. Named entries (providers, embeddings, vector stores, classifiers, MCP servers) are prototype nodes without deep merging, so a later configuration file such as `when@test` replaces an entry wholesale (`docs: { type: fake }`) instead of merging its keys into the production ones. Vector store and embeddings entries follow the provider rule of section 7: the bundle maps the `type` or `driver` to a class and passes the remaining options as that class's named constructor arguments (snake_case keys mapped to the parameter names), which is why the Qdrant entry uses `collection_url` and `dimension` for `QdrantVectorStore::__construct()`'s `$collectionUrl` and `$dimension`.

## 6. Services, lifetimes and autoconfiguration

### Definitions and handles

A definition is a Workflow, Agent or RAG class carrying `#[AsWorkflow(name: 'support')]` (proposed, C19). Autoconfiguration tags it `neuron.workflow` with its name, and the name is the stable alias that messages, routes and commands carry: never a class name, which would break on the first refactoring of a namespace. The bundle builds one locator over the tag, and any service asks for it with `#[AutowireLocator('neuron.workflow', indexAttribute: 'name')]`. The gateway receives the same locator as its PSR-11 `$workflows` argument through `tagged_locator('neuron.workflow', 'name')`.

With core improvement C2 ([neuron-core-improvements.md](neuron-core-improvements.md)), definitions are ordinary shared services, which is Symfony's default for autodiscovered `App\` classes. The application injects `SupportAgent` into a controller action, a Live component, a handler or a command, authorizes the thread, and calls `$agent->for($threadId)`. `for()` returns a bound clone for one call and never mutates the receiver, so a FrankenPHP worker or a Messenger consumer can serve any number of threads with one container-built instance. The application owns the definition's constructor (C4) and injects whatever it needs:

```php
<?php

declare(strict_types=1);

namespace App\Neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\Attributes\AsWorkflow;
use NeuronAI\Providers\AIProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AsWorkflow(name: 'support')] // proposed, C19
class SupportAgent extends Agent
{
    public function __construct(
        #[Target('fast')] protected AIProviderInterface $fast,
        protected OrderLookupTool $orders,
        protected IssueRefundTool $refunds,
    ) {
    }

    protected function provider(): AIProviderInterface
    {
        return $this->fast;
    }

    protected function instructions(): string
    {
        return 'You help customers with their orders.';
    }

    protected function tools(): array
    {
        return [$this->orders, $this->refunds->withApproval()]; // withApproval(): proposed, C18
    }
}
```

Handles never enter the container and never cross a process boundary. A handle may be customized for one request, for example with a stream adapter for a pull stream, without touching the shared definition. Per-segment factories receive the segment's `ExecutionContext` (proposed, C2), so a closure registered once in the container can build a channel for `$context->workflowId` without capturing a definition. A subclass that adds mutable fields of its own defines `__clone()`, the same contract nodes and middleware already follow. The rule that keeps this safe is simple: definitions are configured at build time and customized per call only on handles returned by `for()`. `setStreamAdapter()`, `subscribe()`, `addMiddleware()` and `setAiProvider()` stay public after C2, and calling them on an injected shared definition at request time would leak the change into every later request of a FrankenPHP worker or message of a Messenger consumer.

Definitions compose. An Agent or a RAG can run inside a node of a larger Workflow, as `src/Agent/AGENTS.md` suggests, and durability constrains how. The child needs an address of its own, derived from the parent's and never chosen by a node, so the parent's resources factory binds it: `$this->drafter->for($context->workflowId.'.draft')` (proposed, C2); today the factory takes a fresh prototype from the `neuron.workflow` locator and calls `setThreadId()` with the same derived ID. The node wraps the child's call in `memoize()`, so a recovery of the parent reuses the child's answer instead of paying for it again:

```php
class TicketWorkflow extends Workflow
{
    public function __construct(protected SupportDrafter $drafter)
    {
    }

    // Proposed signature (C2): the hook receives the segment's context.
    protected function resources(ExecutionContext $context): TicketResources
    {
        return new TicketResources($this->drafter->for($context->workflowId.'.draft'));
    }
}

class DraftReplyNode extends Node
{
    public function __invoke(TicketReceived $event, WorkflowState $state, TicketResources $resources): ReplyDrafted
    {
        $draft = $this->memoize('draft', fn (): ?string => $resources->drafter
            ->chat(new UserMessage($event->body))
            ->getMessage()?->getContent());

        return new ReplyDrafted($draft ?? '');
    }
}
```

Two limits remain open. A crash between the child's completion and the commit of the parent's memo reruns the child, which for an Agent appends a second turn to the child's thread. And a child that suspends, for example on an approval of its own, cannot suspend its parent: the child waits on its own address, which neither the parent's run nor its projection row knows. Until core defines how runs compose, children used as nodes must not suspend, and approvals belong to the parent. Both limits are open questions for core ([neuron-core-improvements.md](neuron-core-improvements.md), section 8) and are listed at the end of this document.

C2 has a documented alternative that the maintainer may prefer: keep definitions as prototypes and add only a factory or registry contract. It is simpler for core but leaves every application one `shared: true` away from a cross-thread leak, so this document recommends C2.

**Interim (today's core).** A container-built instance is both a definition and an address. `Workflow::setWorkflowId()` refuses to re-point a bound instance (`src/Workflow/Workflow.php:122`), and executing an unbound instance binds a generated ID to it (`Workflow.php:273`). A shared Agent therefore throws on the second thread in a worker, and an unbound shared Agent executed once makes every later unbound caller continue the first caller's conversation. Until C2 lands the bundle forces prototypes: `registerForAutoconfiguration(WorkflowInterface::class)->setShared(false)`. Controllers take definitions as action arguments (resolved per call) or through the `neuron.workflow` locator, never in the constructor of a shared service, and call `setThreadId()` or `setWorkflowId()` immediately after resolution. Generic code (the gateway pre-release of section 9, commands) types against the concrete `Workflow` class, because `WorkflowInterface` declares no binder. A subclass that declares a constructor must call `parent::__construct()`, because `Workflow::__construct()` initializes the exporter and the initial state (removed by C4). Until C19, the bundle ships its own `#[AsWorkflow]` attribute with the same shape in `NeuronAI\Symfony\Attribute`, and the `neuron.workflows.<name>.class` map covers classes without it.

### The defaults tier

Platform services reach definitions through one PSR-11 locator, `neuron.defaults`, keyed by interface FQCN (proposed, core improvement C3 in [neuron-core-improvements.md](neuron-core-improvements.md)). The base hooks consult it after an explicit setter and after an overridden class hook, and before their core fallback, so the bundle configures every definition once without overriding a class's own decisions. The key list is closed and documented per module, which keeps the locator from becoming a general service locator.

```php
// config/services.php (excerpt)
$services->set('neuron.defaults', ServiceLocator::class)
    ->args([[
        PersistenceInterface::class => service('neuron.persistence'),
        Serializer::class => service('neuron.serializer'),
        ClockInterface::class => service('clock'),                       // C10
        EventDispatcherInterface::class => service('neuron.event_dispatcher'),
        BranchRunner::class => service('neuron.branch_runner'),
        MessageStoreInterface::class => service('neuron.message_store'),
        AIProviderInterface::class => service('neuron.provider.default'),
        EmbeddingsProviderInterface::class => service('neuron.embeddings.default'),
        VectorStoreInterface::class => service('neuron.vector_store.default'),
        OutputMapperInterface::class => service('neuron.output_mapper'),  // C20
    ]])
    ->tag('container.service_locator');
```

Handles made by `for()` carry the same defaults, and `WorkflowEngine` and `Segment` never see the container. An entry is present only when the matching configuration exists: an application without a vector store simply has no `VectorStoreInterface` key, and RAG's hook fails loudly instead of falling back to memory (C21).

**Interim (today's core).** There is no defaults tier. A setter applied from the container wins over the class's hook, because the getters resolve `$this->persistence ??= $this->persistence()` (`src/Workflow/HandleComponents.php`). The bundle therefore adds setter calls only where the class does not override the hook, decided once at compile time: a pass reflects each `neuron.workflow` class and adds `setPersistence()`, `setSerializer()`, `setMessageStore()`, `setAiProvider()`, `setEmbeddingsProvider()`, `setVectorStore()` or `setBranchRunner()` only when `ReflectionMethod::getDeclaringClass()` for the matching hook is `Workflow`, `Agent` or `RAG`. `setEventDispatcher()` is always added, because it has no hook.

### Named services and #[Target] aliases

Every named collaborator becomes a service `neuron.<kind>.<name>` with an autowiring alias registered through `registerAliasForArgument($id, AIProviderInterface::class, (new Target($name))->getParsedName())`, the pattern `AiBundle` uses. Applications inject `#[Target('fast')] AIProviderInterface $provider`, `#[Target('docs')] VectorStoreInterface $store` or `#[Target('github')] McpConnector $github`. Symfony 8.1 deprecates named autowiring aliases that are not used through `#[Target]`, so generated code always uses the attribute. When exactly one provider, store or connector is configured, the interface itself is aliased to it.

### Lifetimes

The target lifetimes assume C2 to C5 and C18; the last column is the registration against today's core.

| Component | Target lifetime | Symfony registration | Interim (today's core) |
|---|---|---|---|
| Workflow / Agent / RAG definition | shared | autoconfigured service tagged `neuron.workflow {name}`, `setDefaults` method call | `shared: false` via `_instanceof`, setters from the compile-time defaults pass |
| Execution handle `$definition->for($id)` | per call | built in the controller or handler after the voter, never a service | `setThreadId()` on a freshly resolved prototype |
| `neuron.defaults` | shared | `ServiceLocator` keyed by interface FQCN | not used |
| Persistence, serializer, clock, `WorkflowEngine` | shared | `neuron.persistence`, `neuron.serializer`, `clock`, `neuron.engine` | same (DBAL backends hold a DBAL `Connection`, not a PDO) |
| Message store | shared | `neuron.message_store` | same |
| Provider per name | shared | `neuron.provider.<name>` plus `#[Target]` alias | `shared: false`, never injected into a shared service |
| HTTP client | shared per worker | `SymfonyHttpClient` over `http_client` or a scoped client | bundle-internal adapter (section 7) |
| Embeddings, vector stores, indexer | shared | `neuron.embeddings.<name>`, `neuron.vector_store.<name>`, `neuron.indexer.<name>` | vector stores `lazy: true` (constructors do I/O) |
| Tools and toolkits | shared prototypes, cloned per call | autoconfigured tag `neuron.tool` | `shared: false`; configure only inside `tools()` |
| MCP connectors | per worker (static credentials) or per request or message (per-user credentials) | `neuron.mcp.<name>`, tagged `kernel.reset` with `method: close` | shared, lazy session; per-user connectors from a factory |
| Classifiers | shared | `neuron.classifier.<name>` plus `#[Target]` alias | same: classifiers keep no per-call state today |
| `WorkflowResources` subclasses | per segment | `shared: false`, fetched through a service closure inside the factory | built inside the `resources()` hook |
| Nodes, middleware, stream adapters, channels | per segment | built in factories, excluded from resource discovery | same |
| Gateway, projection store, listener registry, translators, output mapper | shared | shared services | the gateway from the shared `@internal` pre-release of `neuron-core/gateway`; no output mapper until C20 |
| Test fakes | per test | `when@test` services or `static::getContainer()->set()` | same |

Services that keep state across requests or messages implement `Symfony\Contracts\Service\ResetInterface` and are tagged `kernel.reset`: the Traceable decorators, the profiler's event recorder and stateful Neuron listeners. MCP connectors are tagged `kernel.reset` with `method: close` once C18 adds `close()` (proposed, C18). `ResetServicesListener` resets them between Messenger messages and the kernel between FrankenPHP requests; they also behave correctly under `messenger:consume --no-reset` (available on 7.4 and 8.x; 8.1 also accepts `--no-reset=N` to reset every N messages), because none of them carries correctness-relevant state. Definitions need no reset after C2: they hold only configuration.

## 7. Providers and HTTP

### Provider services

Each entry under `neuron.providers` becomes a service `neuron.provider.<name>` built from a driver map (driver name to provider class) with the entry's options spread as named constructor arguments, and `neuron.provider.default` aliases the entry named by `default_provider`. After core improvement C5 ([neuron-core-improvements.md](neuron-core-improvements.md)) the map is a few lines, because every HTTP chat and embeddings provider follows one constructor convention (key, model, parameters, base URI, HTTP client, then vendor extras by name) and every provider is stateless: `chat()`, `stream()` and `structured()` receive a `ProviderRequest` carrying the messages, the instructions and the tools of that call. Providers then become ordinary shared services, safe across requests, Messenger messages and concurrent branches, and the default one is placed in `neuron.defaults`.

**Interim (today's core).** Providers are mutable. `ChatNode` configures the provider on every call with `systemPrompt(...)->setTools(...)` (`src/Agent/Nodes/ChatNode.php:81-82`), and some providers keep stream state on the instance, so a shared provider mixes instructions and tools between branches that interleave on it (`AsyncBranchRunner`), hands them to any caller that does not set its own, and leaks stream metadata between callers: `OpenAI::enrichMessage()` copies the previous stream's metadata into later responses. The bundle registers provider services with `shared: false` and forbids injecting them into shared services. A prototype agent that receives a non-shared provider in its constructor owns that instance for its single call, which is safe; an agent instance that runs several segments in one request (a turn, then an approval) should obtain its provider from a factory inside its `provider()` hook, which `HandleProvider::getProvider()` calls again for every segment. The driver map translates configuration per driver, because constructor parameter names differ today: `Ollama` takes `url` and no key, `OpenAILike` and `Gemini` take `baseUri`, and `OpenAI` and `Anthropic` accept no base URI at all.

### The HTTP client

Every HTTP-based component the bundle builds (chat providers, embeddings providers, vector stores, classifiers, MCP HTTP transports, HTTP tools) receives one shared `SymfonyHttpClient`, an implementation of Neuron's `HttpClientInterface` over `Symfony\Contracts\HttpClient\HttpClientInterface` that core improvement C17 adds to core next to the existing cURL, Guzzle and Amp adapters. Like core's own clients, the adapter honours the rule that connections belong to the process that opened them (`src/HttpClient/AGENTS.md`): when it detects a new process ID (a child forked by `parallelToolCalls()`), it stops using the injected client and builds a private transport with `HttpClient::create()` and the same default options. It never closes or resets the inherited client, because destroying inherited curl handles would shut down the parent's TLS sessions. `request()` maps to a Symfony request; `stream()` backs Neuron's pull `StreamInterface` (`eof()`, `read()`, `readLine()`, `close()`) with the client's chunk API. Neuron deliberately does not use PSR-18, because PSR-18 cannot express incremental streaming.

Running provider traffic through Symfony's client brings the platform's tooling for free: in debug every LLM call appears in the profiler's HTTP Client panel, in tests `MockHttpClient` and `MockResponse` (or `framework.http_client.mock_response_factory`) replace the network, streamed responses included, and a scoped client named by `neuron.http_client` can pin proxies or certificates for Neuron's traffic. `RetryableHttpClient` is acceptable only when its retries, added together, stay inside the HTTP time budget, because a retry loop that outlives the execution lease looks like a dead worker to the engine.

Two families are built by application code rather than by the bundle, and must receive the same client explicitly. Reranking post-processors (`JinaRerankerPostProcessor`, `CohereRerankerPostProcessor` and `LocalAIRerankerPostProcessor`, which use `HasHttpClient`) are created inside a RAG's `postProcessors()` hook, so the RAG takes the injected `NeuronAI\HttpClient\HttpClientInterface` in its constructor and passes it on; a reranker left on its default falls back to `CurlHttpClient` and escapes both the profiler and `MockHttpClient`. Audio and image providers (`OpenAITextToSpeech`, `OpenAISpeechToText`, `OpenAIImage`, the ElevenLabs and ZAI classes) accept the same `httpClient` argument, but today they implement `AIProviderInterface` like chat providers, so the `neuron.providers` map would offer them to agents; the bundle configures them as a separate kind once C5 separates the capabilities, and until then applications build them with the injected client.

C17 also fixes the contract details the bundle relies on: every adapter raises `HttpException` for status 400 and above (streams at header arrival), so an error body is never parsed and memoized as a model answer; `RetryableHttpException` (408, 425, 429 and 5xx) carries `retryAt()` from `Retry-After`; timeouts distinguish idle time from maximum duration; and exceptions carry a redacted request, so API keys never reach logs, the profiler's exception panel or an error tracker through a Neuron exception. The profiler's HTTP Client panel still records request headers, including `Authorization` and `x-api-key`, so profiles must never be collected with production keys. The application sets `max_duration` on the client that providers use (the `framework.http_client` default options or the scoped client named by `neuron.http_client`), and `ValidateDefinitionsPass` fails the build when it is not below the lease of every gateway-driven definition, where both values are static.

**Interim (today's core).** Core has no Symfony adapter. The bundle ships an internal one with the same shape, deleted when C17 lands. It raises `HttpException::statusError()` itself for any status of 400 or more, maps Symfony's transport exceptions to `HttpException::networkError()`, and passes a copy of the request with the `Authorization`, `x-api-key` and similar headers removed, because today's `HttpException` exposes its request as a public property. On Symfony 8.1, `GuzzleHttpClient` over Symfony's `GuzzleHttpHandler` is a fallback, but whether it preserves incremental SSE delivery has not been verified.

### The Symfony AI Platform adapter

The optional `SymfonyAIPlatformProvider` implements Neuron's `AIProviderInterface` over `Symfony\AI\Platform\PlatformInterface::invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult`. It converts Neuron messages to a Symfony AI `MessageBag`, passes tool definitions through the invocation options, and maps the result (`getResult()` for complete responses, `asStream()` for streams) back to Neuron messages and stream chunks. An application that already configures `ai.platform.openai`, a failover platform or a cache platform can run Neuron agents on it with `driver: symfony_ai` and gain durability, memoization and approvals on top. The adapter follows Symfony AI's 0.x API and ships as experimental. It also maps Symfony AI's errors: `RateLimitExceededException::getRetryAfter()` becomes a retryable exception carrying `retryAt()` (C6, C17), so the gateway delays the run exactly as it does for a provider 429 through `SymfonyHttpClient`; its tool-call, usage and error mapping must be verified per supported bridge. Like any provider, it becomes shared once C5 lands.

### Classifiers

Classification (`NeuronAI\Classifier`) is a contract of its own, separate from chat: `ClassifierInterface::classify(ClassificationRequest): ClassificationResult` answers closed questions (`Choice`, `Score`, `Boolean`) with probabilities. Each entry under `neuron.classifiers` becomes a `neuron.classifier.<name>` service with a `#[Target]` alias, built from a driver map (`typesafe` for `TypeSafeAI`, `fake` for `FakeClassifier`) over the shared `SymfonyHttpClient`. Classifiers are shared services today, without waiting for C5, because `src/Classifier/AGENTS.md` forbids storing request input or questions on the instance, which therefore holds only its key, model and client. A classifier reaches nodes the way every service does, through a `WorkflowResources` subclass that the definition builds. The node wraps `classify()` in `memoize()`, because a classification is paid and non-deterministic, and a recovery that classified again could route the same ticket differently. Thresholds, abstention and routing stay in the node, as the module's guidance requires:

```php
final class TriageResources extends WorkflowResources
{
    public function __construct(public readonly ClassifierInterface $classifier)
    {
        parent::__construct();
    }
}

#[AsWorkflow(name: 'triage')] // proposed, C19
class TicketTriage extends Workflow
{
    public function __construct(#[Target('triage')] protected ClassifierInterface $classifier)
    {
    }

    protected function resources(): TriageResources
    {
        return new TriageResources($this->classifier);
    }
}

class TriageNode extends Node
{
    public function __invoke(TicketReceived $event, WorkflowState $state, TriageResources $resources): TicketRouted|NeedsHumanTriage
    {
        // Paid and non-deterministic: a recovery reuses the committed answer.
        [$department, $probability] = $this->memoize('triage', function () use ($event, $resources): array {
            $answer = $resources->classifier->classify(new ClassificationRequest(
                input: ['ticket' => $event->body],
                questions: ['department' => new Choice('Which department should handle this ticket?', [
                    'billing' => 'Charges and payment problems.',
                    'shipping' => 'Delivery and tracking problems.',
                    'other' => 'Requests outside these departments.',
                ])],
            ))->choice('department');

            return [$answer->choice, $answer->distribution->probabilities[$answer->choice]];
        });

        // The threshold is an application decision, never the classifier's.
        return $probability >= 0.8 ? new TicketRouted($department) : new NeedsHumanTriage($department, $probability);
    }
}
```

The same services back `ClassifierJudge` in evaluations (section 17).

## 8. Durable storage with Doctrine DBAL

### Backends

The bundle stores runs through Doctrine DBAL, not through a PDO extracted from it. `DoctrineDbalPersistence` implements core's four-operation `PersistenceInterface`, `DoctrineDbalMessageStore` implements `MessageStoreInterface`, and `DoctrineDbalProjectionStore` implements the gateway's `ProjectionStoreInterface` (proposed, C14). Each holds a DBAL `Connection` service and uses it per operation. That single choice solves three problems at once. DBAL reconnects lazily after `close()`, so closing or pinging the connection between messages or requests never leaves the store with a dead handle; the backends work on every DBAL driver, including mysqli, pgsql and sqlite3, whose native connection is not a PDO; and Neuron's queries go through DBAL middlewares, so they appear in the Doctrine profiler panel and in query logging and tracing.

The operations map onto DBAL directly. `initializeIfAbsent()` inserts the condition record and the related records inside `Connection::transactional()` and returns false on `UniqueConstraintViolationException`, instead of inspecting driver error codes. `writeIfUnchanged()` and `deleteIfUnchanged()` lock the condition row (`SELECT … FOR UPDATE` through the query builder's `forUpdate()` on PostgreSQL, MySQL and MariaDB; on SQLite, where DBAL rejects `forUpdate()`, the backend first takes the writer lock with a no-op `UPDATE` of the condition row, as core's `DatabasePersistence` and `EloquentPersistence` do, because upgrading a deferred read transaction races with other writers), compare the stored bytes with the expected value, and then upsert the records or delete the partition within the same transaction. Upserts are platform-aware: `ON CONFLICT` on PostgreSQL and SQLite, `ON DUPLICATE KEY UPDATE` on MySQL and MariaDB. The comparison is byte-exact, which is what makes the compare-and-swap safe with a signing serializer (C12).

**Interim (today's core).** The backends are bundle classes over the public `PersistenceInterface` and `MessageStoreInterface`, so they work today. What core adds is conformance: until the abstract contract test cases ship in `src/Testing` (C13), the bundle ports pinned copies of `tests/Workflow/Persistence/PersistenceContractTest.php` and `tests/Chat/History/MessageStoreContractTest.php`, replacing their backend data providers with its DBAL backends and dropping the file- and Eloquent-specific cases, and re-syncs them on every core minor release. Core's `DatabasePersistence` and `SQLMessageStore` are not used in Symfony: both capture a `PDO` in their constructor (`src/Workflow/Persistence/DatabasePersistence.php`, `src/Chat/History/SQLMessageStore.php`), which goes stale after a DBAL reconnect and bypasses DBAL entirely.

### One schema, emitted into migrations

With core improvement C13 ([neuron-core-improvements.md](neuron-core-improvements.md)), core defines one canonical table per store: the workflow store has an `id` primary key, `UNIQUE(partition, key)`, the value, `created_at` and `updated_at`; the message store has `UNIQUE(thread_id, message_id)`; identifiers are hex-encoded into ASCII columns of 510 characters (`ascii_bin` on MySQL and MariaDB), as core's `DatabasePersistence` already stores them, values are base64-encoded long text because serializers produce arbitrary bytes, and deletes are physical. Identical encodings are what let a Laravel and a Symfony application that share a database operate on the same runs. The bundle's default table names carry the `neuron_` prefix: `neuron_workflow_store`, `neuron_chat_messages` and `neuron_runs`.

The DBAL backends expose `configureSchema(Schema $schema, Closure $isSameDatabase): Schema`, which returns the possibly new schema as Lock's `DoctrineDbalStore::configureSchema()` does, so it keeps working with DBAL's immutable schema editing. `NeuronSchemaListener` extends `Symfony\Bridge\Doctrine\SchemaListener\AbstractSchemaListener`, is tagged `doctrine.event_listener` for `postGenerateSchema`, calls it for every store that lives in the database being diffed, and passes the result through `filterSchemaChanges()` and, where that method exists, `GenerateSchemaEventArgs::setSchema()`. The template is DoctrineBridge's `LockStoreSchemaListener`. With it, `doctrine:migrations:diff` generates the three tables and never proposes dropping them. `postGenerateSchema` is an ORM schema-tool event, so applications that use DBAL without the ORM provision with `bin/console neuron:setup` instead, which calls `setup()` on every service implementing `ManagedStoreInterface` (proposed, C4), in the style of `messenger:setup-transports`.

**Interim (today's core).** `ManagedStoreInterface` does not exist and the canonical DDL lives only in docblocks (`DatabasePersistence.php:29-44`). The bundle defines the three tables itself, in its DBAL backends, and `neuron:setup` calls their own `setup()` methods plus the existing provisioning calls of vector stores.

### Connections, transactions and middlewares

The bundle recommends a dedicated DBAL connection named `neuron`, which may point at the application's database; the recipe's post-install message shows the connection block and the two keys that switch Neuron to it (section 20). The reason is transactional isolation. The engine's writes are fenced compare-and-swap operations that other workers must see as soon as they commit; a workflow executed inside an application transaction would hide its step commits from every other worker until the outer commit, and a rollback would erase steps whose side effects already happened. The rule, stated once for both frameworks, is: never run a workflow inside an application transaction on the persistence connection. With `neuron.gateway.transaction_guard` enabled, the gateway handler and the stream response refuse to start when `Connection::isTransactionActive()` is true on the Neuron connection. Test suites that wrap every test in a transaction with DAMA DoctrineTestBundle disable the guard under `when@test`.

On the Messenger side, `doctrine_transaction` is forbidden on the bus that runs invocations, which is why the gateway uses its own bus. `doctrine_ping_connection` and `doctrine_close_connection` are safe, because DBAL reconnects lazily after `close()`. On 7.4 to 8.1 they only act on the connection of an ORM entity manager and never touch a dedicated `neuron` connection, so the recipe puts the bundle's own middleware on `neuron.bus`: it pings the Neuron connection before each consumed message (closing it on failure) and closes it afterwards. Symfony 8.2, in development, adds `DoctrineDbalPingConnectionMiddleware` and `DoctrineDbalCloseConnectionMiddleware`, which target DBAL connections by name and replace the bundle's middleware there.

Workflow state must stay free of Doctrine entities. `PhpSerializer` serializes object graphs, the entity manager is cleared between messages, and a persisted proxy class can become unreadable after a deploy. Nodes store identifiers in the state and load entities through repositories when they need them.

### Redis

`type: redis` uses core's `RedisPersistence(\Redis $client, string $prefix = 'neuron:workflow:')`. The bundle builds the client from a DSN with `RedisAdapter::createConnection()` and refuses anything that is not a phpredis `\Redis` instance until core accepts `\Redis|\RedisCluster` and Relay (proposed, C16). Workflow state is not a cache: the Redis instance must run with `maxmemory-policy noeviction` and AOF persistence; both are instance-wide settings, so a separate database number is not enough and the instance must not be shared with cache pools that rely on eviction.

The placement of DBAL backends is an open question for the maintainer. They live in the bundle under the placement rule, but Laminas, Mezzio and other DBAL users would benefit from having them in core. Those applications can already use `DatabasePersistence` with a `Closure` over `getNativeConnection()` once C13 makes the SQL stores resolve their connection per operation.

### Multi-tenant applications

The bundle recommends central Neuron tables on the dedicated `neuron` connection, shared by every tenant. The workflow ID is then a global key, so business keys carry the tenant: two tenants that both bind `$orders->for('order-42')` would otherwise drive the same run and read each other's state. Definitions are bound with a tenant-prefixed key (`$orders->for($tenantId.'.order-42')`), server-issued thread IDs are unique anyway, and the tenant ID is also kept in the start event or the state, where nodes and tools read it, because a worker has no request to derive it from. When tools need the tenant's own database connection, the tenant context must be switched before the gateway runs: `InvocationDispatcher`, which sees every dispatch (producers, the handler's redispatches and the sweep), adds a stamp carrying the tenant that an application-provided resolver derives from the invocation's workflow ID, and an application middleware on `neuron.bus` restores it before the handler invokes the gateway (a handler receives the message, not the envelope). Per-tenant Neuron storage, with a workflow store and a projection in each tenant's database, needs more: the same middleware must also switch the Neuron connection, the transaction guard must follow it, and every tenant needs its own sweep, because a sweep reads one `neuron_runs` table. That variant is to verify against the tenancy bundles in use before the bundle documents it as supported. Retrieval is scoped per tenant as section 13 describes.

### Retention and erasure

A completed run deletes its partition, immediately for a synchronous run and after `acknowledge()` for a run driven by the gateway. Two kinds of run stay until someone acts: a run suspended on an interruption without a deadline, and a failed run parked after Messenger gave up on it (section 9). `neuron:prune` walks the projection and abandons those whose row has been untouched for longer than `neuron.workflows.<name>.prune_after`, through the definition's own `abandon()`, fenced by the projected run ID and attempt. It goes through the definition so that Agent's guard, which refuses to abandon a conversation whose last message is an unanswered tool call, still applies, and it reports the runs it refused. Until C9 it also removes terminal start rows older than `neuron.gateway.retry_window` (section 9). The application schedules the command, for example with a `RecurringMessage` in its own schedule. Conversation messages follow the application's own retention policy.

Erasing a user's data settles each of the user's threads on its handle: `acknowledge()` for a retained completion, otherwise `resetConversation()`, which abandons the run without Agent's guard and clears the history through `MessageStoreInterface::clear()`. A thread still executing under its lease is retried after the lease expires. Then the projection row is removed.

## 9. Durable execution with Messenger and Scheduler

Everything that happens outside an HTTP request (queued turns, approvals delivered later, signals from other systems, timers and crash recovery) goes through one Messenger message, one handler and the reference gateway of core improvement C14 ([neuron-core-improvements.md](neuron-core-improvements.md)). The gateway is a consumer of the engine's public API, not a scheduler inside it: it resolves a definition by name, binds it, runs one fenced request, classifies the outcome, and returns a `Disposition` (`done`, `retryAt(int)`, `discard(string)` or `fail(Throwable)`) that the bundle translates into Messenger verbs. The Laravel package translates the same dispositions into queue verbs, which is why the algorithm lives in core and is never duplicated in the two packages.

### The message and the handler

The bundle has exactly one message type for workflow invocations, `NeuronAI\Symfony\Messenger\InvokeWorkflow`, which wraps `Invocation::toArray()` and the time of its first dispatch. Definitions, handles, `PendingExecution` objects, providers and closures never travel; the message is plain JSON, so it works with Messenger's PHP serializer and with the Symfony Serializer-based JSON transport alike.

```json
{
  "kind": "resume|answer|signal|wake",
  "workflow": "support",
  "workflowId": "01J…",
  "runId": "run_01J…",
  "attempt": 0,
  "interruptId": 3,
  "payload": {"…": "…"},
  "signal": "order.approved"
}
```

`workflow` is the `#[AsWorkflow]` name. There is no `start` kind on the wire: with C9 the HTTP edge ignites the run synchronously, which persists it as `Pending` without executing it, and dispatches a `resume` of that run at attempt 0. `answer` carries a payload already validated at the edge and is fenced on the interrupt it answers (C8); `signal` carries the awaited event name; `wake` asks the gateway to evaluate a due deadline or recover a run whose lease expired.

```php
<?php

declare(strict_types=1);

namespace NeuronAI\Symfony\Messenger;

use Monolog\Attribute\WithMonologChannel;
use NeuronAI\Exceptions\DefinitionVersionException;
use NeuronAI\Gateway\DispositionKind;
use NeuronAI\Gateway\Gateway;
use NeuronAI\Gateway\Invocation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Throwable;

final class InvokeWorkflow
{
    /** @param array<string, mixed> $invocation Invocation::toArray() (proposed, C14) */
    public function __construct(
        public readonly array $invocation,
        public readonly int $firstDispatchedAt, // copied on every redispatch
    ) {
    }
}

#[AsMessageHandler(bus: 'neuron.bus')]
#[WithMonologChannel('neuron')]
final class InvokeWorkflowHandler
{
    public function __construct(
        protected Gateway $gateway, // proposed, C14
        protected InvocationDispatcher $dispatcher,
        protected LoggerInterface $logger,
    ) {
    }

    public function __invoke(InvokeWorkflow $message): void
    {
        // Proposed, C14. Node failures the gateway rethrows escape to the transport's retry strategy.
        $disposition = $this->gateway->invoke(Invocation::fromArray($message->invocation));

        match ($disposition->kind) {
            DispositionKind::Done => null,
            // A new envelope with DelayStamp::delayUntil() on the same transport, then this one
            // is acknowledged: portable across 7.4 and 8.x.
            DispositionKind::RetryAt => $this->dispatcher->redispatch($message, $disposition->retryAt),
            DispositionKind::Discard => $this->logger->info('Neuron invocation discarded: {reason}', [
                'reason' => $disposition->reason,
                'workflowId' => $message->invocation['workflowId'],
            ]),
            DispositionKind::Fail => $this->fail($message, $disposition->error),
        };
    }

    protected function fail(InvokeWorkflow $message, Throwable $error): void
    {
        // Refused before replay (C12): hand the invocation to the consumers of the recorded version.
        if ($error instanceof DefinitionVersionException && $this->dispatcher->routeToVersion($message, $error->recorded)) {
            return;
        }

        throw new UnrecoverableMessageHandlingException($error->getMessage(), 0, $error);
    }
}
```

A `Disposition` exposes its `kind` (a `DispositionKind`), `retryAt`, `reason` and `error` (proposed, C14), the same accessors the Laravel job reads. The `neuron.bus` runs no `doctrine_transaction` middleware (section 8). `InvocationDispatcher` owns every dispatch of an invocation, by producers, the handler and the sweep alike. It adds a `TransportNamesStamp` naming `neuron.gateway.transport`, or the transport of the run's definition version, and a `DelayStamp::delayUntil()` for delayed invocations. `redispatch()` copies `firstDispatchedAt` and re-applies the same transport, so bounded policies survive redispatch: a signal that keeps arriving before its wait is discarded once `neuron.gateway.signal_window` has passed since its first dispatch.

**Interim (today's core).** Until C14 ships, the bundle depends on the shared `@internal` pre-release of `neuron-core/gateway` that the Laravel package also uses. It has the surface of C14, is written once against today's core, and is never duplicated in either package; in it, `inspect()` checks, the `start` kind and attempt re-fencing stand in for C6 to C9. Until C9, the wire format has a `start` kind: the controller mints a reserved run ID (`'run_'.new Ulid()`, which matches the pattern `ExecutionRequest::start()` validates), encodes the start event with the configured `Serializer` into a base64 `input` field, records the reserved run ID as a `pending` projection row, and dispatches.

The worker runs `ExecutionRequest::start($event, runId: $reserved)` under one rule that both packages state. A start delivery ignites only while `inspect()` finds no run and the projection row still names the reserved run ID with a non-terminal status (`pending` or `executing`). A `RunInFlightException` naming the reserved run switches to `ExecutionRequest::resume(expectedRunId: $reserved)`, which recovers a failed run, converges on a completed one or meets the lease of a live one. Before `acknowledge()`, the gateway marks the row terminal and keeps it for `neuron.gateway.retry_window`, so a delivery after the acknowledgement is discarded, and a crash between the mark and the acknowledgement cannot re-ignite the run. The rule covers every delivery of the message alike, a redelivery after a crash, a redispatch or `messenger:failed:retry`: a worker killed after the write-ahead (step 3 of the algorithm below) but before the ignition was persisted leaves an `executing` row and no run, so the redelivery ignites, and nothing is lost or run twice. Because the start event travels only in the message until C9, the sweep cannot re-dispatch a lost start; it leaves a row whose reserved run does not exist alone until the retry window has passed, instead of forgetting it while the message may still be queued.

Admission at the edge is an `inspect()` pre-check, which is racy. A conflict discovered by the worker comes back as a `discard` disposition, and the bundle's handler publishes it directly to the thread's Mercure topic through `HubInterface::publish()` (an update carrying the refusal), since a refused admission opens no segment and therefore no channel; without Mercure, the client sees it on the thread endpoint.

### Producers

A queued turn authorizes the thread, ignites, records the run in the projection as `pending` with `recheck_at` = now + W plus a short dispatch grace, dispatches after the database work of the request is committed, and returns 202 with the workflow ID, the run ID and the channel (the Mercure topic). `InvocationDispatcher` writes the pending row itself, before it dispatches the first resume of a freshly ignited run, as the Laravel package's dispatcher does. The sweep reads only `neuron_runs`, so without this row a pending run whose dispatch was lost (for example, because the transport was unreachable after `ignite()`) would block the thread until an operator abandoned it; with it, the sweep re-dispatches `Invocation::resume()` at attempt 0 once `recheck_at` has passed, and a duplicate is harmless because the resume is fenced. The browser subscribes before posting, or reconciles from the thread endpoint afterwards.

```php
#[Route('/support/{id}/turns', methods: ['POST'], format: 'json')]
#[IsGranted('NEURON_THREAD', subject: 'conversation')]
public function queueTurn(
    Conversation $conversation,
    #[MapRequestPayload] ChatTurn $turn,
    SupportAgent $agent,
    InvocationDispatcher $dispatcher,
): JsonResponse {
    $run = $agent->for($conversation->threadId())                       // proposed, C2
        ->ignite(ExecutionRequest::start(new AgentStartEvent(           // proposed, C9: 409 happens here
            [new UserMessage($turn->message)],
            new AgentRunOptions(stream: true),
        )));

    $dispatcher->dispatch(Invocation::resume('support', $run)); // proposed, C14: pending row, then dispatch

    return new JsonResponse([
        'workflowId' => $run->workflowId,
        'runId' => $run->runId,
        'channel' => 'neuron/threads/'.$run->workflowId,
    ], 202);
}
```

Answers (approval decisions, deferred tool results, AG-UI and Vercel continuation envelopes) are validated eagerly on the bound handle with `submitApprovalDecisions()`, `submitToolResults()` or `submitInputs()`, so a malformed answer is a 422 before anything is queued; the controller then dispatches `Invocation::answer('support', $id, $pending->request())`, where `PendingExecution::request()` exposes the fenced request (proposed, C9). Today `PendingExecution` keeps its request protected, so the interim edge validates with the same helper, then rebuilds the payload from `inspect()` and the matching translator and records the observed run ID, attempt and interrupt ID in the invocation. Signals arrive through signed webhooks (section 10). Wakes come from two places. The bundle decorates `ProjectionStoreInterface` with a `SchedulingProjectionStore`: when the gateway saves a suspended projection whose deadline, the interrupt's `deadline()` (proposed, C7), falls within the transport's delay cap (`neuron.gateway.max_delay`: SQS caps delays at 900 seconds, while Doctrine, Redis and AMQP have no practical cap), it dispatches `Invocation::wake()` through `InvocationDispatcher` with `DelayStamp::delayUntil()`. The sweep is always the backstop. Keeping the fast path in a store decorator lets the core gateway stay free of any queue concept and keeps `Disposition::done()` payload-free, as C14 defines it. Synchronous streamed turns never touch the queue, but they call `Gateway::reconcile()` with the state they return, and the same decorator schedules their wake, so a synchronous turn that suspends on an approval with an expiry gets its timer too.

A message must not reach a worker before the data it refers to exists. Controllers dispatch after `flush()`; handlers that dispatch from inside another message use `DispatchAfterCurrentBusStamp`; an application that needs dispatch to be atomic with its own writes can use the Doctrine transport on the same database.

### The handler algorithm

`Gateway::invoke()` performs the same steps for every kind. They are listed because the order is the contract.

1. Resolve the definition by name from the `neuron.workflow` locator, bind it with `for()`, and enable completion retention.
2. For a wake, call `inspect()` first, which is read-only. A stale fence is discarded; a deadline still in the future returns `retryAt(deadline)`. This avoids the checkpoint write an idle inputless poll performs today.
3. Write the projection ahead: status `executing`, `recheck_at` = now + lease + sweep interval. If the worker dies before step 5, the sweep still finds the run.
4. Run the fenced request.
5. Reconcile from the returned state. A suspended run upserts the projection (interrupt ID, type, event name, deadline, definition version); the bundle's `SchedulingProjectionStore` turns a near deadline into a delayed wake as the row is saved. A completed run calls the completion handler, then `acknowledge($runId)`, then forgets the projection.
6. Map refusals by type (C6). On a fence refusal, inspect: if the run is the invocation's own, converge (a failed run gets a fenced inputless recovery, a suspended run is reconciled, a completed run has its retained outcome read, recorded and acknowledged, a running run under a lease becomes `retryAt(lease expiry)`); otherwise discard.

### Dispositions and refusals

| Disposition | Messenger action |
|---|---|
| `done` | return, so the message is acknowledged |
| `retryAt(t)` | redispatch the same message through `InvocationDispatcher` with `DelayStamp::delayUntil(t)` on the same transport, then return |
| `discard(reason)` | return and log the reason on the `neuron` channel |
| `fail(e)` | throw `UnrecoverableMessageHandlingException` with `e` as previous, so the message goes to the failure transport without retries (a `DefinitionVersionException` is first routed to its version's transport); the failure listener then parks the run's projection row |

Exceptions the gateway does not handle (the database is unreachable before admission, the transport fails during redispatch), and node failures the gateway rethrows after the run is durably failed, escape the handler and follow the transport's retry strategy, then the failure transport. That is safe because either nothing was committed or the fenced state is intact.

When Messenger gives up, the projection must say so, or the sweep would keep recovering the run. The write-ahead row of a failed run still carries a finite `recheck_at`, so after its message reached `neuron_failed` the sweep would wake the run every L + W and re-execute a deterministic failure (`ToolRunsExceededException`, exhausted structured output) indefinitely, outside the retry strategy's bound and paying for inference each time. The bundle's `ParkFailedRunListener` listens to `WorkerMessageFailedEvent` for `InvokeWorkflow` messages, below the priority of Messenger's own retry listener so that `willRetry()` is final, and applies C14's terminal-failure rule when `willRetry()` is false, which covers both an exhausted retry strategy and `UnrecoverableMessageHandlingException`. It inspects the run through the bound handle and parks the row (status `failed`, `recheck_at` null) only when the run is `Failed`. A suspended run keeps its row and its deadline, since an answer refused as invalid input leaves the run waiting and its expiry must still fire; a row whose run no longer exists is forgotten; and a `DefinitionVersionException` leaves the row untouched, so the run is picked up again once a consumer of its version runs. A parked run stays until an operator acts: `neuron:recover`, or a successful `messenger:failed:retry`, un-parks it, because the gateway writes the projection ahead again, and `neuron:prune` eventually abandons it (section 8).

The table below is shared with the Laravel document and with the HTTP edge (section 10); the last column says how the bundle detects the case against today's core, where C6's typed vocabulary does not exist yet.

| Refusal (C6) | Messenger action | HTTP status | Detected today by |
|---|---|---|---|
| `RunInFlightException`, running with a fresh lease | `retryAt(retryAt())` | 409 + `Retry-After` | `RunInFlightException` with status `Running` and `leaseExpiresAt` in the future; for continuations, the pre-release's `inspect()` showing a `Running` run, retried after one lease because snapshots carry no lease expiry until C7 |
| `RunInFlightException`, pending run (C9) | none: workers never start runs after C9 | save the missing `pending` projection row, then 409 | not available (no `pending` status before C9) |
| `RunInFlightException`, suspended on another run | discard | 409 with the interrupt JSON | `RunInFlightException` with status `Suspended` and a `runId` other than the reserved one |
| `RunInFlightException`, completed and not acknowledged | wake that run, then `retryAt(now + 1)` for this invocation | 409 + `Retry-After: 1` | `RunInFlightException` with status `Completed` |
| `StaleRequestException` family | inspect and converge, otherwise discard | 409 | `StaleWorkflowRunException` with a non-null `actualRunId`; a run ID, attempt or interrupt ID that differs from the pre-release's `inspect()` |
| `NoRunInFlightException` | discard and forget the projection | 404 | `StaleWorkflowRunException` whose `actualRunId` is null; an `inspect()` that returns null |
| `InvalidInputException` / `InputTranslationException`, answer | fail as unrecoverable | 422 | `InputTranslationException` from the edge's eager validation |
| `InvalidInputException`, signal | bounded `retryAt`, because early arrival is legitimate | 202 at the webhook | an `inspect()` whose current interrupt is not waiting for that event name |
| `ConcurrentUpdateException` | `retryAt(now + jitter)` | 409 + `Retry-After: 1` | covered by the bounded backoff for any other `WorkflowException` |
| `DefinitionVersionException` | never executed here: redispatched to the transport that serves the recorded version, unrecoverable only when none does (the row is not parked) | 409 | not available (no versioning today) |
| `RetryableHttpException` (provider 429 or 5xx) | the run is failed and durable; `retryAt(Retry-After)`, and recovery reuses committed steps | 503 in synchronous mode | `HttpException` whose response status is 408, 425, 429 or 5xx |
| `UnrecoverableException` | fail as unrecoverable | 500 or 422 | `ToolRunsExceededException`; the `AgentException` or `DeserializerException` that `StructuredOutputNode` rethrows once its retries are exhausted |
| `UnboundWorkflowException` | fail (a bug) | 500 | not available (an unbound instance binds a generated ID today) |
| any other `Throwable` from a node | the run is failed and durable; the gateway rethrows it, so the transport's `retry_strategy` (`max_retries`, `delay`, `multiplier`) supplies the backoff and the bound, then the failure transport; the next delivery recovers the run through its fence | 500 | any other exception |

The gateway pre-release never matches exception messages. It classifies `RunInFlightException` by its `status` and `runId` before any other rule: its own reserved run leads to `resume(expectedRunId:)`, while a `Failed` or expired-lease generation of another run is discarded and the edge answers 409, because a reserved start never sweeps a dead generation and retrying it immediately would loop. It inspects before every continuation and compares run ID, attempt and interrupt ID itself, re-fences an answer when the same interrupt is still current, and gives any other `WorkflowException` a bounded backoff before failing. C6 turns this into a type switch.

### The run projection and the Scheduler sweep

The gateway keeps a small projection of every run it drives in `neuron_runs`: `workflow_id` (primary key), `workflow`, `run_id`, `attempt`, `status` (`pending`, `executing`, `suspended` or `failed`), `interrupt_id`, `interrupt_type`, `event_name`, `deadline_at` (indexed), `recheck_at` (indexed), `definition_version` and `updated_at`. `DoctrineDbalProjectionStore` implements it and the schema listener adds the table to migration diffs. The projection is an expiring hint, never the truth: it is derived only from returned state or `inspect()`, never from listeners, because `SegmentEventDispatcher::report()` swallows listener failures (`src/Workflow/Executor/SegmentEventDispatcher.php:42`) and a timer scheduled from a `WorkflowInterrupted` listener can be lost silently. The due query selects suspended runs whose `deadline_at` has passed and pending, executing or failed runs whose `recheck_at` has passed. `Gateway::due()` inspects each due run and yields only fenced invocations for runs that still need one: a wake for a due deadline or an expired execution, and `Invocation::resume()` at attempt 0 for a pending run whose dispatch was lost. It forgets rows whose run is gone.

```php
#[AsSchedule('neuron')]
final class NeuronSchedule implements ScheduleProviderInterface
{
    public function __construct(
        protected LockFactory $locks,
        protected string $frequency, // neuron.gateway.sweep.every
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every($this->frequency, new SweepDueRuns()))
            ->lock($this->locks->createLock('neuron.sweep'));
    }
}

#[AsMessageHandler]
final class SweepDueRunsHandler
{
    public function __construct(
        protected Gateway $gateway, // proposed, C14
        protected InvocationDispatcher $dispatcher,
        protected int $batch, // neuron.gateway.sweep.batch
    ) {
    }

    public function __invoke(SweepDueRuns $sweep): void
    {
        foreach ($this->gateway->due($this->batch) as $invocation) {
            $this->dispatcher->dispatch($invocation);
        }
    }
}
```

Workers consume both transports, `bin/console messenger:consume neuron scheduler_neuron`, and the lock keeps one sweep per interval however many workers run, provided the `LockFactory` uses a store shared by every host (DBAL, Redis or another remote store); the default `flock` store only coordinates workers on one machine, and duplicate sweeps are then harmless but wasteful. `Gateway::due()` inspects before it yields, and the handler inspects again before it wakes anything, so early or duplicate wakes are no-ops. `bin/console neuron:wake` runs one sweep for applications that prefer cron to the Scheduler. Timer precision is bounded by the sweep interval when the transport cannot delay far enough.

**Interim (today's core).** Deadlines come from an `instanceof` ladder over the returned interrupt, `SleepUntilRequest::getWakeAt()` and `WaitForEventRequest::getExpiresAt()`, the same ladder `WorkflowEngine::dueInput()` uses internally; C7 replaces it with `InterruptRequest::deadline()`. Snapshots carry no lease expiry, so write-ahead rows use now + lease + sweep interval, and every lease refusal moves the row forward by one lease.

### Lease and redelivery alignment

A lease bounds silence, not work: the engine renews it at step commits, and a run whose lease expired is treated as dead. Messenger's redelivery must therefore never race a live lease, and a live step must never outlive its lease.

| Symbol | Meaning | Symfony setting |
|---|---|---|
| L | execution lease | `neuron.workflows.<name>.lease` or the `leaseTimeout()` hook (Agent: 600 s) |
| H | HTTP maximum duration of one provider call | `max_duration` on the client used by providers |
| S | longest silent node | until C11, the whole tool loop of one agent step |
| T | handler execution time | no per-message timeout in Messenger |
| R | redelivery of an unacknowledged message | `redeliver_timeout` on the Doctrine and Redis transports; the visibility timeout on SQS |
| W | sweep interval | `neuron.gateway.sweep.every` |

The rules are H < L and S < L, so no single step outlives its lease. T ≤ L, Laravel's rule for killed jobs, has no Messenger equivalent, because Messenger never kills a running handler; the per-step rules above carry the guarantee instead. R > L plus a margin: by default the Doctrine and Redis transports redeliver a message whose delivery is older than R even while a consumer is still working on it, and a redelivery that arrives before the lease can expire is only refused and delayed, so R should also exceed the longest expected handler run to avoid pointless redeliveries. Workers that run `messenger:consume --keepalive` (Symfony 7.3+, requires `ext-pcntl`) refresh the delivery on Doctrine, Redis and SQS while the handler works, which removes those redeliveries; the recipe's worker command uses it, and R > L plus a margin still bounds how quickly a crashed worker's message is recovered. Finally, the projection's stale-after interval equals L + W. `ValidateDefinitionsPass` checks the values that are static at compile time, and `neuron:setup --check` reports the ones resolved from environment variables, such as the transport DSN. A plain `Workflow` driven by the gateway must declare a lease: without one, a redelivered wake cannot tell a crashed worker from a live one, so the bundle refuses gateway invocations for lease-less definitions unless `neuron.workflows.<name>.lease` sets one.

### Completion, failure transport and deploys

The gateway always retains completions. The completion handler runs before `acknowledge()` and is therefore at-least-once: the bundle's default `CompletionHandlerInterface` implementation dispatches a `WorkflowCompleted` Symfony event with the definition name, workflow ID, run ID and final state, and listeners must be idempotent by run ID. A crash between the run and the acknowledgement is recovered by the sweep. Synchronous HTTP runs do not retain completions, so the workflow store is cleaned as soon as they finish.

The recipe configures a `neuron_failed` failure transport for the `neuron` transport. `messenger:failed:retry` is always safe (with C9; before it, thanks to the start rule above), because every retried invocation is fenced again, and `neuron:inspect` shows what the run is doing before an operator retries anything.

Definitions can declare a version (`#[AsWorkflow(version: ...)]` or a `version()` hook), which core stamps into the run and checks before replay, throwing `DefinitionVersionException` on a mismatch (proposed, C12). Routing old runs to old workers is platform policy, and the bundle's policy is configuration: `neuron.workflows.<name>.versions` maps a recorded version to the transport whose consumers still run it (`{ '1': neuron_orders_v1 }`). `InvocationDispatcher` reads the run's `definition_version` from the projection and names the mapped transport in its `TransportNamesStamp`. When the projection was stale and a message still reaches a consumer of the wrong version, the engine refuses before replaying anything; the handler does not execute the invocation, redispatches it through `InvocationDispatcher` to the recorded version's transport, and throws `UnrecoverableMessageHandlingException` only when no transport serves that version, without parking the row. The recipe for an incompatible deploy is to declare a transport for the previous version and map it under `versions`, keep one `messenger:consume neuron_orders_v1` worker running the previous release, and stop it once `neuron:runs --definition-version=1` is empty. `messenger:stop-workers` lets each consumer finish the message in hand, so a rolling deploy interrupts nothing; a consumer killed earlier is recovered through its lease after at most L.

The version changes only when the graph changes incompatibly, because persisted steps replay by step key (the node class plus an index) and persisted records unserialize by class name. Renaming or reordering nodes, moving or renaming event, state or interrupt classes, and changing the shape of what the state holds are incompatible. Changing instructions, prompts, providers and the code inside a node that keeps its input and output events is compatible, because committed steps replay their memoized results and new steps use the new code; removing a tool that a suspended approval still names is not. Until C12, there is no version protection: suspended runs must be drained or abandoned before an incompatible deploy, and moved Event, State and InterruptRequest classes keep `class_alias` shims.

### Business workflows end to end

Chat turns are the most visible use of the runtime, but it exists for business processes. An order that waits up to three days for its payment is a plain Workflow with a lease (`neuron.workflows.orders.lease`, section 5):

```php
#[AsWorkflow(name: 'orders', version: '2')] // proposed, C12 and C19
class OrderPaymentWorkflow extends Workflow
{
    protected function nodes(): array
    {
        return [new AwaitPaymentNode(), new FulfilOrderNode(), new CancelOrderNode()];
    }
}

final class OrderPlaced implements Event
{
    public function __construct(public readonly int $orderId)
    {
    }
}

class AwaitPaymentNode extends Node
{
    public function __invoke(OrderPlaced $event, WorkflowState $state): PaymentCaptured|PaymentExpired
    {
        // Computed once and memoized: a resume or a recovery must not move the deadline.
        $deadline = new DateTimeImmutable($this->memoize(
            'deadline',
            fn (): string => (new DateTimeImmutable('+3 days'))->format(DATE_ATOM),
        ));

        $payment = $this->awaitEvent('payment.captured', expiresAt: $deadline); // null once the deadline passed

        return $payment === null
            ? new PaymentExpired($event->orderId)
            : new PaymentCaptured($event->orderId, (int) $payment['amount']);
    }
}
```

Three pieces connect it to the application. The run starts from a domain event, not from a request that waits. A `postFlush` listener that collected new `Order` entities, or a handler of the application's own `OrderCreated` message, binds the definition with `$orders->for('order-'.$order->getId())` (proposed, C2), calls `ignite(ExecutionRequest::start(new OrderPlaced($order->getId())))` (proposed, C9) and dispatches `Invocation::resume('orders', $run)` through `InvocationDispatcher`, adding `DispatchAfterCurrentBusStamp` when an outer transaction is still open, so the invocation leaves only after the commit. A second start for the same order meets the existing run as a `RunInFlightException` and is ignored, which keeps the listener idempotent. Because `ignite()` commits on the Neuron connection, an order written inside an outer transaction that may still roll back is better served by the message handler, dispatched after that commit, so a run never outlives a rolled-back order.

The payment provider's webhook arrives through the Webhook component. A `RequestParser` implements the vendor's own signature scheme (the bundle's HMAC only covers senders the application controls), rejects stale or replayed deliveries and turns the body into a `RemoteEvent`; an `#[AsRemoteEventConsumer('payments')]` consumer maps the vendor event to `Invocation::signal('orders', 'order-'.$orderId, 'payment.captured', ['amount' => $amount])` and dispatches it after the definition check of section 10. The awaiting node then resumes in a worker, and an unpaid order resumes on its own when the deadline passes, through the delayed wake or the sweep, with `awaitEvent()` returning null.

The outcome arrives in a `WorkflowCompleted` listener (section 9, completion), which reads the final state of the `orders` definition and updates the order. It runs at least once, so it is idempotent by run ID.

## 10. The HTTP edge

### Endpoints and building blocks

The bundle ships optional routes (imported from `@NeuronBundle/config/routes.php` under a prefix the application chooses) and, more importantly, the building blocks to write equivalent controllers by hand. Routes carry the `#[AsWorkflow]` name, so one set of controllers serves every registered definition through the `neuron.workflow` locator. The ownership record also stores the definition name (`NeuronThreadInterface::workflow()`), and the controllers answer 404 when the route's `{workflow}` differs from it, before `for()` is called. Without that check a thread created for one definition could be driven through another, which would replay the first definition's persisted steps (step keys are the node class plus an index) under the second definition's graph, instructions and tools.

| Endpoint | Purpose | Building blocks |
|---|---|---|
| `POST /{workflow}/threads` | create a thread with a server-issued ULID and an ownership record | the application's `Conversation` entity implementing `NeuronThreadInterface`, created through its `NeuronThreadRepositoryInterface` |
| `POST /{workflow}/threads/{id}/turns` | a new turn, streamed synchronously or queued with 202, as `neuron.workflows.<name>.mode` decides; an optional `Idempotency-Key` header makes client retries safe | `NeuronStreamResponse`, `InvocationDispatcher`, `ChatTurn` DTO |
| `GET /{workflow}/threads/{id}` | status, `pendingApprovals()`, the interrupt JSON, a history page from `MessageStoreInterface::loadAll()`, and `AGUIAdapter::hydrate()` for AG-UI reloads | `$definition->for($id)->inspect()` (proposed, C2; today `inspect()` on the bound prototype), the message store |
| `POST /{workflow}/threads/{id}/approvals` | approval decisions | `submitApprovalDecisions()`, `ApprovalDecisions` DTO |
| `POST /{workflow}/threads/{id}/tool-results` | results of deferred frontend tools | `submitToolResults()`, `ToolResults` DTO |
| `POST /{workflow}/agui`, `POST /{workflow}/vercel` | single-endpoint protocols deciding between a new turn and a continuation | protocol value resolvers, `AGUIInputTranslator`, `VercelAIInputTranslator` |
| `POST /webhook/neuron` | signed signals from other systems | the Webhook component, `Invocation::signal()` (proposed, C14) |

A client that retries a new turn after a timeout would otherwise start a duplicate turn with the same message once the first one completed, because a completed thread admits the next turn. The turn endpoint therefore honours an optional `Idempotency-Key` header, scoped to the user and the thread. The controller claims the key atomically before `ignite()`, with a non-blocking `Lock` on a store shared by every host (not auto-released, with the key's retention as its TTL), and records `{workflowId, runId}` for the key in a dedicated cache pool once admission succeeds. A repeat receives the stored 202 body for a queued turn, or a 409 carrying the run ID for a streamed turn, whose output cannot be replayed; a repeat that arrives while the first request is still being admitted receives a 409 with `Retry-After: 1`.

### Thread identity and authorization

The thread ID is the workflow ID, and therefore a storage key: it selects which conversation is read, written and resumed (`src/Agent/AGENTS.md` states it is untrusted input). The bundle never accepts a client-chosen ID as a storage key, including the chat ID a Vercel `useChat` client generates or an AG-UI `threadId` the application did not issue. Threads are created server-side with a ULID and an ownership record, and every endpoint resolves that record and authorizes it before `for()` is called. The application implements two small bundle contracts on its own entity and repository; the optional routes use the repository to create and find threads, and the voter reads the owner:

```php
<?php

declare(strict_types=1);

namespace NeuronAI\Symfony\Security;

use Symfony\Component\Security\Core\User\UserInterface;

interface NeuronThreadInterface
{
    /** The server-issued workflow ID. */
    public function threadId(): string;

    /** The #[AsWorkflow] name of the definition this thread belongs to. */
    public function workflow(): string;

    /** Compared with the token's user identifier by ThreadVoter. */
    public function ownerIdentifier(): string;
}

interface NeuronThreadRepositoryInterface
{
    public function create(string $workflow, UserInterface $owner): NeuronThreadInterface;

    public function find(string $threadId): ?NeuronThreadInterface;
}
```

The idiomatic pair is `#[MapEntity]` (or plain entity argument resolution) and `#[IsGranted('NEURON_THREAD', subject: 'conversation')]`, backed by the bundle's voter:

```php
<?php

declare(strict_types=1);

namespace NeuronAI\Symfony\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, NeuronThreadInterface> */
final class ThreadVoter extends Voter
{
    public const ATTRIBUTE = 'NEURON_THREAD';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && $subject instanceof NeuronThreadInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUserIdentifier();

        return $user !== '' && $subject->ownerIdentifier() === $user;
    }
}
```

Applications with richer rules (teams, support staff reading customer threads) register their own voter for the same attribute. Queued continuations capture the actor at submission, in the invocation payload or the start event; nothing that runs in a worker reads the security token.

### Synchronous streaming: NeuronStreamResponse

A synchronous turn is still fully durable; the edge adds two guarantees, which core improvements C1 and C15 ([neuron-core-improvements.md](neuron-core-improvements.md)) make cheap. Admission errors are reported as ordinary HTTP errors before any byte is sent, and a client disconnect never leaves the run holding its lease.

```php
#[Route('/support/{id}/stream', methods: ['POST'])]
#[IsGranted('NEURON_THREAD', subject: 'conversation')]
public function streamTurn(
    Conversation $conversation,
    #[MapRequestPayload] ChatTurn $turn,
    SupportAgent $agent,
    Gateway $gateway,
): NeuronStreamResponse {
    $adapter = new VercelAIAdapter();
    $events = $agent->for($conversation->threadId())                       // proposed, C2
        ->setStreamAdapter(fn (ExecutionContext $context) => $adapter)     // context-aware factory, C2
        ->stream(new UserMessage($turn->message));

    // Primes the generator: a RunInFlightException becomes a 409 here, before any header.
    // Gateway::reconcile(): proposed, C14 (interim: the shared gateway pre-release).
    return new NeuronStreamResponse($events, $adapter, static fn (WorkflowState $state) => $gateway->reconcile('support', $state));
}
```

`NeuronStreamResponse` extends `StreamedResponse`. Its constructor opens a framework-neutral `SSEStream` over the generator (proposed, C15), which primes it: the generator runs to its first yielded event, after admission and graph construction. For AG-UI that is the `RUN_STARTED` frame. For Vercel and the native adapter, which have no start frame, it is the first provider chunk, so the controller waits for the first token. Either way a refusal propagates as an exception that the `kernel.exception` listener renders. Headers come from the adapter through `StreamAdapterInterface::headers()` (C15), merged with the SSE defaults; AG-UI's hop-by-hop `Connection` header is dropped, since it is invalid on the HTTP/2 and HTTP/3 connections FrankenPHP and Caddy serve. The streaming callback echoes and flushes `SSEStream::frames()`, which sets `ignore_user_abort(true)` for the lifetime of the stream, restores the previous value afterwards (worker runtimes reuse the process), and after `connection_aborted()` stops emitting but keeps consuming the generator, so the turn completes and the answer is committed to history. When the generator returns, the callback hands the final state to the settlement closure, typically `Gateway::reconcile()`, so a suspension with a deadline gets its timer. A failure after the first byte has already produced the adapter's error frame, and the run was committed as failed before that frame; the callback logs the rethrown exception on the `neuron` channel and does not rethrow it, because the headers are gone.

The session needs care. The kernel's session listener saves the session on `kernel.response`, before the headers go out, but any code that touches the session while the body streams (a tool reading a flash bag, a Twig fragment, a lazily started session) would try to start it again after the headers were sent and fail with "Failed to start the session because headers have already been sent". The Symfony AI demo works around the same problem by swapping the request's session for one backed by `MockArraySessionStorage`. The bundle does the equivalent in a `kernel.response` listener registered below the session listener's priority: for a `NeuronStreamResponse`, it replaces the request's session with a detached in-memory one after the real session has been saved and closed.

`EventStreamResponse` and `ServerEvent` (HttpFoundation 7.3+) are not the default. `EventStreamResponse` stops iterating as soon as `connection_aborted()` is true, which destroys the Neuron generator in the middle of a step, and `ServerEvent` expects payload strings rather than Neuron's framed output. Once C1 lands, a destroyed generator settles its segment (a fenced, best-effort `fail()` releases the lease, the next turn supersedes it, and `run()` recovers it with its committed steps), and `SSEEncoder::payloads()` (C15) yields JSON payloads with the same throw-back behaviour. The bundle then offers an `EventStreamResponse` variant for applications that accept that an aborted turn ends failed rather than finished; draining stays the default, because it preserves the user's answer.

**Interim (today's core).** There is no `SSEStream`, no `headers()` on the adapter interface and no settlement of abandoned segments. `NeuronStreamResponse` primes with `$events->current()` (safe, because `SSEEncoder::encode()` walks the generator with `valid()`, `current()` and `next()` and never rewinds it), frames with `SSEEncoder::encode()`, reads headers from the concrete adapter's `getHeaders()` (`AGUIAdapter` and `VercelAIAdapter` have it; `AgentChunkAdapter` gets plain SSE headers) and drops `Connection`, and implements the drain loop itself. A generator abandoned without draining (an exception in the streaming callback, a loop that stops iterating) leaves an Agent thread refused for up to its 600-second lease, because `Segment::run()` only reports `WorkflowEnd` in its `finally` block and settles nothing (`src/Workflow/Executor/Segment.php:98`). A request killed outright (`max_execution_time`, `request_terminate_timeout`, an out-of-memory error, `SIGKILL`) skips generator `finally` blocks, so there the lease stays the only release even after C1. The bundle therefore steers long turns to the queued mode. Once the lease expires, the next turn supersedes the dead run on its own; `neuron:recover` instead finishes the aborted turn from its committed steps, so its answer reaches history.

### Protocols, approvals and tool results

The AG-UI and Vercel AI SDK protocols post everything to one endpoint, and the server must decide whether a request is a new turn or a continuation of a suspended run. Core's protocol request objects answer that question once (proposed, C15): `AGUIRequest::fromPayload()` and `VercelAIRequest::fromPayload()` return the thread ID, whether the request is a new turn or a continuation, the `UserMessage` with its attachments or the continuation payload, and the frontend tools the client declared. The bundle's argument value resolvers build them from the request body, so a protocol controller is a few lines: load and authorize the conversation named by the request's thread ID (with `denyAccessUnlessGranted()`, since the subject comes from the body), bind the definition, attach the frontend tools to the handle, then either stream the new turn or call `submitInputs($payload, new AGUIInputTranslator())` and stream `$pending->events()`. Until C15, the resolvers build a bundle DTO with the same fields from today's translators (`AGUIInputTranslator::tools()` already builds the frontend tool catalog) and apply the rule once: a request is a continuation when it carries continuation parts and `inspect()` shows a current interrupt.

Native approvals and deferred tool results use `#[MapRequestPayload]` DTOs validated by the Validator component. The controller calls `submitApprovalDecisions($input->decisions)` or `submitToolResults($input->results)` on the bound handle, which translates and validates eagerly, so a bad answer is a 422 before anything runs or is queued. It then either streams `$pending->events()` through `NeuronStreamResponse` or dispatches the answer invocation and returns 202. Reloads use the thread endpoint: `pendingApprovals()` rebuilds the approval UI and `AGUIAdapter::hydrate()` rebuilds the transcript from `MessageStoreInterface::loadAll()`. Interrupts are serialized with `jsonSerialize()` explicitly, so the documented shape does not depend on the application's normalizer chain: FrameworkBundle's `JsonSerializableNormalizer` honours it, but a custom normalizer registered ahead of it would not.

An approval can expire. With C24, an Agent declares `setApprovalTimeout(new DateInterval('PT24H'))` (proposed, C24), and an elapsed approval rejects every pending call with the reason "Approval expired". The deadline is an ordinary interrupt deadline, so `SchedulingProjectionStore` and the sweep deliver the expiry as a fenced wake with no controller logic, and the run continues with the rejections recorded. Today `ToolNode::buildApprovalRequest()` passes no expiry to `ApprovalRequest`, and a timed-out resume yields no decisions, so the node re-suspends with a new request. The bundle's documented interim is a `ToolNode` subclass installed through `Agent::nodes()`. It overrides `buildApprovalRequest()` to pass a deadline memoized once per approval, because the method runs again for every partial-decision round and must not extend the deadline, and `resolveToolApprovals()` to return `['reject', 'Approval expired']` for every pending call when the node resumes on a timeout, so the expiry is recorded durably and replays identically.

Structured-output endpoints use `run(ExecutionRequest::start(new AgentStartEvent($messages, new AgentRunOptions(outputClass: Invoice::class))))` and branch on the returned state's status, because `Agent::structured()` returns the `structured_output` state value, which is null when the run suspends, until C24 makes it throw a typed exception carrying the interrupt.

Signals from other systems arrive through the Webhook component: a request parser verifies an HMAC signature keyed by a dedicated secret and turns the body into a remote event, and a `#[AsRemoteEventConsumer('neuron')]` consumer dispatches `Invocation::signal()` (proposed, C14) through `InvocationDispatcher`. A plain controller on a signed route is an acceptable alternative for applications that do not use the Webhook component. Either way, signal endpoints are the only unauthenticated Neuron endpoints, and they are safe only because the signature, computed over a timestamp and a delivery ID as well as the body, is verified: the request parser rejects stale timestamps and deduplicates delivery IDs, so a captured request cannot be replayed into a later wait for the same signal. Before verifying anything, the parser also consumes a token from a `neuron_signals` limiter of the RateLimiter component, keyed by the sender's IP, and rejects the request with a 429 when it is exhausted. Before dispatching, the consumer reads the projection row of the workflow ID through the projection store (C14) and discards the signal, or the plain controller answers 404, when there is no row or the row's `workflow` differs from the definition the signal names. That is the same cross-definition check the thread routes perform: without it a signal could drive another definition's run and replay its steps under the wrong graph.

Signals answer the current interrupt only. The engine buffers nothing for a wait that has not opened yet or that is deferred behind another interrupt (`src/Workflow/AGENTS.md`), and `neuron.gateway.signal_window` only covers a signal that races the node opening its wait. A webhook that arrives while the run is still waiting on an approval, for longer than the window, is discarded. Events that must not be lost therefore go into an application inbox, a table the webhook consumer writes, keyed by workflow ID and event name, and the node reads the inbox inside `memoize()` before it calls `awaitEvent()`.

### Exception mapping

A `kernel.exception` listener renders the refusals of the table in section 9 as `application/problem+json`. It applies only before streaming begins, which is exactly what priming guarantees for admission errors.

```php
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ProblemDetailsListener
{
    public function __construct(protected ClockInterface $clock)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();

        [$status, $headers] = match (true) { // C6 types
            $error instanceof RunInFlightException && $error->retryAt() !== null
                => [409, ['Retry-After' => (string) max(1, $error->retryAt() - $this->clock->now()->getTimestamp())]],
            $error instanceof RunInFlightException && $error->status === WorkflowStatus::Completed,
            $error instanceof ConcurrentUpdateException => [409, ['Retry-After' => '1']],
            $error instanceof RunInFlightException,
            $error instanceof StaleRequestException,
            $error instanceof DefinitionVersionException => [409, []],
            $error instanceof NoRunInFlightException => [404, []],
            $error instanceof InvalidInputException,
            $error instanceof InputTranslationException => [422, []],
            $error instanceof RetryableHttpException
                => [503, ['Retry-After' => (string) max(1, $error->retryAt() - $this->clock->now()->getTimestamp())]],
            default => [null, []],
        };

        if ($status === null) {
            return;
        }

        $problem = ['type' => 'about:blank', 'title' => Response::$statusTexts[$status], 'status' => $status];
        if ($error instanceof InvalidInputException || $error instanceof InputTranslationException) {
            $problem['detail'] = $error->getMessage(); // describes the submitted payload
        }
        if ($error instanceof RunInFlightException) {
            $problem['run'] = ['status' => $error->status->value, 'retryAt' => $error->retryAt()];
            if ($error->status === WorkflowStatus::Suspended && $error->interrupt !== null) {
                $problem['interrupt'] = $error->interrupt->jsonSerialize();
            }
        }

        $event->setResponse(new JsonResponse($problem, $status, $headers + ['Content-Type' => 'application/problem+json']));
    }
}
```

Exception messages never reach the wire. They carry run identifiers, operator instructions, provider URLs and response bodies, which core's own `AgentChunkAdapter` keeps off the wire for the same reason, so the title is the HTTP status phrase, as RFC 9457 expects for `about:blank`. Only refusals whose text describes the submitted payload add a `detail`, a `RunInFlightException` contributes structured fields (and the interrupt when the run is suspended), and `HttpException` text is never included. `UnboundWorkflowException` and other bugs keep the default 500. Until C6, the listener recognizes the same cases as the handler, by class and fields where core has them (`RunInFlightException`, `StaleWorkflowRunException`, `InputTranslationException`, `HttpException`) and by the edge's own `inspect()` for the rest, never by message text.

## 11. Push with Mercure

Queued turns need a push transport, and Mercure is Symfony's natural one: `symfony/mercure-bundle` configures hubs, and FrankenPHP ships a built-in hub. Core improvement C16 ([neuron-core-improvements.md](neuron-core-improvements.md)) adds `MercureChannel extends AbstractChannel` to core. It publishes to a hub over Neuron's `HttpClientInterface` with a `Closure(): string` JWT provider and has no Symfony dependency, so any framework running FrankenPHP can use it. `AbstractChannel` already owns the envelope, sequencing and fragmentation; the Mercure transport only supplies delivery.

The bundle's channel factory builds one channel per segment from `ExecutionContext::workflowId`, with the topic `neuron/threads/<workflowId>` (the prefix is configurable), private updates, the hub URL from `RemoteHubInterface::getUrl()` and the publisher JWT from that hub's token provider (the concrete `Hub::getProvider()->getJwt()`). A hub that is not a `RemoteHubInterface`, such as FrankenPHP's `FrankenPhpHub`, which publishes in-process through `mercure_publish()`, has no URL or publisher token; for it the bundle keeps delivering through `HubInterface::publish()` with the bundle's own channel described below. A definition opts in either in its `channel()` hook, which receives the context after C2, or through configuration (`neuron.workflows.<name>.channel: mercure`), which the bundle turns into a `setChannel()` factory call on that service; explicit configuration is an explicit setter by design. The channel needs a stream adapter to carry content, since without one it receives only the segment lifecycle. With `neuron.streaming.transport: fake`, as in tests, the factory returns one shared `FakeChannel` instead (section 16). The channel publishes one envelope per update (`batch_size: 1`, `AbstractChannel`'s default). The default batch encoding concatenates envelopes into one update, which a browser consumer receiving one envelope per SSE message cannot split. Larger batches, which would save hub POSTs, need a batch encoding the browser glue unpacks; C16's `MercureChannel` should define one before the bundle accepts `batch_size` above 1.

Subscriptions are authorized by the same voter. The create-thread and get-thread endpoints (or a dedicated subscribe endpoint) run the `NEURON_THREAD` check and issue a subscriber JWT for exactly that topic, so the client can subscribe before posting; the 202 response may refresh it. The JWT travels in a cookie set with mercure-bundle's `Authorization::setCookie($request, [$topic])` for same-origin hubs, or in the response body for cross-origin ones. In the browser, an `EventSource` on the hub feeds `createChannelConsumer()` from `@neuron-core/streaming`, which orders envelopes, joins fragments, drops duplicates and reports gaps. Clients reload the thread endpoint when the consumer reports a gap, and expect a new stream ID for every segment, including a recovered one. Channel delivery failures are reported as `ChannelError` events and never fail the run; durable state is always the source of truth for recovery.

A Turbo Streams integration for Hotwire applications is possible later: a channel variant that publishes rendered `<turbo-stream>` fragments instead of Neuron envelopes. It is out of scope for the first release.

**Interim (today's core).** The bundle ships its own channel, `NeuronAI\Symfony\Mercure\MercureHubChannel extends AbstractChannel`, whose `deliver()` calls `HubInterface::publish(new Update($topic, $batch, private: true))`, so it works with every hub kind, FrankenPHP's built-in hub included. Because today's `setChannel()` factories receive no context (`Closure(): ?StreamingChannelInterface`), the topic must come from the bound instance: the definition's `channel()` hook asks the bundle's channel factory for `$this->getWorkflowId()`, which is safe because interim definitions are bound before they execute. The `neuron.workflows.<name>.channel` setting therefore takes effect only with C2, since a factory registered in the container cannot learn the address before then; a controller may still set a factory itself for a synchronous turn it runs.

## 12. Tools and MCP

### Catalog versus grant

Every `ToolInterface` and `ToolkitInterface` service is autoconfigured with the `neuron.tool` tag, and with the `#[AsTool(name, description)]` attribute of core improvement C18 ([neuron-core-improvements.md](neuron-core-improvements.md)) (in `NeuronAI\Attributes`, not to be confused with Symfony AI's attribute of the same short name) the tag carries the tool's name without instantiating it. The tagged services form a catalog, a locator keyed by tool name. The catalog is used for compile-time validation (duplicate names, `ToolValidator::validateClass()` checking that declared properties match the `__invoke()` signature, both proposed in C18), for exposure through `symfony/mcp-bundle`, and as the source from which an agent's explicitly configured `ToolSearchMiddleware` pool is drawn. The catalog itself is never a grant, but a search pool is: every tool the search finds joins the segment's registry and becomes callable, so the pool is a named subset chosen per agent and never the whole catalog. An agent lists the tools it may use explicitly, through its constructor and its `tools()` hook, so adding a tool to the codebase never silently hands it to every agent: least privilege is the default.

Tools own their constructors (`Tool` declares none), so they autowire like any service and receive repositories, HTTP clients and `#[Autowire(env: 'STRIPE_SECRET')]` scalars. With C18, attach-time configuration returns configured copies (`withApproval()`, `withMaxRuns()`, `withName()`, `withVisibility()`, and `only()`, `exclude()` and `with()` on toolkits and connectors), duplicate tool names throw when a segment's registry is built, and toolkits respect the visibility of their inner tools. Tools then become shared prototypes: `ToolNode` already clones a tool for every call and binds the call's inputs on the clone (`src/Agent/Nodes/ToolNode.php:221`). Approval policies and error handlers get interfaces next to the closures (`ApprovalPolicyInterface`, `ToolErrorHandlerInterface`), so policies are typed contracts that the configuration tree can validate at compile time (today's `callable` parameters accept an invokable service, but nothing checks its signature).

**Interim (today's core).** Attach-time configuration mutates the instance: `requireApproval()`, `withApprovalPolicy()`, `setMaxRuns()` and `visible()` change the tool, and a toolkit's or connector's `only()`, `exclude()` and `with()` change it and return `$this`. On a shared service that silently changes every other agent's tools, including switching off an approval gate. The bundle registers tools and toolkits `shared: false`, configuration happens only inside the agent's `tools()` hook, and approval policies are invokable services or closures applied inside `tools()`.

### Side effects, identity and authorization in workers

A tool that charges a card or sends an email must be safe when its step is recovered. Core improvement C11 gives every call a `ToolContext` (workflow ID, run ID, execution attempt, call ID and a stable idempotency key, identical across recoveries), bound by `ToolNode` on the per-call clone. Tools pass the key to APIs that accept one, such as Stripe's `Idempotency-Key`, and otherwise record it under a unique database key before an effect that has none, such as sending an email. They read the thread's identity from the context, because a tool running in a Messenger worker has no security token, no request and no session. Until C11, a tool that needs identity receives it from the agent's `tools()` hook, which runs on the bound instance and can pass `$this->getThreadId()` into the tool, and uses `getCallId()` as a partial idempotency key.

Symfony AI's `#[IsGrantedTool]` checks the current token when a tool executes, which cannot work in a worker. The bundle offers the same idea bound to the captured actor instead of the token: a class attribute `#[IsGrantedForActor('REFUND_ISSUE')]` on a tool, evaluated before execution against the actor captured when the turn was submitted (stored in the run's state from the invocation payload or the start event), which an application-provided `ActorResolverInterface` loads as a `UserInterface` from the `ToolContext` workflow and run IDs (C11; until then, from the thread ID that the agent's `tools()` hook passes to the tool), with `UserAuthorizationCheckerInterface::isGrantedForUser()` (Symfony 7.3+). The bundle evaluates it in `AuthorizingToolNode`, a `ToolNode` subclass installed through `Agent::nodes()`, when each call is resolved and before the approval gate, so an approver is never asked about a call that will be denied. A denied call returns `ToolOutput::error()` to the model and is logged, so the conversation continues. Grants at composition time (which agent the actor may talk to, which tools that agent has) remain the first line of defence; the attribute is the second.

### MCP connectors

Each entry of `neuron.mcp.servers` becomes a `McpConnector` service `neuron.mcp.<name>` with a `#[Target]` alias. HTTP transports use the shared `SymfonyHttpClient`, so MCP traffic shows up in the profiler and can be mocked in tests. stdio servers run with `%kernel.project_dir%` as their working directory once C18 adds the working-directory option; until then the configuration uses absolute paths built from `%kernel.project_dir%`, as the sketch in section 5 does. C18 makes connectors fit the container: a lazy session, tool definitions cached in a PSR-16 cache (a Symfony cache pool wrapped in `Psr16Cache`), `close()` for worker resets, an environment allowlist and a configurable timeout and working directory for stdio children, an auth header resolver (`Closure(): array`) for per-user credentials, results mapped to `ToolOutput` (an MCP `isError` becomes `ToolOutput::error()`), `destructiveHint` mapped to a default approval policy, and a name prefix against collisions between servers. Connectors with static credentials are shared and tagged `kernel.reset` with `method: close`, so a session lasts until the next reset: one message under `messenger:consume` (unless `--no-reset`) and one request in FrankenPHP worker mode, while C18's cached tool definitions keep reopening cheap. Connectors that act on behalf of a user are built per request or message by a factory service that resolves that user's MCP credentials from the captured actor (through `ActorResolverInterface` and the application's credential store), never from the security token, so the same connector works in a controller and in a Messenger worker; C18's header resolver (`Closure(): array`) is where those credentials are read for each request.

**Interim (today's core).** `McpConnector` builds its `McpClient` on first use, but the client opens its session in its constructor, `tools()` lists the server's tools on every segment, and there is no `close()`. The bundle shares connectors per worker and clones them before narrowing (`(clone $this->github)->only([...])`), because `only()` mutates. Clones share the session only when the shared connector opened it before being cloned, so the bundle's holder primes it once per worker (one `tools()` call on the shared instance); otherwise every clone opens its own session, and for stdio its own process, on every segment. `StdioTransport` merges the full `getenv()` into the child's environment (`src/MCP/StdioTransport.php:76`), which hands every secret of the worker to the MCP server; until C18 the recipe declares stdio servers through `env -i` with an explicit `PATH` and `HOME`, and recommends HTTP transports. `env -i` also clears the server's own `env` entries, so variables it needs are passed as `NAME=value` arguments, which the process list exposes; secrets are better handed over by a small wrapper script that reads them from a file.

### Exposing Neuron through symfony/mcp-bundle

`symfony/mcp-bundle` serves MCP over HTTP and stdio and autoconfigures `#[McpTool]` capabilities. The bundle's optional adapter registers selected catalog tools (a tag attribute or a configuration list, never the whole catalog) as MCP tools, translating the input schema and mapping `ToolOutput` to MCP content. A tool called directly through MCP runs outside `ToolNode`: no approval gate, no `#[IsGrantedForActor]` check and no `ToolContext`. The adapter therefore refuses tools that override `approvalPolicy()` or carry an attach-time policy (those are exposed only through a durable workflow that keeps the gate), evaluates the authorization attribute against the authenticated MCP user, and the MCP endpoint sits behind a firewall. The adapter also exposes durable workflows as MCP tools that authorize the caller, issue the workflow ID server-side, call `ignite()` on the bound handle (proposed, C9), dispatch the continuation to the gateway through `InvocationDispatcher`, and return the workflow ID and run ID, so a long process is started from an MCP client and finished by Messenger. The registration API of `mcp/sdk` 0.8 must be verified before this adapter is implemented.

## 13. RAG

Vector stores and embeddings providers are configured by name (`neuron.vector_stores.<name>`, `neuron.embeddings.<name>`) and registered as `neuron.vector_store.<name>` and `neuron.embeddings.<name>` with `#[Target]` aliases; the entries named by `default_embeddings` and `default_vector_store` (or the only entry, when one is configured) go into `neuron.defaults`. RAG definitions receive them by constructor injection or through the defaults tier. With core improvement C21 ([neuron-core-improvements.md](neuron-core-improvements.md)), `vectorStore()` throws when nothing is configured, instead of silently falling back to an in-memory store as `ResolveVectorStore::vectorStore()` does today, which in production means an empty index and confident wrong answers. C21 also makes retrieved documents drop their embeddings, adds `RAGResources extends AgentResources` so RAG nodes read the segment's services, and gives `RetrievalTool` a mandatory scope: today it bypasses the RAG retrieval scope, which in a multi-tenant application leaks documents across tenants, so the bundle documents not exposing it to agents that serve several tenants until C21 lands. Applications that already configured Symfony AI stores could reuse them through a Neuron `VectorStoreInterface` adapter over `Symfony\AI\Store\StoreInterface` (`add()`, `remove()`, `query()`), a possible interop item to verify against that component's 0.x API (section 23).

Several stores do I/O in their constructors today; `QdrantVectorStore::__construct()`, for instance, calls the server to check and create its collection. With C4 constructors are pure and provisioning moves to `ManagedStoreInterface::setup()`, called by `neuron:setup`. Until then the bundle registers vector stores `lazy: true`, so building the container or a controller that never searches does not touch the network, and `neuron:setup` calls the existing provisioning methods where they exist (`MariaDBVectorStore::setupTable()`, `MongoDBVectorStore::setupVectorIndex()`) and initializes the lazy service of stores that provision in their constructors.

PostgreSQL applications get a pgvector store in core (proposed, C21), built on PDO with a reusable SQL filter compiler. In Symfony it receives a `Closure(): PDO` over the Neuron DBAL connection's `getNativeConnection()`, which is valid on the `pdo_pgsql` driver and, with C13, is resolved per operation. DBAL has no native vector column type, so the pgvector table is provisioned by `neuron:setup` rather than by the migrations diff unless the application registers a custom DBAL type; the exact interaction with the schema listener is to be verified. Either way the table must be made known to, or hidden from, the application's schema tooling: without a `vector` mapping DBAL introspection fails on that database, and a table absent from the desired schema shows up as a DROP in `doctrine:migrations:diff`. The recipe therefore documents `mapping_types: { vector: string }` or a `schema_filter` that excludes Neuron's vector tables (which of the two works best with the schema listener is to be verified).

Ingestion runs through Messenger. The bundle ships an `IndexSource` message (the vector store name, source type, source name, and a reference to the content, never the content itself) and a handler that calls the stateless `Indexer::reindexSource()` (proposed, C21). The indexer validates the whole batch before any write, assigns deterministic chunk IDs (UUIDv5 of source type, source name and index), upserts by ID, and deletes only the chunks that became stale, so a redelivered message converges instead of duplicating chunks. Large sources use the optional durable `IngestionWorkflow` (C21) through the gateway: one committed step per batch, so a worker crash resumes without embedding committed batches again, and a workflow ID of the form `ingest:<store>:<type>:<name>`, derived from the store and the source, doubles as a per-source mutex, so two stores indexing the same source never block each other. Readers become instance services that read content or streams (C21), so a loader can read from a `league/flysystem-bundle` storage, S3 included, instead of local paths only.

Entities drive re-indexing through Doctrine listeners: an `#[AsEntityListener]` (`postPersist`, `postUpdate`, `postRemove`) collects changed entities, and an `#[AsDoctrineListener(event: Events::postFlush)]` dispatches `IndexSource` once the flush is done; when an outer transaction is still open (for example a handler behind `doctrine_transaction`), the message carries `DispatchAfterCurrentBusStamp` so it leaves only after the commit. Embeddings are never computed inside a flush, where network I/O would run inside the unit of work and a rolled-back transaction would leave vectors for data that does not exist.

**Interim (today's core).** Ingestion uses one message per source and assigns deterministic IDs with `Document::setId()`. At-least-once ingestion is limited to stores that already upsert by ID, and after the upsert the handler deletes the source's chunks whose index (kept in metadata) is at or above the new chunk count; other stores delete by source and re-add under a per-source Symfony Lock and accept a short retrieval gap. Loaders read local files only, so text content from Flysystem is read by the handler and passed through `StringDataLoader`, and binary formats such as PDF are copied to a temporary local file and read with `FileDataLoader` and the matching reader.

## 14. Structured output

Symfony developers expect structured output to go through the Serializer, the Validator and the TypeInfo component, with validation messages that can be translated. Today the pipeline is hardwired: `StructuredOutputNode` calls `JsonSchema::make()->generate()`, `Deserializer::make()->fromJson()` and the static `Validator::validate()` (`src/Agent/Nodes/StructuredOutputNode.php:74`, `:165`, `:169`). Core improvement C20 ([neuron-core-improvements.md](neuron-core-improvements.md)) introduces `OutputMapperInterface` with `schema(string $class): array`, `map(string $json, string $class): object` and `validate(object $output): array`, delivered through `AgentResources` and the `OutputMapperInterface` key of the defaults tier, with a default implementation wrapping today's classes.

The bundle's `SymfonyOutputMapper` builds the JSON schema from TypeInfo and the Serializer's class metadata, applying the same name converter, `#[SerializedName]` and groups the deserializer applies, so the keys the model is asked for are the keys the mapper reads (reusing Symfony AI's schema factory when `symfony/ai-platform` is installed is an option to evaluate). It maps with `SerializerInterface::deserialize($json, $class, 'json')`, so custom denormalizers apply as well, and validates with `ValidatorInterface`, returning the violation messages. Those messages go back to the model when the node retries, so they are written for the model first; translating them with the Translator is optional. Output classes stay plain PHP objects shared by the API layer and the agent.

**Interim (today's core).** An application that needs Symfony's Serializer or Validator today subclasses `StructuredOutputNode`, overriding the protected `processResponse()`, and overrides `Agent::nodes()` to use it. The override rethrows `Symfony\Component\Serializer\Exception\ExceptionInterface` and validation violations as `AgentException`, because the node's retry loop only catches `AgentException` and `DeserializerException` (`src/Agent/Nodes/StructuredOutputNode.php:131`); any other exception fails the step instead of asking the model to correct its answer. Structured-output endpoints branch on the returned state's status, as described in section 10, until C24.

## 15. Observability

### Events and listeners

Neuron dispatches its observability events through the workflow's own PSR-14 dispatcher, which runs the listeners of Neuron's `ListenerRegistry` first, matched with `instanceof`, and then forwards the event to an external dispatcher (`src/Observability/EventDispatcher.php`). The bundle's `neuron.event_dispatcher`, placed under the `EventDispatcherInterface` key of the defaults tier, is that external dispatcher, the workflow's forward target (C3). It is itself a Neuron `EventDispatcher`: its registry holds every service tagged through `#[AsNeuronListener(event: ToolCalled::class)]`, and its own forward target is Symfony's `event_dispatcher`.

Two registries are needed because Symfony's EventDispatcher matches listeners by the exact event name, the FQCN, while Neuron's registry matches parent classes. A Symfony listener on `ObservabilityEvent` would never fire; a Neuron listener on it receives every event. Catch-all and base-class listeners therefore use `#[AsNeuronListener]`, while application listeners for one concrete event can use plain `#[AsEventListener(event: InferenceStop::class)]`, and in debug the traceable dispatcher shows Neuron events in the profiler's Events panel. Listeners that keep state key it by `$event->execution->runId`, because one listener instance sees interleaved runs, and implement `ResetInterface`.

Monitoring never changes execution. With core improvement C22 ([neuron-core-improvements.md](neuron-core-improvements.md)), `Node::emit()` goes through the same isolating path the segment uses for its own reports, so a failing listener is reported as a `WorkflowError` and never fails a step. C22 also adds a serializable `EventRecord` (class, name, data, workflow ID, run ID, attempt, branch ID, time) for Messenger-dispatched listeners and profiler storage, a `replayed` flag on `InferenceStop` and `ToolCalled` so cost dashboards do not count a recovered step twice, and the provider class and model on inference events.

**Interim (today's core).** `SegmentEventDispatcher::report()` isolates what the segment reports, but `Node::emit()` dispatches directly (`src/Workflow/Node.php:205-217`), so a throwing listener on an event emitted by a node, such as `ToolCalling` or `InferenceStart`, fails the step. The bundle wraps each registry listener and the forward to `event_dispatcher` in its own try/catch and logs the failure. Queued listeners receive a record the bundle builds by hand from `name()`, `toArray()` and the execution context. Replays cannot be told apart yet, so usage figures may double-count after a recovery.

### Profiler, Stopwatch and Traceable decorators

In debug, the bundle mirrors what Symfony AI offers. `NeuronDataCollector` extends `AbstractDataCollector` and implements `LateDataCollectorInterface`, because a streamed response finishes after `kernel.response`; it stores `EventRecord`s grouped by run and shows inferences (provider, model, token usage, duration), tool calls (name, approval state, duration, errors), interruptions, run IDs, attempts and final status. A Stopwatch listener opens spans in a `neuron` category for segments, nodes, inferences and tool calls, so they appear on the performance timeline next to Doctrine and HTTP client spans; C22's optional span-pairing interface on start and end events makes this pairing exact. `DebugDecoratorsPass` decorates providers, persistence and message stores with Traceable services tagged `kernel.reset`. Because the DBAL backends use the DBAL connection, their queries are in the Doctrine panel too, and console commands benefit from the global `--profile` option.

### Logging and context

`LogListener` is registered on the `neuron` Monolog channel and subscribed to `ObservabilityEvent` through the registry. With C22 it picks a level per event (errors as errors, node spans as debug); today it logs everything at one level. `WorkflowEnd::toArray()` includes the full workflow state, and `InferenceStart`, `InferenceStop`, `MessageSaving`, `MessageSaved`, `ToolCalling` and `ToolCalled` carry prompts, model answers and tool inputs and results. With `observability.log_state: false`, the default, the bundle's `LogListener` subclass therefore drops the `state`, `message` and `response` fields and the tool's `inputs` and `result`, and keeps identifiers, names and usage. A Monolog processor adds the workflow ID, run ID and attempt from the current execution context to every record written during a segment. The web request's correlation or trace ID travels to the worker in a Messenger stamp that `InvocationDispatcher` adds, and a bundle middleware on `neuron.bus` restores it before the handler invokes the gateway (a handler receives the message, not the envelope), so one conversation can be followed across the controller, the worker and the provider calls.

## 16. Testing

Tests replace collaborators through configuration first and through the test container second. The recipe adds a `when@test` block that switches Neuron to in-memory storage, core's fakes and in-memory transports, so RAG, ingestion, classification, MCP-backed agents and pushes are testable offline:

```yaml
when@test:
    neuron:
        persistence: { type: memory }
        message_store: { type: memory }
        default_provider: fake
        providers:
            fake: { driver: fake }          # NeuronAI\Testing\FakeAIProvider
        embeddings:
            default: { driver: fake }       # FakeEmbeddingsProvider (entries replace, never merge)
        vector_stores:
            docs: { type: fake }            # FakeVectorStore
        classifiers:
            triage: { driver: fake }        # FakeClassifier
        mcp:
            servers:
                github: { transport: fake } # McpConnector over a FakeMcpTransport
        streaming: { transport: fake }      # channel factories return one shared FakeChannel instead of Mercure
        gateway: { transaction_guard: false }
    framework:
        messenger:
            transports:
                neuron: 'in-memory://'
```

A test that needs specific model answers replaces the configured provider's service before first use: `static::getContainer()->set('neuron.provider.fake', new FakeAIProvider(new AssistantMessage('Your order shipped yesterday.')))`. Because the `neuron.defaults` locator references every entry, those services survive compilation and are never inlined, so the test container can replace them. Every fake is registered as a shared service, even before C5 makes providers shareable: the test container can only swap a shared service for an instance (7.4 and 8.0 ignore the replacement of a non-shared one, and 8.1 requires a closure factory), and the fakes' recorded-call assertions need the one instance the run used. The same holds for `neuron.embeddings.default`, `neuron.vector_store.docs`, `neuron.classifier.triage`, the transport service of each faked MCP server (passed to `McpConnector` through its `transport` configuration key, which `McpClient` accepts as an `McpTransportInterface` instance) and `neuron.channel.fake`. Core's fakes already record and assert: `FakeAIProvider` (`assertCallCount()`, `assertSent()`, `assertToolsConfigured()`), `FakeEmbeddingsProvider` (`assertEmbeddedText()`), `FakeVectorStore` (`assertSearchedWithFilters()`, `assertHasDocumentWithContent()`), `FakeClassifier` (`assertSent()`), `FakeMcpTransport` (`assertToolCalled()`) and `FakeChannel` (`assertSent()`, `assertSuspended()`, `assertCompleted()`). Provider-level tests that must exercise the real provider classes use `MockHttpClient` and `MockResponse` through `SymfonyHttpClient` instead.

`NeuronAssertionsTrait` turns the data collector into functional-test assertions, in the style of Symfony AI's `AiAssertionsTrait`: the number of inferences, which tools were called and with what approval state, whether a run suspended on a given interrupt type or completed, and which invocations the gateway received. It also exposes the fakes registered by `when@test` (`self::getFakeVectorStore('docs')`, `self::getFakeChannel()` and the like), so a test reaches their assertions without knowing service IDs. Tests call `$client->enableProfiler()` before the request. For queued flows, the trait reads `InvokeWorkflow` payloads from the in-memory transport to assert what was dispatched, and can drain the transport through the real handler until no message is available, so a test can post a turn, deliver an approval and assert the final state in one method. Multi-request flows call `$client->disableReboot()`, because the default reboot between requests discards in-memory stores and test-container replacements; the bundle does not tag its in-memory persistence and message store `kernel.reset`, so they survive between requests of one test. The in-memory transport honours `DelayStamp` against the `clock` service, so a delayed wake stays queued until the test moves the clock, which is why the drain stops when the transport has no available message rather than when it is empty. After C10 one `mockTime()` call moves the engine and the transport together (next paragraph).

Time is the one thing tests cannot control today. With core improvement C10 ([neuron-core-improvements.md](neuron-core-improvements.md)) the engine, the segment heartbeat and interrupt due checks use a PSR-20 clock, which the bundle takes from Symfony's `clock` service; `ClockSensitiveTrait::mockTime()` then drives leases, `SleepUntilRequest` wakes, approval expiries and the sweep's due query in a `KernelTestCase`. Until C10, the engine calls `time()` directly (`use function time;` in `src/Workflow/WorkflowEngine.php:27`), so timer and lease tests use real deadlines one or two seconds in the future, or assert only the projection row and the scheduled wake.

The bundle's own adapters prove themselves against core. The test classes of `DoctrineDbalPersistence`, `DoctrineDbalMessageStore` and `DoctrineDbalProjectionStore` extend the abstract contract test cases core ships in `src/Testing` (`PersistenceContractTestCase`, `MessageStoreContractTestCase`, `ProjectionStoreContractTestCase`, proposed in C13), and, while it exists, the tests of the interim HTTP adapter extend `HttpClientContractTestCase` (proposed, C13 and C17). The bundle's CI runs them on SQLite, PostgreSQL, MySQL and MariaDB, on Symfony 7.4 with PHP 8.2 and on Symfony 8.x with PHP 8.4.

## 17. Console and makers

Operational commands are `#[AsCommand]` services (Symfony 8.1 deprecates registering commands through `Bundle::registerCommands()`), and they address runs by definition name and workflow ID.

| Command | What it does |
|---|---|
| `neuron:setup` | provisions every managed store (tables, collections, indexes) through `ManagedStoreInterface::setup()` (C4); `--check` reports the lease, timeout and redelivery alignment of section 9 |
| `neuron:runs` | lists runs from the projection, filtered by definition, status, due time or `--definition-version` (`--version` is Symfony Console's global option) |
| `neuron:inspect` | prints the snapshot of one run, including the current interrupt as JSON |
| `neuron:abandon` | abandons a run, fenced by the run ID and attempt given on the command line; Agent's guard against unanswered tool calls applies |
| `neuron:acknowledge` | acknowledges a retained completion by run ID |
| `neuron:recover` | dispatches a fenced inputless wake, which recovers a failed or expired run with its committed steps |
| `neuron:wake` | runs one sweep of the projection, for applications that use cron instead of the Scheduler |
| `neuron:prune` | applies retention (section 8): abandons, through the definition's own `abandon()`, runs whose projection row is untouched for longer than `neuron.workflows.<name>.prune_after` (suspended without a deadline, or parked after a failure), reports the runs Agent's guard refused, and removes expired interim start rows |
| `neuron:evaluate` | runs evaluators resolved from the `neuron.evaluator` tag, with evaluation-scoped storage (below) |

### Operator runbook

The commands map onto the symptoms operators meet in production:

| Symptom | Meaning | Action |
|---|---|---|
| 409 with `Retry-After` on a thread | the run holds a fresh lease: a turn is executing, or a process died and its lease has not expired | wait for `Retry-After`; `neuron:inspect` shows the run, its attempt and (with C7) the lease expiry; once the lease expired, `neuron:recover` finishes an aborted turn from its committed steps |
| a parked failed run (`neuron:runs --status=failed`) | Messenger gave up: the retry strategy is exhausted or the failure is unrecoverable (section 9) | `neuron:inspect`, fix the cause, then `neuron:recover`; `neuron:abandon` gives up on it |
| a stale suspended run | a wait without a deadline that nobody answers | answer it through the application, `neuron:abandon` it, or let `neuron:prune` apply `prune_after` |
| `DefinitionVersionException` after a deploy | runs recorded by a previous definition version reached consumers of the new one | map the version under `neuron.workflows.<name>.versions` and keep a consumer of the previous release on that transport until `neuron:runs --definition-version=<old>` is empty (section 9) |
| records fail verification after rotating `kernel.secret` | signed records were written with the previous secret | add the previous secret to `neuron.serializer.previous_secrets` and keep it until no run signed with it remains |
| a message in `neuron_failed` | an invocation failed for good; its run is parked, or the invocation was refused | `neuron:inspect` first, then `messenger:failed:retry` (fenced, so safe) or `messenger:failed:remove` |

### Evaluation

`neuron:evaluate` wraps the typed evaluation application service of core improvement C23 ([neuron-core-improvements.md](neuron-core-improvements.md)), which writes through an injected writer instead of `echo` and accepts an explicit class list, so evaluators are autoconfigured services resolved from a tagged locator: `registerForAutoconfiguration(BaseEvaluator::class)` adds the `neuron.evaluator` tag. Until C23, the command wraps core's `EvaluationCommand` with its `$resolver` argument pointing at the locator and buffers its echoed output into the Symfony `OutputInterface`, and evaluator subclasses that declare a constructor call `parent::__construct()`, because `BaseEvaluator::__construct()` creates the rule executor until C4 makes it lazy.

Container-built evaluators receive definitions wired to the production stores, so the command must keep evaluations out of production state. It runs with evaluation-scoped defaults unless `--persist` is given: the defaults tier answers `PersistenceInterface` and `MessageStoreInterface` with in-memory stores for the duration of the command, while providers stay real (the bundle decorates `neuron.defaults` for this, which works because hooks consult the tier lazily after C3). Until C3 the setters wired at compile time cannot be switched, so evaluations run in a dedicated environment (`APP_ENV=eval`) whose `when@eval` block selects memory stores. Each dataset item needs its own conversation: an agent injected into an evaluator and reused across items chains every item into one thread, and throws `UnboundWorkflowException` once C2 lands, so evaluators bind per item with `for()` and a generated ID (today, a fresh prototype from the `neuron.workflow` locator with `setThreadId()`). Judges need the same care. `AgentJudge` sends every judgment to the agent it was constructed with, so the judgments of one evaluation share a conversation and each sees the previous ones; evaluators build each judge over a fresh handle (today, a fresh prototype with a generated thread ID). `ClassifierJudge` needs no such care: it receives a shared classifier service (section 7) and keeps no conversation. `--concurrency` forks with `spatie/fork`, so the command passes `EvaluationCommand` an `EvaluatorRunner` whose `beforeChild` hook is the bundle's fork-safe callable (section 18). The run cache (`--cache`) lives in `%kernel.project_dir%/var/neuron/evaluation` rather than core's default `.neuron/cache/evaluation`.

### Makers

Generators are MakerBundle makers with the `make:neuron-*` names shared with the Laravel package: `make:neuron-agent`, `make:neuron-tool`, `make:neuron-workflow`, `make:neuron-node`, `make:neuron-middleware`, `make:neuron-rag` and `make:neuron-evaluator`. They render the framework-neutral stubs of C23 into `App\Neuron`: constructor injection, `#[AsWorkflow]`, no `env()` calls and no provider built inline in a hook. Today's core `agent.stub` builds `new Anthropic(key: 'ANTHROPIC_KEY', ...)` inside `provider()`, so until C23 the makers use bundle-owned templates.

## 18. FrankenPHP, Runtime and long-running worker checklist

FrankenPHP worker mode, the Runtime component and `messenger:consume` all keep one container alive across many requests or messages. The checklist below is what the bundle guarantees and what applications must follow.

- Definitions are shared services only after C2. Until then they are prototypes, resolved per action or through the `neuron.workflow` locator, and never injected into the constructor of a shared service such as a controller, a Messenger handler or a console command.
- Definitions are configured at build time and customized per call only on handles returned by `for()`; setters such as `setStreamAdapter()`, `subscribe()`, `addMiddleware()` or `setAiProvider()` are never called on an injected shared definition at request time.
- Providers are shared only after C5; until then they are `shared: false`. Stores, the serializer, the engine, the gateway and the HTTP client are always shared, and the DBAL backends hold a DBAL `Connection`, never a captured native PDO.
- MCP connectors with static credentials are shared per worker. With C18 they are tagged `kernel.reset` and their reset calls `close()`; until then a stdio child lives as long as the worker and ends when `McpClient::__destruct()` runs. Per-user connectors are built per request or message from the captured actor.
- `FrankenPhpWorkerRunner` (Runtime 7.4) sets `ignore_user_abort(true)` itself, and `FRANKENPHP_RESET_KERNEL` (8.1) resets the kernel between requests. `NeuronStreamResponse` sets and restores `ignore_user_abort()` regardless, so its behaviour does not depend on the runtime.
- Messenger workers run with `--time-limit` and `--memory-limit`; a worker stopped between messages loses nothing. `--no-reset` (and, from 8.1, `--no-reset=N` to reset every N messages) is safe for bundle services, because none of them keeps correctness-relevant state.
- `parallelToolCalls()` forks with `spatie/fork` and requires `pcntl`. It is allowed only in Messenger workers and console commands, never in FrankenPHP workers or FPM requests; C25 makes that opt-in explicit and refuses it outside the CLI. The bundle ships a `beforeChild` callable that applications pass to `parallelToolCalls()` (today that call sets the flag and the hooks together, so the bundle cannot install a default without enabling parallelism). For each Doctrine connection it keeps a reference to the inherited native connection (`getNativeConnection()`), so no destructor runs in the child, which `spatie/fork` ends with `SIGKILL`, and then calls `close()` on the DBAL wrapper, so the child reconnects on its own socket. Closing the inherited handle without pinning it would send the driver's quit message on the socket shared with the parent and end the parent's database session. The HTTP client opens its own connections in the child (section 7).
- `AsyncBranchRunner` is opt-in, because core does not declare `amphp/amp` as a dependency and its behaviour inside FrankenPHP's threaded workers is unverified; `SequentialBranchRunner` is the default.
- Stateful listeners, collectors and decorators implement `ResetInterface` and key per-run state by run ID.
- Workflow state holds identifiers, never entities: the entity manager is cleared between messages, and nodes reload what they need.

## 19. Security checklist

- Thread IDs are server-issued ULIDs, and every thread endpoint authorizes the `NEURON_THREAD` attribute on the resolved conversation and checks that the thread belongs to the route's definition before binding it, with `for()` (proposed, C2) or, today, `setThreadId()`/`setWorkflowId()`.
- Queued continuations capture the actor at submission. Tools running in workers read `ToolContext` (C11) and state, never the security token. The bundle's `#[IsGrantedForActor]` attribute authorizes a tool call against the captured actor that `ActorResolverInterface` loads from the `ToolContext` workflow and run IDs (proposed, C11), through `isGrantedForUser()`, never against the current token.
- Signals come only through signed webhooks with a dedicated secret (or the vendor's own scheme for vendor webhooks), and the signature covers a timestamp and a delivery ID so captured requests cannot be replayed; the run's projection row must name the definition the signal targets. They are the only unauthenticated Neuron endpoints.
- Mercure updates are private, and subscriber JWTs are issued per topic after the voter check.
- `PhpSerializer` calls `unserialize()` on stored records, so write access to the workflow store is an object-injection vector. `serializer.signed: true` wraps it in an HMAC-signing serializer keyed by `%kernel.secret%` (proposed, C12; until then a bundle-owned decorator), which is compatible with compare-and-swap because the engine compares the raw bytes it read. Signing is off by default, as in the Laravel package, because records written unsigned do not verify once it is on; production applications whose workflow store is reachable by anything other than the application enable it before their first run. The serializer signs with the current secret and verifies against it and `serializer.previous_secrets`, so rotating `kernel.secret` means moving the old value into that list and removing it once no run signed with it remains.
- Provider keys never reach logs or the profiler's exception panel: `HttpException` stores a redacted request and keys carry `#[\SensitiveParameter]` (C17); until then the bundle's HTTP adapter redacts before throwing. The profiler's HTTP Client panel records request headers, so profiles are never collected with production keys.
- Problem responses carry the HTTP status phrase and structured fields, never exception messages (section 10).
- stdio MCP servers receive an explicit environment allowlist (C18); until then, `env -i` in the server command.
- `observability.log_state` stays false in production. `WorkflowEnd` carries the full state, and `InferenceStart`, `InferenceStop`, `MessageSaving`, `MessageSaved`, `ToolCalling` and `ToolCalled` carry prompts, model answers and tool inputs and results, so with the flag off the bundle's `LogListener` subclass drops those fields and keeps identifiers, names and usage (section 15). The profiler, which stores prompts and tool arguments, is never enabled in production.
- `RetrievalTool` is not given to agents that serve several tenants until it requires a scope (C21).
- Tools with side effects declare approval policies. SQL write tools declare theirs with C18 (proposed); until then the agent's `tools()` hook calls `requireApproval()` on them.
- Tools exposed through `symfony/mcp-bundle` never carry an approval policy, and the MCP endpoint sits behind a firewall (section 12).
- Production uses a dedicated DBAL connection for Neuron (section 8), and the transaction guard stays enabled outside tests.
- Erasing a user settles each of the user's threads on its handle, `acknowledge()` for a retained completion and otherwise `resetConversation()`, then removes the projection row (section 8).
- Turn endpoints accept an `Idempotency-Key` scoped to the user and the thread, and signal endpoints sit behind a rate limiter (section 10).

## 20. Flex recipe and developer experience

The recipe lives in `symfony/recipes-contrib`. It registers the bundle in `config/bundles.php` and adds `config/packages/neuron.yaml` with the `neuron` tree (the default provider, persistence and message store on the `default` DBAL connection, the serializer and the gateway), the `when@test` block, and a Messenger block. That block declares the `neuron` transport with `redeliver_timeout` above the default lease and its own `failure_transport: neuron_failed`, set on the transport so the application's global failure transport is untouched; the `neuron_failed` transport; and the `neuron.bus` bus, with the bundle's connection middleware (section 8) and without `doctrine_transaction`. Declaring any bus disables FrameworkBundle's implicit `messenger.bus.default`, and a single declared bus becomes the default one, so the block also declares `messenger.bus.default: ~` and `default_bus: messenger.bus.default`, which reproduces what an application without declared buses already has. An application whose own `messenger.yaml` declares buses removes those two lines, as the post-install message says, because `neuron.yaml` loads after `messenger.yaml` and its `default_bus` would win; `ValidateDefinitionsPass` fails the build when `neuron.bus` is the application's default bus or carries `doctrine_transaction`. The recipe adds `.env` keys for the provider API key, the model and the transport DSN (`NEURON_TRANSPORT_DSN=doctrine://default?queue_name=neuron`), and a commented `config/routes/neuron.yaml` importing the optional endpoints. It does not write Doctrine configuration: the post-install message shows the dedicated `neuron` connection block that section 8 recommends and the two keys (`persistence.connection`, `message_store.connection`) that switch Neuron to it, and the next steps: `bin/console doctrine:migrations:diff` (or `bin/console neuron:setup`) and `bin/console messenger:consume neuron scheduler_neuron --keepalive`.

Developers inspect the integration with the tools they already know: `debug:config neuron` and `config:dump-reference neuron` for configuration, `debug:container --tag=neuron.workflow` for registered definitions, `debug:messenger` and `debug:scheduler` for the runtime, the profiler panel for runs, and `neuron:runs` in production. The bundle stays experimental, as its README and the recipe's post-install message state, until core 4.x is stable; the Symfony AI Platform adapter and `symfony/mcp-bundle` exposure activate only when those packages are installed.

## 21. Bundle layout

```text
neuron-bundle/
├── composer.json                     neuron-core/neuron-ai ^4.0, php >=8.2, symfony/* ^7.4|^8.0
│                                     (framework-bundle, messenger, scheduler, lock,
│                                     doctrine-messenger), doctrine/doctrine-bundle;
│                                     neuron-core/gateway (@internal pre-release, until C14)
├── config/
│   ├── definition.php                configuration tree
│   ├── services.php                  defaults locator, engine, gateway, providers, stores
│   ├── services_debug.php            collector, Stopwatch listener, Traceable decorators
│   └── routes.php                    optional endpoints
├── src/
│   ├── NeuronBundle.php
│   ├── Attribute/                    AsNeuronListener, IsGrantedForActor, AsWorkflow (interim, C19)
│   ├── DependencyInjection/Compiler/ ValidateDefinitionsPass, ListenerRegistryPass,
│   │                                 DebugDecoratorsPass, LegacyDefaultsPass (interim, C3)
│   ├── Provider/                     ProviderFactory, driver maps (providers, embeddings, classifiers),
│   │                                 SymfonyAIPlatformProvider
│   ├── HttpClient/                   SymfonyHttpClient (interim, removed with C17)
│   ├── Storage/Dbal/                 DoctrineDbalPersistence, DoctrineDbalMessageStore,
│   │                                 DoctrineDbalProjectionStore, NeuronSchemaListener,
│   │                                 fork-safe beforeChild callable
│   ├── Messenger/                    InvokeWorkflow, InvokeWorkflowHandler, InvocationDispatcher,
│   │                                 ParkFailedRunListener, IndexSource, IndexSourceHandler,
│   │                                 connection and trace middlewares
│   ├── Scheduler/                    NeuronSchedule, SweepDueRuns, SweepDueRunsHandler
│   ├── Gateway/                      SchedulingProjectionStore, SymfonyCompletionHandler,
│   │                                 WorkflowCompleted event
│   ├── Http/                         NeuronStreamResponse, ProblemDetailsListener,
│   │                                 StreamSessionListener, ProtocolRequestResolver,
│   │                                 idempotency-key guard, Controller/,
│   │                                 Dto/ (ChatTurn, ApprovalDecisions, ToolResults)
│   ├── Security/                     ThreadVoter, NeuronThreadInterface,
│   │                                 NeuronThreadRepositoryInterface, ActorResolverInterface,
│   │                                 AuthorizingToolNode
│   ├── Mercure/                      channel factory, MercureHubChannel (every hub until C16,
│   │                                 then only in-process hubs such as FrankenPhpHub)
│   ├── Webhook/                      SignalRequestParser, SignalConsumer
│   ├── Observability/                listener registry wiring, LogListener subclass, Monolog processor
│   ├── Profiler/                     NeuronDataCollector, StopwatchListener, Traceable decorators
│   ├── StructuredOutput/             SymfonyOutputMapper
│   ├── Mcp/                          connector factory, mcp-bundle exposure
│   ├── Command/                      neuron:* commands
│   ├── Maker/                        make:neuron-* makers
│   └── Test/                         NeuronAssertionsTrait
├── templates/
│   ├── profiler/neuron.html.twig
│   └── maker/                        agent, tool, workflow, node, middleware, rag, evaluator
└── tests/                            contract suites (C13), functional kernel tests
```

## 22. Dependencies on core improvements

Every row is a bundle feature, the core improvement that makes it clean (details in [neuron-core-improvements.md](neuron-core-improvements.md)), its priority (P0 before core 4.0, P1 before the bundle goes stable, P2 soon after), and the approach the bundle uses against today's core. The bundle can ship an experimental preview on the interim column once defects D1 to D4, D8, D12 and D13 are fixed on the core branch.

| Bundle feature | Core improvement | Priority | Interim approach against today's core |
|---|---|---|---|
| Aborted streams release the thread | C1 | P0 | always drain with `ignore_user_abort(true)`; long turns in queued mode; the lease releases an abandoned generator, and `neuron:recover` finishes the aborted turn |
| Shared definitions, handles from `for()`, context-aware factories | C2 | P0 | `shared: false` definitions, `setThreadId()` (Agent, RAG) or `setWorkflowId()` (Workflow) right after resolution, locator in long-lived services, concrete `Workflow` typing |
| `neuron.defaults` applied with `setDefaults()` | C3 | P0 | compile-time reflection pass adding setters only where the hook is not overridden; `setEventDispatcher()` always; evaluations in a dedicated environment |
| Autowired constructors, pure construction, `neuron:setup` | C4 | P1 | `parent::__construct()` in subclasses and evaluators; vector stores `lazy: true`; existing provisioning methods |
| Shared provider services | C5 | P1 | `shared: false` providers; a factory inside `provider()` for multi-segment instances; audio and image providers built by the application |
| Typed refusal mapping in the handler and the exception listener | C6 | P0 | class and field checks on `RunInFlightException`, `StaleWorkflowRunException`, `InputTranslationException` and `HttpException`, plus `inspect()` comparisons of run ID, attempt and interrupt ID; never message patterns |
| Timers from `deadline()`, lease expiry on snapshots | C7 | P1 | `instanceof` ladder over `SleepUntilRequest` and `WaitForEventRequest`; recheck rows at now + lease + sweep interval |
| Redelivered answers converge | C8 | P1 | on a stale-attempt refusal, inspect and re-fence when the current interrupt is still the answered one; otherwise discard |
| 409 before 202, JSON-only messages | C9 | P1 | `start` kind with a serializer-encoded event and a reserved run ID, ignited only under the shared start rule of section 9; `inspect()` pre-check at the edge |
| `MockClock` drives leases and timers | C10 | P1 | real deadlines a few seconds ahead; assertions on the projection |
| Idempotency keys and identity for tools, lease renewal on memo commits | C11 | P1 | `getCallId()` plus the thread ID passed from `tools()`; lease sized above a whole tool loop |
| Signed serializer, definition versions, version routing | C12 | P1 | a bundle-owned signing `Serializer` decorator with `previous_secrets`; drain or abandon before incompatible deploys; `class_alias` shims |
| Canonical schema and contract tests | C13 | P1 | tables defined by the bundle's DBAL backends with core's encodings; contract tests ported from core's test suite |
| Gateway, projection, sweep and the terminal-failure rule | C14 | P1 | the shared `@internal` pre-release of `neuron-core/gateway` with the surface of C14, which the Laravel package also depends on; replaced by `NeuronAI\Gateway` when C14 ships |
| `SSEStream`, adapter headers, protocol request resolvers, `EventStreamResponse` variant | C15 | P2 | priming with `current()`, `getHeaders()` on concrete adapters, bundle protocol DTOs |
| Core `MercureChannel`, Redis cluster and Relay | C16 | P2 | `MercureHubChannel` over `HubInterface::publish()`; phpredis `\Redis` only |
| `SymfonyHttpClient`, HTTP error contract, redaction | C17 | P1 | bundle-internal adapter that raises `HttpException` itself and redacts the request |
| Shared tools, policy services, MCP `close()` and environment allowlist | C18 | P1 | `shared: false` tools configured inside `tools()`; cloned connectors; `env -i` for stdio |
| `#[AsWorkflow]` discovery and build-time metadata | C19 | P2 | a bundle attribute with the same shape, plus the `neuron.workflows.<name>.class` map |
| Serializer, Validator and TypeInfo for structured output | C20 | P2 | subclass `StructuredOutputNode` and override `Agent::nodes()` |
| Indexer, upsert by ID, deterministic chunk IDs, no memory fallback, scoped `RetrievalTool` | C21 (P1 part) | P1 | deterministic IDs with `Document::setId()`; upserting stores only, or delete and re-add under a Lock; a configured store for every RAG; `RetrievalTool` withheld from agents that serve several tenants |
| pgvector store, Flysystem readers, `IngestionWorkflow`, `fromArray()` factories | C21 (the rest) | P2 | `StringDataLoader` and temporary files for binary formats; one ingestion message per source |
| Isolated listeners, `EventRecord`, replay flag, per-event log levels | C22 | P1 | try/catch around every bundle-registered listener; hand-built records; documented double counting |
| `neuron:evaluate`, maker templates | C23 | P2 | wrap `EvaluationCommand` with a resolver, a fork-safe runner and output buffering; bundle-owned templates |
| Structured-output and approval endpoints, approval expiry | C24 | P2 | `run(ExecutionRequest::start(...))` with `outputClass`, branching on status; approval endpoints type the handle as the concrete `Agent`, because `AgentInterface` does not declare `pendingApprovals()`, `submitApprovalDecisions()` or `submitToolResults()`; a `ToolNode` subclass installed through `Agent::nodes()` that passes a deadline memoized once per approval from `buildApprovalRequest()` and rejects every pending call with "Approval expired" when `resolveToolApprovals()` resumes on a timeout (section 10) |
| Parallel tools restricted to the CLI, removed deprecated APIs | C25 | P2 | documented restriction; the bundle never exposes `observe()` or `Node::checkpoint()` |

## 23. Delivery roadmap

The roadmap is sequenced so the bundle is usable early and each core release mostly deletes interim bundle code; the few application-facing changes are named per milestone.

1. **Preview against today's core (experimental).** Prerequisite: defects D1 to D4, D8, D12 and D13 are fixed on the core branch. Bundle skeleton, configuration tree, prototype definitions with the compile-time defaults pass, non-shared providers, classifiers, the interim HTTP adapter, DBAL backends with the schema listener and `neuron:setup`, `NeuronStreamResponse` with priming, draining and session detachment, `ThreadVoter`, the problem+json listener, the Messenger message, handler, `InvocationDispatcher` and `ParkFailedRunListener` over the shared `@internal` pre-release of `neuron-core/gateway`, the projection with `SchedulingProjectionStore` and the Scheduler sweep, the interim Mercure channel, the listener registry and Monolog channel, the operational commands, the makers, the `when@test` configuration with core's fakes and `NeuronAssertionsTrait`. This milestone proves the gateway protocol end to end. Done when the DBAL contract suites are green on SQLite, PostgreSQL, MySQL and MariaDB, and the gateway scenarios (duplicate delivery, a crash between the run and the acknowledgement, a lost dispatch of a continuation, an early wake, a signal before its wait, a terminal failure parked once) pass through the real handler on the Doctrine transport.
2. **Core 4.0 P0 (C1, C2, C3, C6).** Definitions become shared services with `for()`; `setDefaults()` replaces the reflection pass; the handler and the exception listener map typed refusals and the inspect-based classification becomes a type switch; abandoned segments settle themselves. Application code changes in two places: `setThreadId()`/`setWorkflowId()` after resolution becomes `for()`, and channel factories read the `ExecutionContext` instead of the bound instance. Done when one shared Agent serves interleaved threads in a single `messenger:consume` worker and in a FrankenPHP worker, and every row of the refusal table in section 9 is covered by a test that throws the typed exception.
3. **Core P1 (C4, C5, C7 to C14, C17, C18, the P1 part of C21, C22).** The pre-release dependency is replaced by `NeuronAI\Gateway`; queued turns ignite synchronously and messages become JSON-only; answers fence on interrupts; `MockClock` drives tests; tools receive `ToolContext`; definition versions route to their own transports; stores implement `ManagedStoreInterface` and extend the core contract tests; `SymfonyHttpClient` comes from core; providers, tools and connectors become shared; ingestion goes through the `Indexer` with deterministic chunk IDs and upserts, every RAG has a configured store and `RetrievalTool` is scoped; observability is isolated and serializable. Applications drop `parent::__construct()` from definition constructors (C4), switch attach-time tool configuration to the copying `with*()` methods (C18) and read identity from `ToolContext` (C11). With this milestone done and core 4.x stable, the bundle leaves experimental status. Done when the gateway scenarios of milestone 1 pass with JSON-only messages on the Symfony Serializer transport, `MockClock` drives an approval expiry and a lease takeover in a `KernelTestCase`, and a redelivered `IndexSource` message leaves the index unchanged.
4. **Core P2 (C15, C16, C19, C20, the rest of C21, C23, C24, C25).** `SSEStream` and the `EventStreamResponse` variant, protocol resolvers over core request objects, core `MercureChannel`, core `#[AsWorkflow]`, `SymfonyOutputMapper`, pgvector, Flysystem readers and the durable `IngestionWorkflow`, the evaluation service and framework-neutral stubs, typed structured-output suspension and built-in approval expiry, and the removal of deprecated APIs with parallel tool calls refused outside the CLI. `#[AsWorkflow]` imports move from `NeuronAI\Symfony\Attribute` to `NeuronAI\Attributes`, custom `StructuredOutputNode` subclasses give way to `SymfonyOutputMapper`, and the interim approval `ToolNode` subclass gives way to `setApprovalTimeout()`. Done when the bundle ships no interim code except `MercureHubChannel` for in-process hubs.
5. **Interop.** The Symfony AI Platform adapter, a Neuron `VectorStoreInterface` adapter over Symfony AI stores, `symfony/mcp-bundle` exposure of tools and durable workflows, and an exploration of Turbo Streams for Hotwire applications. Done when each adapter passes the core contract test case of its interface.

### Open questions

These points must be verified before or during implementation; none of them changes the architecture.

- The session detachment listener's priority relative to Symfony's session listener on 7.4 and 8.x, and whether a session started during the streamed request still gets its cookie.
- `UserAuthorizationCheckerInterface::isGrantedForUser()` (introduced in Symfony 7.3) for checks against a captured actor in workers: behaviour with voters that expect a full token.
- The `mcp/sdk` 0.8 API for registering capabilities programmatically, needed by the MCP exposure adapter.
- Whether Symfony 8.1's `GuzzleHttpHandler` preserves incremental SSE delivery, for the fallback HTTP path.
- Whether `ClockSensitiveTrait::mockTime()` drives the `clock` service in every kernel test setup, once C10 lands.
- The pgvector table and the schema listener: a custom DBAL type versus provisioning only through `neuron:setup`.
- The batch encoding of Mercure updates that `@neuron-core/streaming` accepts, if batches larger than one envelope are enabled.
- The SQS visibility timeout as the redelivery bound R, and the delay cap the bundle should assume for other transports.
- Per-tenant Neuron storage (section 8): switching the Neuron connection in a `neuron.bus` middleware and running one sweep per tenant, checked against the tenancy bundles in use.
- The `symfony/ai-store` 0.x API for a Neuron `VectorStoreInterface` adapter, in particular filters, upsert semantics and whether query results carry scores.
- Composed definitions (section 6), an open question for core: how a child's completion and the parent's memo commit together, so a crash between them does not rerun the child, and how a suspending child could suspend its parent.
- Decisions that belong to the maintainer and shape the bundle: C2 versus prototypes with a factory contract, the placement of DBAL backends (bundle or core), and the gateway as a core module or as `neuron-core/gateway` (either way, the bundle depends on it and never carries a copy).
