# Neuron AI: local durable orchestration implementation plan

> Historical implementation record. Its mutable preparation design is superseded by [the execution architecture](WORKFLOW_EXECUTION_ARCHITECTURE.md) and [implementation plan](WORKFLOW_EXECUTION_IMPLEMENTATION_PLAN.md). Use the current module instructions for executable APIs.

Status: simplified engine invocation contract implemented; native SDK adoption pending.
Compatibility: direct breaking API changes; no compatibility adapters or versioned protocol.
Repository review: 2026-09-22, HEAD `df30064d` (`tool inputs cast consistency`),
including the current uncommitted workflow/idempotency implementation.

## 1. Relationship to the global plan

The [global plan](../neuron-cloud/ORCHESTRATION_IMPLEMENTATION_PLAN.md) defines the
first-party orchestration goals. This document implements its engine prerequisites
(section 5, milestone M2) and specifies the engine guarantees consumed by the
[native PHP SDK](../neuron-cloud-sdk/LOCAL_ORCHESTRATION_IMPLEMENTATION_PLAN.md).
The [Cloud platform](../neuron-cloud/LOCAL_ORCHESTRATION_IMPLEMENTATION_PLAN.md)
and [Laravel SDK](../neuron-cloud-laravel/LOCAL_ORCHESTRATION_IMPLEMENTATION_PLAN.md)
remain responsible for reservation, transport, admission, and queue integration.

Neuron AI supplies generic workflow capabilities: stable identity, atomic execution
initialization, durable intent, safe runtime preparation, and fenced cleanup.
Cloud delivery envelopes, delivery reports, request receipts, orchestrator registration,
Laravel containers, and schedulers do not belong in the engine.

Streaming remains local through existing adapters and channels. No Cloud stream,
new channel infrastructure, stream replay store, or mandatory Pusher dependency
is planned. Preserve ordinary standalone workflow and agent usage.

Before implementing each step, recheck the working tree, affected source, module
instructions, and dependent SDK contract. Update this baseline when it changes;
mark a gate complete only with actual verification evidence.

## 2. Current project status

### Working tree and dependencies

- Existing modified files include Agent/Workflow classes and interfaces, executor,
  ignition, control, run store, module guidance, and persistence tests.
- `WorkflowOperation`, workflow/agent idempotency tests, a crash fixture, and
  `docs/workflow-idempotency.md` are untracked. They are part of the current working
  implementation. Preserve and extend them; do not replace this checkout with HEAD.
- Declared runtime floor is PHP `^8.1`; the engine has no Cloud or Laravel runtime
  requirement. Installed tools include PHPUnit 10.5.64 and PHPStan 2.2.14;
  optional development dependencies include Illuminate Database 12.69.2 and
  Pusher PHP SDK 7.3.0, as inspected with Composer.
- Follow [root guidance](AGENTS.md), [Workflow guidance](src/Workflow/AGENTS.md),
  [Agent guidance](src/Agent/AGENTS.md), and the repository's workflow/streaming skills.
  Use strict types, protected non-public members, and focused tests/static checks.
- This document records source analysis, not a successful test run. No suite,
  live model call, migration, or runtime change was performed while writing it.

### Existing foundation and concrete gaps

