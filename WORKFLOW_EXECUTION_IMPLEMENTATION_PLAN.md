# Workflow definition/execution separation: implementation plan

Status: engine implementation complete on `runner`; SDK and app-demo adoption remain pending.
Date: 2026-09-22.
Architecture: [Runnable definitions and isolated executions](WORKFLOW_EXECUTION_ARCHITECTURE.md).
Baseline: Neuron AI HEAD `df30064d` plus the existing uncommitted execution/idempotency work.
Compatibility: direct breaking changes; no compatibility layer or versioned protocol.

## 1. Goal and scope

Make Workflow/Agent reusable runnable definitions. Move each invocation's identity, state, graph, resources, and lifecycle into an independent execution. Preserve the simple `make()->chat()`, `stream()`, `structured()`, and `run()` experience.

Do not implement the separation by renaming the current preparation callback or cloning the entire Workflow with all its current execution fields. The definition must stop being the execution runtime.

This plan replaces the relevant engine ownership/preparation approach in [LOCAL_ORCHESTRATION_IMPLEMENTATION_PLAN.md](LOCAL_ORCHESTRATION_IMPLEMENTATION_PLAN.md). The [global orchestration goals](../neuron-cloud/ORCHESTRATION_IMPLEMENTATION_PLAN.md) still apply. The native and Laravel SDK plans need their preparation contracts aligned before SDK implementation proceeds.

The main implementation belongs in this project. SDK adaptation and the eventual app-demo experiment are separate downstream gates, described here so the engine contract is useful across the complete product. No Cloud event-stream channel is in scope.

## 2. Baseline to preserve

The pre-refactor working tree included:

- [ExecutionRequest](src/Workflow/Executor/ExecutionRequest.php) with start/resume/signal factories and operation keys.
- [WorkflowExecutor](src/Workflow/Executor/WorkflowExecutor.php) with atomic admission, operation recovery, owned execution and fenced settlement.
- [WorkflowRunStore](src/Workflow/Executor/WorkflowRunStore.php), [WorkflowControl](src/Workflow/Executor/WorkflowControl.php), [WorkflowOperation](src/Workflow/Executor/WorkflowOperation.php), and [Ignition](src/Workflow/Executor/Ignition.php).
- [Workflow](src/Workflow/Workflow.php) with eager `run()`, lazy `events()`, and per-invocation `prepare:` callbacks.
- [Agent](src/Agent/Agent.php) convenience methods that already construct execution requests.
- Durable approval/history, concurrent reservation, lost-acknowledgement, streaming and stale-cleanup coverage.

The previous implementation reported 543 focused test cases checked, 7,325 assertions, and 16 Redis-dependent skips. Those are historical baseline results, not verification of this proposed architecture. Current implementation verification is recorded below.

Read the current checkout rather than reconstructing from HEAD. Preserve existing uncommitted work and do not remove tests to make the refactor pass. The package declares PHP `^8.1`; the previous checks ran on PHP 8.4.17. Do not introduce language features above the supported floor accidentally.

Before implementation, read [root instructions](AGENTS.md), [Workflow instructions](src/Workflow/AGENTS.md), [Agent instructions](src/Agent/AGENTS.md), and path-specific guidance for touched components. Follow the workflow/streaming skills and inspect any newly applicable rules.

## 3. Definition of done

- Definition instances do not store adopted run/attempt identity, current state/input, live routing maps, or per-segment resource caches.
- A fresh execution owns these values and does not mutate a previously returned result.
- Context-dependent dependencies are constructed from explicit context; there is no `prepareExecution()` callback that receives and patches Workflow.
- Application convenience methods delegate to the same engine path the SDK uses.
- Admission, leases, receipts, replay, approvals, history behavior, cleanup and output isolation retain their existing guarantees.
- Existing code consumers, generators, examples, and dependent SDK specifications agree on the new contract.
- Focused tests, scoped static analysis, formatting, and selected integration checks have recorded evidence. Unrun integrations are clearly identified.

## 4. Dependency order

```text
P1 Contract and characterization
  -> P2 Request/context and value isolation
  -> P3 Execution runtime and definition construction
  -> P4 Executor and durability boundary
  -> P5 Agent and fluent facade
  -> P6 Control operations and cross-cutting consumers
  -> P7 Documentation and engine verification
  -> P8 Native SDK / Laravel adoption
  -> P9 app-demo integration experiment
```