| Area | Current implementation | Required treatment |
| --- | --- | --- |
| Identity | [Workflow](src/Workflow/Workflow.php) exposes explicit/adopted `getWorkflowId()` and a separate declared `workflowId()` hook; executor reconciles them at execution. [Agent](src/Agent/Agent.php) declares its thread through the hook. | Resolve an already-declared address before SDK admission without executing or requiring duplicate constructor arguments. |
| Generation | [WorkflowExecutor::startRun](src/Workflow/Executor/WorkflowExecutor.php) generates a `run_*` ID and initializes control/ignition. | Accept an optional externally reserved generation through a generic start intent and bind it atomically. |
| Idempotency | [WorkflowOperation](src/Workflow/Executor/WorkflowOperation.php) and [WorkflowRunStore](src/Workflow/Executor/WorkflowRunStore.php) retain input fingerprints, ignition, and outcomes within the run. | Include reserved generation in operation identity and preserve existing CAS/recovery guarantees. |
| Intent | `Ignition` retains the start event/context; `AgentStartEvent` and `AgentRunOptions` describe messages and inference mode. | Expose inert start preparation without making the SDK invoke eager convenience methods or write engine internals. |
| Setup | Executor adopts identity/ignition, claims execution, and builds the graph; no external generic preparation boundary exists. | Configure per-segment dependencies after authoritative identity/intent adoption and ownership, before graph construction/output. |
| Streaming | `forwardEvents()` resets/starts the adapter before iterating the executor generator; `events()` checks adapter/channel before that iteration. | Move preparation ahead of first frame and account for resources installed by preparation. |
| Terminal state | `Workflow::run()` already consumes `events()` and always returns `WorkflowState`; `stream()` may return a generator. | Reuse `run()` for SDK-owned execution with an explicit execution request; avoid a redundant execution terminal. |
| Input | `submitInputs()` translates against persisted interruption and stages run/attempt fences; native approval helpers already exist. | Preserve/reuse these contracts; keep Cloud response-format selection and frozen translation in the SDK. |
| Completion | `retainCompletionUntilAcknowledged()` and `acknowledgeCompletion(runId)` already retain and fence cleanup. | Verify them under reserved generations and address reuse; Cloud finalization stays outside the engine. |

The engine already distinguishes keyed starts from automatic failed-run recovery:
keyed starts refuse existing generations. Preserve this distinction while adding
reserved IDs, rather than introducing a second recovery implementation.

## 3. Required semantics and ownership

| Value | Engine meaning | Integration responsibility |
| --- | --- | --- |
| Workflow ID | Stable persistence partition/business address; agent thread equals this address. | Application chooses it; Cloud reserves it; SDK constructs the addressed workflow. |
| Run ID | One execution generation within that address. | Engine generates it standalone or adopts a caller-reserved value on managed start. |
| Execution attempt | Engine-owned mutation/lease fence. | Callers submit observed fences; they do not choose the next attempt. |
| Idempotency key | One logical engine start/continuation while receipts remain stored. | SDK uses its delivery ID; engine does not interpret it as a Cloud identifier. |
| Ignition | Immutable start event and engine-opaque domain context. | Application composition defines intent; SDK freezes prepared input before execution. |
| Runtime configuration | Transient dependencies for an actual segment. | SDK restores its frozen delivery context and installs local resources. |

No live adapter, channel, provider, closure, container, credential, or connection
is serialized into ignition/checkpoints. Existing executable tool reconstruction
remains transient. Do not store mutable frontend channel context as immutable
engine ignition merely to make it available on resumes.

A reserved run ID is an input to initialization, not evidence that a run exists.
Keep a supplied reservation distinguishable from an initialized/adopted generation;
`inspect()` and execution metadata must not claim execution before initialization.

## 4. Ordered implementation work

### N1. Agree on the smallest engine API — global M1/M2

Review `WorkflowInterface`, `WorkflowRuntimeInterface`, `WorkflowExecutorInterface`,
`AgentInterface`, and the SDK's proposed `StartOperation`/context contract together.

1. Use an explicit ExecutionRequest with typed start events/options with optional reserved
   run ID, rather than adding Cloud-specific setters or another execution terminal.
2. Identify the minimal supported way for the SDK to prepare messages and agent
   options without calling `chat()`, `stream()`, or `structured()` to execute them.
3. Define a generic segment-preparation seam callable by the SDK. Specify when it
   runs, which identity/intent is authoritative, and how setup failures are recorded.
4. Keep `run()` and `events()` as execution terminals where possible. Keep SDK
   `StartOperation` and registration abstractions outside the engine; use existing
   typed engine events/options as the translation target.
5. Record exact signatures before implementation. Root `AGENTS.md` requires newly
   introduced public APIs to be discussed before coding. These capabilities are
   the agreed scope; this plan does not silently approve additional lifecycle APIs.

Gate: SDK and engine agree on identity, inert start preparation, setup ordering,
and terminal behavior. Any necessary new public names are explicit rather than
emerging as incidental methods during the executor refactor.

### N2. Make declared identity available before execution — global M2

Affected: `Workflow`, `WorkflowInterface`, `WorkflowRuntimeInterface`, `Agent`,
`AgentInterface`, and executor identity resolution.

1. Expose one authoritative address when it is already declared or explicit.
   An agent created with `threadId: $id` must be identifiable before execution.
2. Centralize agreement/validation of explicit address, declared address, thread,
   and adopted identity. Contradictions fail before admission or persistence writes.
3. Preserve lazy construction. Do not call overridable identity hooks from the
   base constructor before subclass properties are initialized. Do not bootstrap
   a graph or open history/provider/channel services just to resolve identity.
4. Keep unkeyed standalone workflows valid: unresolved identity can remain null
   until the existing generated-address start path. Managed deliveries require a
   supplied stable address; the SDK enforces that contract.
5. Preserve explicit conversation switching via `setChatHistory()` outside execution.
   Once identity is used for admission/segment ownership, runtime setup cannot
   silently switch conversation or redirect the persistence backend.

Gate: declared-only, explicit-only, agent thread, invalid key, and conflicting-key
cases behave consistently before and during execution; identity inspection has
no execution/persistence-write side effects.

### N3. Adopt reserved generations atomically — global M2

Affected: executor start/continuation paths, executor interface, `WorkflowRunStore`,
`WorkflowOperation`, `Ignition`, `WorkflowControl`, and explicit execution requests.

1. Accept an optional caller-reserved run ID for a fresh start. Standalone starts
   without one retain engine generation.
2. Validate reserved IDs using a documented generic grammar compatible with run
   storage keys; do not assume arbitrary path separators or reserved keys are safe.
3. Include the reservation in the immutable start fingerprint. Same operation key
   with another reservation is a conflict, not a recoverable duplicate.
4. Initialize control, ignition, and keyed operation binding through the existing
   atomic store mutation. Never publish a run ID separately from its durable records.
5. A retry of a claimed but unfinished operation uses its recorded ignition/run ID.
   A duplicate with a saved outcome returns that outcome without executing nodes.
6. A reserved fresh start cannot implicitly recover a prior failed run or sweep
   another live generation. Explicit recovery keeps its existing run ID with a
   new logical operation key; replacement requires settling/abandoning old state
   through the documented, fenced path.
7. Preserve lease and attempt fencing. Do not introduce a second lock, global
   execution registry, permanent deduplication table, or new persistence backend API
   unless existing CAS primitives demonstrably cannot express the required mutation.

Gate: concurrent starts/resumes, changed reservation under one key, crashes around
initialization, retained completion, expired lease, and explicit failed-run recovery
all preserve one authoritative generation.

### N4. Add preparation at a safe execution boundary — global M2

Affected: `WorkflowExecutor::executeSegment`, `continueRun`, `Workflow::bootstrap`,
runtime interface, and Agent restored-dependency handling.

Required order for an actual execution segment:

1. Validate/stage operation and resolve the addressed durable generation.
2. Validate accepted response and acquire execution ownership/attempt fence.
3. Adopt authoritative identity and immutable ignition intent.
4. Invoke generic runtime preparation with that identity/intent available.
5. Build nodes and reattach transient dependencies to recalled state as needed.
6. Emit stream-start/lifecycle output and execute nodes under the same ownership.

Detailed work:

- Reconcile this order with current `continueRun()`, which adopts ignition before
  claiming execution. Do not run application preparation merely because a caller
  inspected/adopted a record; only an actual owned segment can invoke it.
- State restoration is not one eager operation today: checkpoints/step outcomes
  are recalled as execution needs them. Do not pre-load every record. Ensure each
  `restoreState()` uses the configured dependencies when it is recalled.
- `Agent::restoreState()` rebuilds tools. Input-dependent tool setup must be installed
  before that rebuild and graph construction, without overwriting accepted durable
  request options/messages. Review early-return paths separately.
- Preserve original ignition on resume/retry. Current `adoptIgnition()` can keep an
  already-set local start event; ensure stale local preparation cannot override a
  persisted managed continuation. A new turn and a resume remain distinct intents.
- Preparation is transient and may repeat on crash recovery. It must not execute
  business actions, change persistence/address/run identity, or replace durable
  start intent. Add post-preparation identity checks where required.
- A preparation exception after ownership is acquired must leave an inspectable,
  fenced failure/recovery outcome. A stale/unclaimed caller cannot fail another
  worker's run. Distinguish setup failure from channel delivery's nonfatal policy.
- Saved-outcome/report-only paths do not require live channel/provider setup.
  SDK reconciliation should bypass execution entirely when its ledger has a report.