P3–P5 can be implemented as one coherent local change if intermediate code would not compile. Keep review and verification organized by responsibility rather than retaining two production execution paths. No public release or compatibility bridge is required between these steps.

## 5. Work packages

### P1. Agree the smallest public contract and capture regressions

Affected: [WorkflowInterface](src/Workflow/WorkflowInterface.php), [WorkflowRuntimeInterface](src/Workflow/WorkflowRuntimeInterface.php), [WorkflowExecutorInterface](src/Workflow/Executor/WorkflowExecutorInterface.php), [AgentInterface](src/Agent/AgentInterface.php), and the companion architecture.

Tasks:

1. Inventory public calls and protected hooks used by Agent, RAG, evaluation, observability, console generation, examples, and SDKs. Distinguish configuration access from accidental current-run access.
2. Specify the new context and internal runtime contract. Prefer the existing executor as runner; do not create redundant public runner/handle abstractions.
3. Specify address resolution: explicit request address, declared/bound default, unkeyed starts, conflicts, and generated addresses returned only through execution results.
4. Define the fluent override precedence for instances and context-aware factories. Settle hook signatures and prototype construction semantics; expose only the factory methods required by actual examples.
5. Define when lazy invocations capture input and choose configuration. Remove the configuration-mutation assertion from all setters. Capture completion policy and dispatcher per execution, preserve registered listeners through dispatcher snapshots, and resolve default RAG retrieval per segment. Retain executor checks for overlapping execution and cleanup, plus durable ownership checks.
6. Define detached input/state semantics, including custom mutable object properties and explicitly shared services. PHP readonly fields alone are insufficient.
7. Record exact proposed API signatures and intentional removals for review before implementing new public APIs, as required by root guidance.

Add or adapt meaningful characterization cases:

- Two calls on the same Agent produce results whose metadata and nested state remain independent.
- Two unconsumed streams retain separate message/options input; consuming one does not overwrite the other.
- Constructing a definition or creating a generator performs no runtime-resource work.
- Saved receipts and idle suspension polls call neither graph nor resource factories.
- Original input survives approval continuation despite changed definition defaults.

Gate: ordinary use, worker push streaming, fresh-process approval continuation, and SDK invocation all have concrete code examples using one agreed contract. Tests expose the current ownership defects instead of asserting that current internals must remain.

### P2. Establish explicit requests, context and detached values

Affected: [ExecutionRequest](src/Workflow/Executor/ExecutionRequest.php), [Ignition](src/Workflow/Executor/Ignition.php), [WorkflowState](src/Workflow/WorkflowState.php), [AgentState](src/Agent/AgentState.php), [AgentStartEvent](src/Agent/Events/AgentStartEvent.php), [AgentRunOptions](src/Agent/AgentRunOptions.php), serializers, and new ExecutionContext in the existing Workflow namespace layout.

Tasks:

1. Extend request addressing for unbound/reconstructed callers while preserving bound-definition convenience. Freeze the inspected workflow address in translated-input requests.
2. Snapshot start input when an invocation is created. Later mutation of caller-owned messages/options must not alter a queued request or its fingerprint.
3. Create ExecutionContext only from admitted authoritative records: workflow ID, run ID, execution attempt, original start input, and durable domain context. SDK delivery metadata is not engine context.
4. Expose original input without a mutable alias that can rewrite durable intent. Keep traversal's working event separate from the context's input snapshot.
5. Turn configured initial state into a seed/recipe. Produce a fresh state per run and a restored independent state per continuation. Preserve custom state subclass behavior and branch cloning contracts.
6. Preserve fingerprint normalization, including Agent message display IDs. Keep fingerprinting a pure function of declared operation/input rather than a mutation of Workflow.
7. Keep runtime services, factories, callbacks and credentials out of serialized records.

Gate: conflicting addresses reject before persistence; reserved identity is not prematurely advertised as active; requests/results remain independent under nested mutation; custom state tests demonstrate the chosen copy contract. Persistence record changes are unnecessary unless proven otherwise.

### P3. Introduce an execution runtime and explicit construction boundary

Affected: [Workflow](src/Workflow/Workflow.php), [WorkflowRuntimeInterface](src/Workflow/WorkflowRuntimeInterface.php), [HandleComponents](src/Workflow/HandleComponents.php), [ResolveState](src/Workflow/ResolveState.php), [HandleMiddleware](src/Workflow/HandleMiddleware.php), and new internal WorkflowExecution.

Tasks:

1. Implement the reduced runtime interface on WorkflowExecution. Move current state, graph map/node resolution, runtime dependencies, restoration and stream delivery there.
2. Remove `Workflow implements WorkflowRuntimeInterface` once the executor can consume the runtime directly.
3. Add a definition construction boundary taking ExecutionContext and returning execution-owned components. Protected graph/resource hooks produce objects rather than modify a current execution on the definition.
4. Keep configured node instances as prototypes. Establish fresh-instance behavior for node fields and middleware; use explicit factories for uncloneable/stateful resources rather than serializing arbitrary object graphs.
5. Move resource memoization from definition getters to the runtime. Configuration values remain on the definition; resolved per-segment values do not.
6. Move stream adaptation and channel lifecycle to execution scope. Preserve channel failure isolation and exception settlement ordering.
7. Ensure runtime teardown does not need to reset run identity or input on the definition.

Gate: graph construction receives complete context; one execution's nodes/resources cannot overwrite another's runtime; zero-output nodes still receive correct stream lifecycle; stateful configured prototypes are not accidentally executed as shared live objects.

### P4. Retarget executor admission and traversal

Affected: [WorkflowExecutor](src/Workflow/Executor/WorkflowExecutor.php), [AsyncExecutor](src/Workflow/Executor/AsyncExecutor.php), [WorkflowExecutorInterface](src/Workflow/Executor/WorkflowExecutorInterface.php), [WorkflowRunStore](src/Workflow/Executor/WorkflowRunStore.php), and [NodeContext](src/Workflow/NodeContext.php).

Tasks:

1. Resolve address, serializer/persistence policy, canonical start input, and operation fingerprint from request plus definition configuration without bootstrapping a graph.
2. Keep the current atomic admission and control-store machinery. Saved outcomes and idle polls return persisted data before runtime construction.
3. After a successful claim, construct ExecutionContext and WorkflowExecution. No call to Workflow `adoptIdentity()`, `adoptIgnition()`, or `prepareExecution()` remains.
4. Pass the runtime into traversal and branch execution. Keep the event/state node API and step-bound NodeContext/memoizer where possible.
5. Allocate execution-specific executor/store/branch state per invocation. Preserve same-facade overlap policy through an engine-owned usage gate shared across invocations of that facade, separate from durable ownership.
6. Record construction/bootstrap failures under the acquired fence. Refused or stale requests must not run factories or mark the current owner failed.
7. Preserve startup, suspension, failure and completion ordering. An adapter startup failure must not advance an unstarted node generator while trying to settle it.
8. Preserve generator abandonment semantics and AsyncExecutor's draining of already-running branches. Never replace conditional cleanup with unconditional deletion.
9. Remove duplicate lifecycle implementations once the new path works. There is one admission and execution path for standalone and managed use.

Gate: existing lost-ack, duplicate-operation, concurrency, lease, stale-attempt, branch-order, step/memo replay and cleanup tests pass through the new runtime. A stopped generator releases local resources without manufacturing durable completion.

### P5. Preserve Agent/Workflow convenience and isolate composition resources

Affected: [Workflow](src/Workflow/Workflow.php), [Agent](src/Agent/Agent.php), [HandleProvider](src/Agent/HandleProvider.php), [HandleTools](src/Agent/HandleTools.php), [HandleInstructions](src/Agent/HandleInstructions.php), Agent nodes, and RAG composition.

Tasks:

1. Keep `make()`, subclassing, fluent configuration, `run()` and `events()`. They delegate with a definition and request and never adopt execution facts back onto the facade.
2. Keep Agent `chat()`, `stream()` and `structured()` as request-building conveniences. `run()` remains eager and state-returning; `events()`/`stream()` remain lazy generators for all channel configurations.
3. Resolve history with the admitted conversation address. A definition's configured default conversation is not mutable run identity. Validate pre-bound history conflicts before writing to the wrong conversation.
4. Preserve useful explicit `setChatHistory()` behavior between interactions as deliberate configuration. Do not let discovering history during graph construction silently choose a different execution address.
5. Move tool expansion caches, provider request assembly and toolkit-derived instructions into the execution. Ensure instructions/tools do not accumulate changes across turns.
6. Restore executable tool registries through the Agent runtime after resource construction. Saved outcomes remain passive data.
7. Preserve normal conversation continuity across separate runs: history is intentionally shared by conversation; execution state and run IDs are not.
8. Support context-dependent adapter/channel/provider/tool/history construction without a generic Workflow-mutating callback. Configure resource recipes before invocation.
9. Remove the `prepare:` terminal argument, preparation flags, identity/input checksum guard around the callback, and definition runtime getters after their responsibilities have moved. Retain the normal operation fingerprint and durability checks.
10. Keep reset/abandon semantics around unanswered tool calls; application-level history policy remains Agent-specific rather than leaking into the generic executor.