Gate: fresh-process resumes, caught setup errors, stolen/expired leases, duplicate
outcomes, and restored tool registries behave consistently, without business execution
before setup or mutation by a losing caller.

### N5. Correct streaming initialization and reuse the eager terminal — global M2

Affected: `Workflow::events`, `streamExecution`, `run`, Agent forwarding/start methods,
and relevant configuration traits/interfaces.

1. Move adapter reset/start so it runs after N4 preparation and before the first
   node output. Merely advancing the executor until its first node yield is too late:
   a node can already call a provider or complete without yielding.
2. Make `events()` consistently lazy and `run()` consistently eager. Resolve output
   resources after preparation without generator priming or a synthetic marker.
3. Expose inert agent-start requests using existing `AgentStartEvent`/`AgentRunOptions`
   for messages, stream intent, structured output, and retry count. Convenience
   methods should construct the same request before their terminal call.
4. Have the SDK execute a request carrying the operation key with `run($request)`, reusing its
   existing guaranteed terminal-state return. Streaming inference is selected by
   durable intent, not by whether the caller iterates a generator.
5. Use lazy `events()`/`stream()` and explicit eager `run()` for channel delivery.
   Verify zero-yield workflows, suspension/error terminals, generator abandonment,
   and same-instance overlap guards after changing initialization order.
6. Preserve channel isolation and `ChannelError`: transport failures do not fail
   the workflow. No subscriber wait, automatic frame replay, or exactly-once output
   guarantee is added. Protocol adapters/channels remain fresh per concurrent segment.

Gate: recording adapter/channel fixtures show restored identity and configured
resources before the first frame; eager calls always execute fully, and standalone
pull streams retain their expected contract.

### N6. Verify approval, history, and cleanup invariants — global M2/M6

Affected only where needed: input submission/translation, Agent history restoration,
completion acknowledgement, and their existing tests.

1. Reuse `submitInputs`, `InputTranslatorInterface`, native approval/tool-result
   helpers, and persisted interruption validation. The SDK freezes translation;
   do not add a Cloud-specific approval API inside the engine.
2. Semantic rejection must not claim/consume the pending response or execute a
   protected action. Recheck run/attempt fences at execution after translation.
3. Run successive turns with one thread/address and different reserved run IDs.
   Verify history writes remain durable and completion cleanup does not erase chat
   history or require copying it into a new thread.
4. Reuse existing retained completion and `acknowledgeCompletion(expectedRunId)`.
   Prove delayed old cleanup cannot delete a newly initialized generation.
5. Keep engine operation receipts scoped to retained run records. Post-cleanup
   deduplication and the report/finalize handshake belong to Cloud/SDK ledgers.
6. Exercise sequential and async executors where the shared lifecycle changes;
   preserve deferred interruption order and accepted input ownership.

Gate: approve/deny, stale input, two turns, process restart, and delayed cleanup
satisfy the SDK contract without new pointer tables or application history copying.

### N7. Update guidance and hand off a verified revision — global M2/M7

1. Update Workflow/Agent API docs and module guidance for any agreed signature or
   identity-timing change; update `docs/workflow-idempotency.md` for reserved IDs.
2. Update workflow/agent/streaming skill examples only where behavior changes.
   Document generic caller-managed execution, without teaching Cloud internals as
   engine concepts. Keep standalone examples minimal.
3. Record exact engine revision and focused checks required by the native SDK.
   Existing SDK path dependencies may see edits immediately; coordinate worker
   restarts and avoid interpreting old records with incompatible new code.
4. Hand the native SDK the finalized staging/preparation interfaces and failure
   semantics. Complete the real integration with the SDK after its runner is ready.

## 5. Focused verification matrix