Gate: the architecture's fluent examples run unchanged in spirit, including configured services and custom subclasses. Same-Agent consecutive turns preserve old results; fresh-process stream/structured/approval continuation uses the recorded mode and correct history. No Cloud SDK dependency enters Neuron AI.

### P6. Update control operations and cross-cutting consumers

Affected: Workflow inspection/translation/cleanup methods, [WorkflowRunSnapshot](src/Workflow/WorkflowRunSnapshot.php), observability events/listeners, graph exporters, [Conversation](src/Evaluation/Conversation/Conversation.php), console stubs, RAG, and frontend translators/examples.

Tasks:

1. Keep native approval/tool-result helpers returning fenced requests. They must not require a live execution, graph, or provider.
2. Make inspection/acknowledgement/abandonment use explicit or bound addresses and exact observed fences. Unbound callers use identity returned by a prior result.
3. Remove consumers of a definition's last run/state. Supply execution metadata to observability explicitly while retaining definition class/identity where useful for presentation.
4. Validate node/event observers and event source semantics; do not leave monitoring attached to a definition with misleading execution values.
5. Audit graph export. Static export must not start a run; execution-dependent graph previews require explicit context/input and must not claim persistence ownership.
6. Update evaluation's approval loop, custom workflow/Agent compositions, and generated code to use the revised public contract.
7. Update stream-consuming endpoints and examples to consume generators explicitly; retained outcomes should be read from results, not mutable Agent fields.

Gate: monitoring, export, evaluation, and native/frontend approvals work without definition execution fields. Read-only control paths do not construct runtime dependencies or write history. No unrelated API changes are bundled in this phase.

### P7. Align documents and verify the engine boundary

Affected: the two new documents, earlier local plan, [workflow idempotency guide](docs/workflow-idempotency.md), module AGENTS files, workflow/agent/streaming/approval/frontend skills, and public examples.

Tasks:

1. Replace old preparation and current-run examples with context-aware resource construction. Mark superseded implementation notes as historical rather than leaving competing authoritative contracts.
2. Document state/input ownership, instance/factory lifetimes, lazy-call timing, same-facade concurrency policy, and deliberate breaking changes.
3. Include minimal examples for ordinary Agent use, custom Workflow, worker push streaming, fresh-process approval, unbound-address recovery, and SDK invocation.
4. Run only affected tests, appropriate scoped PHPStan, and targeted formatting under repository conventions. Do not run the full repository suite/static analysis automatically.
5. Verify independent-process reservation races and loss-of-acknowledgement recovery, not merely same-process mock call ordering.
6. Record actual PHP/database/Redis coverage. Do not present skipped integrations or the PHP 8.1 runtime matrix as verified.

Gate: the source and documented API agree; removed methods have no production callers; engine regression matrix below passes or has explicitly identified environment-only gaps. SDK adoption can begin against a concrete contract.

### P8. Adopt the contract in the first-party SDKs

References: [native SDK plan](../neuron-cloud-sdk/LOCAL_ORCHESTRATION_IMPLEMENTATION_PLAN.md), [Laravel SDK plan](../neuron-cloud-laravel/LOCAL_ORCHESTRATION_IMPLEMENTATION_PLAN.md), and [Cloud platform plan](../neuron-cloud/LOCAL_ORCHESTRATION_IMPLEMENTATION_PLAN.md).

Native SDK work:

1. Reconcile the existing orchestrator proposal before implementing it. `configureExecution(Workflow, context): void` must not survive as a generic mutation callback into an executing Workflow.
2. Keep manifest/registration pure. Resolve a configured definition, map/freeze business input into an engine request, and invoke the common eager engine path.
3. Configure context-aware resource recipes from the orchestrator. Persist only required data for retries; never persist live resource factories or use a callback to repair run/thread identity.
4. Use reserved workflow/run identity and stable delivery operation keys. Keep SDK delivery context separate from authoritative engine ExecutionContext.
5. Preserve report-only recovery, delivery receipts, address ownership, and exact-generation finalization. Persist translated answers and their observed fences before retryable execution.
6. Assert that resume processing cannot accidentally call Agent `chat()` and start a new turn.

Laravel work:

1. Resolve orchestrators/definitions and application services through the container.
2. Reconstruct execution recipes per worker invocation; do not share a singleton runtime with current state or live output adapters.
3. Delegate command processing, admission and reporting to the native SDK rather than implementing a Laravel-specific execution lifecycle.
4. Update package bindings, queue processing and examples to the actual new signatures.

Cloud work:

1. Check command/report fields and reservation/finalization invariants against the SDK contract.
2. Change the wire contract only for a demonstrated missing capability, not because the engine's internal class layout changed.
3. Keep all changes unversioned while unreleased. Do not add Cloud stream channels.

Gate: SDK integration tests demonstrate the same engine guarantees as direct invocation, including lost reports, duplicate deliveries, setup failure and stale approvals. Each repository's local implementation plan is updated with its concrete changes before work there starts.

### P9. Verify the app-demo experience

Use the existing BIAgent, dedicated NeuronCloud controller/view, and application-managed real-time channel.

1. Start a Cloud-managed chat and observe provider chunks on the existing frontend channel.
2. Send a second message in the same conversation: same history/address, different run generation, independent results.
3. Suspend for tool approval, reconstruct execution in a new worker/process, answer the exact pending request, and complete without resending the original question.
4. Retry a delivery and a lost completion report: no duplicated committed work or replayed output frames.
5. Refuse a stale approval and stale cleanup without disturbing the newer attempt/run.
6. Confirm application conversation storage contains domain/UI information rather than a workaround that bridges incomplete engine initialization phases.

Use deterministic providers/tools first, then a bounded live experiment only when the environment is configured. This is a focused integration experiment, not an instruction to run the entire app-demo test suite.

Gate: the integration is understandable from the application code, works across process boundaries, and no longer needs the old Workflow preparation callback. Record which live and simulated cases were actually executed.

## 6. Focused verification matrix

| Concern | Existing starting points | Required evidence |
| --- | --- | --- |
| Result/input isolation | [AgentManagedExecutionTest](tests/Agent/AgentManagedExecutionTest.php), [WorkflowExecutionIntentTest](tests/Workflow/WorkflowExecutionIntentTest.php) | Previous result unchanged after another run; deep message/options and custom state isolation; separate queued streams |
| Ownership-before-construction | [WorkflowManagedExecutionTest](tests/Workflow/WorkflowManagedExecutionTest.php) | Context available to factories; no factories for refusals/saved outcomes/idle polls; setup failure fenced |
| Operation durability | [WorkflowIdempotencyTest](tests/Workflow/WorkflowIdempotencyTest.php), [AgentIdempotencyTest](tests/Agent/AgentIdempotencyTest.php) | Same-key replay/recovery, changed input rejection, receipt cleanup boundaries |
| Cross-process initialization | [ReservedGenerationConcurrencyTest](tests/Workflow/Persistence/ReservedGenerationConcurrencyTest.php), [PersistenceContractTest](tests/Workflow/Persistence/PersistenceContractTest.php) | One reserved generation wins; no losing receipt; supported backend atomicity |
| Stale workers and abandonment | [WorkflowLeaseTest](tests/Workflow/WorkflowLeaseTest.php), [WorkflowSegmentOverlapTest](tests/Workflow/WorkflowSegmentOverlapTest.php), [AgentAbandonTest](tests/Agent/AgentAbandonTest.php) | Refusal/claim/cleanup rules unchanged; local usage guard separate from persisted ownership |
| Resume and tools | [AgentInputSubmissionTest](tests/Agent/AgentInputSubmissionTest.php), [ToolResolutionTest](tests/Agent/Nodes/ToolResolutionTest.php), [StateRestorationTest](tests/Workflow/Executor/StateRestorationTest.php) | Frozen answer/address/attempt, authoritative original intent, runtime tool rehydration |
| Conversation continuity | [AgentDurableHistoryTest](tests/Agent/AgentDurableHistoryTest.php), [ThreadIdentityTest](tests/Agent/ThreadIdentityTest.php) | History shared only by intended conversation; definition never adopts execution identity |
| Stream lifecycle | [ChannelForwardingTest](tests/Workflow/Channel/ChannelForwardingTest.php), [StreamFailureDeliveryTest](tests/Workflow/Channel/StreamFailureDeliveryTest.php), [PushAdapterDeliveryTest](tests/Agent/PushAdapterDeliveryTest.php) | Lazy/eager contract, zero chunks, startup failure, suspension, transport isolation, no saved-frame replay |
| Parallel execution | [AsyncExecutorTest](tests/Workflow/Executor/AsyncExecutorTest.php), [ParallelInterruptTest](tests/Workflow/Executor/ParallelInterruptTest.php) | Branch state, accepted-input priority, deferred interruptions and drain semantics |
| Cross-cutting behavior | [WorkflowContinuationReportingTest](tests/Observability/WorkflowContinuationReportingTest.php), [ConversationTest](tests/Evaluation/Conversation/ConversationTest.php) | Correct runtime identity and lifecycle reporting without querying a mutable definition |