| Existing tests | Required cases |
| --- | --- |
| `tests/Workflow/WorkflowIdentityTest.php`, `tests/Agent/ThreadIdentityTest.php` | Address before execution, conflict validation, no lazy-service side effects, history switching. |
| `tests/Workflow/WorkflowIdempotencyTest.php`, `tests/Agent/AgentIdempotencyTest.php` | Reserved generation fingerprints, same-key recovery, changed-key/reservation conflict, saved outcomes. |
| `tests/Workflow/WorkflowLeaseTest.php`, `tests/Workflow/WorkflowSegmentOverlapTest.php` | Setup under ownership, expired lease, overlap rejection, stale worker cannot commit. |
| `tests/Workflow/WorkflowExecutionIntentTest.php`, `tests/Agent/InferenceIntentTest.php` | Staged chat/stream/structured intent, persisted intent wins on resume, no cross-turn leakage. |
| `tests/Workflow/WorkflowStreamingTest.php`, `tests/Workflow/Channel/ChannelForwardingTest.php` | Setup/reset/start order, runtime-installed adapter/channel, eager/lazy contracts, zero-yield execution. |
| `tests/Workflow/Channel/StreamFailureDeliveryTest.php`, `tests/Workflow/Channel/StreamSuspensionDeliveryTest.php` | Error/suspension terminals, transport isolation, same-instance reuse. |
| `tests/Workflow/WorkflowInputSubmissionTest.php`, `tests/Agent/AgentInputSubmissionTest.php`, `tests/Agent/Nodes/ToolApprovalFlowTest.php` | Correctable invalid responses, exact fences, approval and rejection, fresh-process continuation. |
| `tests/Agent/AgentDurableHistoryTest.php` | Stable-thread multi-turn history, retained completion, delayed cleanup. |
| `tests/Workflow/Executor/AsyncExecutorTest.php`, `tests/Workflow/Executor/AcceptedResumeInputTest.php` | Shared lifecycle ordering and accepted/deferred interruption safety. |

Extend persistence contract/backend tests only if initialization/cleanup mutations
change; preserve their existing uncommitted additions. Use fake providers and
recording channels. No live provider or new Pusher server is needed to verify the
engine boundary. Use supported multiprocess persistence for ownership/crash tests;
InMemory/File persistence alone cannot establish worker-farm safety.

Run `vendor/bin/phpunit` with the affected test file or filter, plus narrowly scoped
static/style checks. Follow the repository instruction not to run the full suite
or full static analysis. This document itself requires link/content checks only.

## 6. Completion and handoff

- [x] N1 engine signatures and ordering are documented for SDK adoption.
- [x] N2 stable identity is available before managed admission.
- [x] N3 caller-reserved generation is bound atomically and recovered unchanged.
- [x] N4 setup runs under ownership before graph/resources are consumed.
- [x] N5 eager execution and streaming lifecycle use restored identity and intent.
- [x] N6 approval/history/cleanup invariants pass focused regressions.
- [x] N7 documentation and exact dependency revision are handed to the native SDK.
- [ ] Native SDK and app-demo confirm global M6 continuity/approval scenarios.

Engine completion means generic primitives are implemented and verified. Full
product completion still requires Cloud reservation/finalization and the native/
Laravel SDK lifecycle; no engine-only patch can replace those coordination changes.


## 7. Implemented API and handoff — 2026-09-22

Engine source baseline: `df30064d` plus the current uncommitted working tree.
The pre-existing idempotency changes were preserved and extended. No package
version, dependency update, protocol version, or compatibility branch was added.
SDK path dependencies already see this checkout; a released/tagged dependency
revision has not been created.

### Public integration primitives

```php
ExecutionRequest::start(?Event $event = null, ?string $runId = null, ?string $idempotencyKey = null, bool $recoverFailed = false): self;
ExecutionRequest::resume(?array $payload = null, ?string $expectedRunId = null, ?int $expectedExecutionAttempt = null, ?string $idempotencyKey = null): self;
ExecutionRequest::signal(string $event, array $payload = [], ?string $expectedRunId = null, ?int $expectedExecutionAttempt = null, ?string $idempotencyKey = null): self;
Workflow::run(?ExecutionRequest $request = null, ?Closure $prepare = null): WorkflowState;
Workflow::events(?ExecutionRequest $request = null, ?Closure $prepare = null): Generator;
```

`ExecutionRequest` lives in `NeuronAI\Workflow\Executor`. Its read-only fields
replace pending start/resume/signal state on Workflow. The callback receives the
concrete workflow, belongs only to this invocation, and returns void. Input
translation helpers return a fenced request, optionally carrying an idempotency key.

```php
$request = ExecutionRequest::start(new AgentStartEvent(
    messages: $messages,
    options: new AgentRunOptions(stream: true),
), runId: $identity->runId, idempotencyKey: $deliveryId);
$state = $agent->run($request, prepare: $configureSegment);
```