Extend these tests or introduce narrowly named cases for missing behaviors. Avoid tests that merely count newly introduced internal method calls.

## 7. Main implementation risks and responses

| Risk | Response / acceptance condition |
| --- | --- |
| Moving fields but leaving runtime mutation in a cloned definition | Workflow no longer implements the runtime contract; tests inspect definition reuse and independent outcomes. |
| Readonly fields still alias mutable messages/options/state | Explicit data-copy/view contract; mutate nested originals and later results in tests. |
| Shared adapters, tools, middleware or node prototypes leak segment state | Execution-local resolution and tested copy/factory semantics; no unsupported concurrency promise. |
| A bound history silently changes the persistence address | Explicit address reconciliation before effects; context binds history, not the reverse during graph construction. |
| Admission/configuration cycle reappears in SDK integration | Definition construction remains pure; recipes receive engine context and return resources; no callback receives Workflow to patch. |
| Ownership is released or marked completed on generator destruction | Preserve CAS/lease lifecycle and distinguish local cleanup from durable state transitions. |
| Observability or export depends on removed current-run getters | Audit consumers explicitly in P6; use execution metadata/context rather than a hidden last-run pointer. |
| Large uncommitted tree is accidentally overwritten | Baseline changed files before edits; preserve existing work; review incremental diffs; no reset/clean or wholesale replacement. |

## 8. Completion checklist

- [x] P1 public contract and characterization cases agreed.
- [x] P2 request/context/value isolation complete.
- [x] P3 runtime separated from definition.
- [x] P4 one engine admission/execution path retains durability guarantees.
- [x] P5 familiar Agent/Workflow experience preserved without current-run fields.
- [x] P6 control, observability, export and composition consumers migrated.
- [x] P7 engine documentation and focused verification complete.
- [ ] P8 native/Laravel SDK contracts and implementations adopted.
- [ ] P9 app-demo continuity, approval and streaming experiment verified.

P1–P7 cover this Neuron AI change. P8–P9 remain separate downstream work; neither SDK adoption nor the live app-demo experiment is claimed complete.

## 9. Verification recorded on the runner branch

The engine work is implemented in this working tree. The public convenience methods
remain; the intentionally breaking resource/graph hook contracts are recorded in the
architecture's implemented-contract section and module instructions. Existing
uncommitted durability work was retained.

Verified on PHP 8.4.17:

- 1,003 selected cases across Workflow, Agent, observability, conversation evaluation,
  RAG nodes/composition, console generation and core Agent convenience tests: 954
  passed, 49 skipped, 9,570 assertions, no failures/errors/warnings.
- Skips cover unconfigured Redis/MySQL integration services, unavailable PostgreSQL
  PDO and igbinary extensions. SQLite coordination and independent-process reservation
  tests ran. These results do not claim a PHP 8.1 runtime matrix or external-service coverage.
- Scoped PHPStan passed for Workflow, Agent, affected RAG composition/resolver files,
  observability, conversation evaluation and console generation.
- PHP CS Fixer ran on the PHP files changed in this implementation.
- Isolation regressions cover nested request data, independent results and custom
  state fields, cloned node prototypes, explicit unbound-address continuation/cleanup,
  address conflicts, transport-free export, and failed-construction lifecycle metadata.

No SDK implementation, Cloud protocol change, Cloud streaming service, or live
app-demo experiment was performed in this stage. P8 and P9 are still open.