This directly replaces the earlier staged API: Workflow `start()`, `resume()`,
`signal()` and `prepareExecutionUsing()` are removed. No compatibility wrappers
or SDK trait are introduced. The SDK's orchestrator builds requests, supplies
runtime configuration, consumes execution and handles Cloud reporting.

An explicit workflow ID now also supplies Agent thread identity. Declared identity
is visible before admission; unkeyed workflows remain lazy and unresolved. The
reserved run ID is validated but stays separate from initialized/adopted identity.
Its grammar is 1–128 ASCII letters/digits/underscores/hyphens, with a letter or
digit first. Atomic initialization persists control, ignition, and any keyed
operation binding together. The reservation participates in the fingerprint.
Reserved starts never replace/recover another generation implicitly. The same key
with another reservation is rejected; a saved duplicate returns its own outcome.

### Segment ordering and SDK obligations

1. Configure persistence, serializer, executor, lease, and completion retention
   before execution. Build the typed start or exact fenced continuation request.
2. The executor resolves/adopts recorded intent, validates input and claims the
   attempt. Only an owned segment invokes preparation. Read-only inspection,
   invalid input, live-lease refusal, and saved outcomes do not invoke the hook.
3. Preparation sees the run ID, attempt metadata, thread, and persisted start
   messages/options. It installs providers/tools, same-thread history, and local
   adapters/channels. It cannot redirect storage/identity, change durable input,
   execute another operation, or change lease/retention policy under ownership.
4. Recalled start-event dependencies are restored after preparation; graph
   construction and recalled step-state tool restoration use the configured
   resources. Only an owned, prepared segment enters the stream wrapper, which
   resets/starts the adapter before traversal. No marker event is required.
5. Setup failures become fenced failed outcomes. SDK failure reports use the
   actual engine attempt if initialization occurred, even when no node ran.
   Deliberate recovery uses a new key plus a resume request with observed run/attempt.
6. The transient execution guard is released when the executor settles or its
   generator is destroyed. Existing lease/CAS fencing remains authoritative.

`events()` always returns a lazy generator; iteration starts admission, preparation
and execution, and also delivers to any configured channel. `run()` always consumes
that generator and returns state. Agent `stream()` uses the same lazy contract;
`chat()` and `structured()` remain eager. Saved outcomes return through the generator
without configuration or frames. There is no dynamic return type or generator priming.

Executor `execute(workflow, request, prepare)` separates operation admission,
owned traversal/settlement, and failure handling. The ten-argument segment method
and mutable workflow staging flags are removed. Atomic persistence semantics,
operation receipts, leases, run/attempt fences and exact-generation cleanup remain.

Fenced `abandonRun(runId, executionAttempt)` supports explicit replacement without
discarding a newer recovery attempt. It preserves retained-completion/live-lease
refusal and Agent protection against unanswered tool calls. Completion cleanup
still uses exact-generation `acknowledgeCompletion(runId)`. The SDK and Cloud must
retain their own delivery/address ownership through cleanup and finalization;
engine receipts remain bounded by the stored run lifetime.

### Verification evidence

- 543 focused PHPUnit cases checked (7,325 assertions; 16 Redis-dependent cases skipped) across managed execution,
  identity, idempotency, leases/overlap, streaming, approval input, history,
  cleanup, both executors, and persistence contracts.
- Independent PHP processes raced reserved starts through DatabasePersistence on
  a shared SQLite database: one generation won, and no losing receipt was written.
- InMemory, File, Database, and Eloquent contract checks verified reserved IDs and
  receipt lifetime; injected lost initialization acknowledgement recovered the
  same reserved generation.
- Regression cases also cover independent lazy inputs, per-invocation preparation,
  lazy channel delivery, and durable adapter-start failure before node execution.
- Scoped PHPStan analysis and PHP CS Fixer passed for the implementation and new
  test files. The declared PHP 8.1 floor remains; checks ran on local PHP 8.4.17.
- No full repository suite, live provider, Cloud/SDK HTTP run, Laravel queue,
  PHP version matrix, or Redis server test was run for this engine change.

The native SDK can now implement its orchestrator runner against these primitives.
Cross-project integration and app-demo continuity/approval checks remain pending.

Saved outcomes and unanswered checkpoint polls are passive data snapshots: they do
not call `restoreState()` or reconstruct executable tool registries. Actual resumed
steps still restore transient dependencies after segment preparation.
