# Agent Architecture Refactor — Static Graph & Ignition Records

**Status**: Design grilled and ratified 2026-08-09 · **Phases 1–2 IMPLEMENTED
2026-08-09 (uncommitted, verified: `composer test` 1491 green + `composer
analyse` clean) · Phases 3–6 pending** ·
**Branch**: 4.x · **Scope**: neuron-ai framework only (cloud SDK deferred).

> **Supersedes** the previous content of this file (the D4–D7 "thread identity &
> background wakes" plan). That plan patched around the architecture: it persisted
> the Agent's call-time mode in a private record (`__run_context`) and re-injected
> it on resume (D5/D6). This plan removes the reason the record was needed. The
> channel-seam half (`CHANNEL_INTERFACE.md`, Phases 1–3) is **already implemented**
> and is a prerequisite here. Decisions D4 (threadId) and D7 (resolver setters)
> survive into this plan; D5/D6 are replaced.

## The Gap, Rediagnosed

A background wake of an Agent fails today because the workflow graph is composed
inside `chat()`/`stream()`/`structured()` — bare `resume()` on a factory-built
Agent finds no nodes. The prior plan treated the symptom (persist the mode, recall
it, compose late). The root cause is a layering violation:

A fact can live in one of three layers —

1. **Definition** — pure config, reproducible by any factory (provider,
   instructions, tools, parallelToolCalls);
2. **Durable run-state** — persisted, survives the process (steps, chat history);
3. **Instance state** — dies with the process.

The execution mode (chat/stream/structured), the structured output class, and the
thread identity are **run-facts stored as instance state**: needed in layer 2,
held in layer 3. The engine's own invariant — replay-by-rerun requires the graph
to be reproducible from the factory alone — is broken by the Agent because its
graph depends on which method was called on which instance in which process.

## Design Principles

1. **The graph is a pure function of the agent definition.** Composition never
   depends on call-time choices. What varies per run is data, not structure.
2. **Runs are self-describing at the engine level.** Every durable run persists
   its *ignition* — the start event plus run context — so any blank process can
   continue it. (Temporal records workflow inputs in history; Inngest runs *are*
   events. Neuron persisted every step result but not step zero's cause.)
3. **Run-varying facts ride events; events ride the ignition record.** Exactly
   one durable home, no hand-picked scalar copies.
4. **`run()`/`resume()` are the uniform engine verbs** (`WorkflowInterface` is
   untouched) — any handler drives any agent without knowing how it was ignited.

## Vocabulary

- **Ignition** — a run comes into existence: `chat()`/`stream()`/`structured()`
  or bare `run()`. Fresh runId, no persisted past.
- **Wake** — an existing suspended run continues: `resume()`/`wake($payload)`.
- **Ignition record** — the persisted snapshot of how the run was ignited: its
  start event + context bag. Written at first execution, read by wakes.

---

## Decisions

### G1 — Static graph

`Agent::compose()` loses its parameter and composes the same node set on every
path, from `bootstrap()`:

```php
$this->addNodes([
    ...$this->entryNodes(),   // StartNode | RAG's retrieval chain (see G2)
    new ChatNode($this->resolveProvider(), $this->getChatHistory()),
    new StructuredOutputNode($this->resolveProvider(), $this->getChatHistory()),
    $toolNode,   // ToolNode | ParallelToolNode by definition config (see G8)
]);
```

- **`ChatNode` absorbs `StreamingNode`** (which is deleted). Chat vs stream is
  *transport*, not control flow: both memoize the same `'inference' =>
  ProviderResponse` under the same step id. The node branches on the event's
  `stream` intent — `provider->chat()` vs `provider->stream()` + chunk yielding.
  Because the memo shape is identical, a wrong transport flag can never corrupt
  a replay; worst case is delivery style.
- **`StructuredOutputNode` stays a sibling** — its correction-retry loop and
  attempt-indexed memos are a genuinely different algorithm — but its
  run-specific constructor args (`outputClass`, `maxTries`) are removed; it reads
  them from its ingress event (G2). Any blank factory can construct it.
- **Abstract `InferenceNode` base stays**: shared plumbing
  (`pendingConversation()`, provider+history constructor, `ChatHistoryHelper`)
  and the documented middleware target ("register against `InferenceNode::class`
  once, fires in all modes") survive verbatim. A concrete parent is impossible
  anyway: `StructuredOutputNode::__invoke(StructuredInferenceEvent ...)` would
  narrow an inherited `__invoke(AIInferenceEvent ...)` parameter — a PHP
  contravariance fatal.
- **Path selection is exact-class event routing** (`eventNodeMap[$eventClass]`):
  `AgentStartEvent` → entry chain (G2), `AIInferenceEvent` → `ChatNode`,
  `StructuredInferenceEvent` → `StructuredOutputNode`. `ToolCallEvent` already
  carries its originating event back to the tool loop, so structured tool
  cycles return to the structured node with zero new routing code.
  Registered-but-unreached nodes never touch step ids (ids are built during
  traversal: `NodeClass-index`, one global index).

### G2 — Inference intent: bare start events, birth nodes, derived routing

*(Revised twice 2026-08-09 post-Phase-2 review. First: the original
"polymorphic conversion method" gave `withStructuredOutput()` three contracts
under one name — recording and routing were split. Second: the routing
derivation moved from a `bootstrap()` mutation into the graph itself — an
entry node births the inference event, ADR-0009-style: flow control belongs
in nodes.)*

The start event is **pure run data** — messages + intent — for every agent.
Definition capability (instructions, tools) enters the flow at a *birth node*,
never at ignition:

- **`AgentStartEvent` (base) carries the intent fields**: `bool $stream =
  false`, `?string $outputClass = null`, `int $maxTries = 1`. (The old
  `AIInferenceEvent::$maxRetries` field migrated into this vocabulary.)
  `Agent::startEvent()` returns a bare `AgentStartEvent` — RAG's override
  doing exactly that is deleted, because it became the base behavior.
- **Recording**: `setStream()` / `setStructuredOutput($class, $tries)` on the
  base — fluent in-place recorders, one implementation, no overrides.
  Recording never changes the event's class. Sugar only records:
  `chat()`/`stream()`/`structured()` are structurally identical; none calls
  `setStartEvent()`. The hand-off chain stays *sugar → event → ignition
  record → wake*.
- **Birth nodes**: the entry chain — `Agent::entryNodes()`, overridden by
  subclasses — ends in the node that births the inference event from the
  definition. Plain Agent: the new **`StartNode`** (instructions cloned +
  tools injected at compose, messages + intent copied from the start event).
  RAG: the retrieval chain ending in `InstructionsNode`, which births the
  same way — plain Agent is RAG with a zero-length retrieval chain. RAG's
  `compose()` override is deleted (the hook replaces it).
- **`StructuredInferenceEvent extends AIInferenceEvent`** — a pure routing
  marker (empty class body); its config is the inherited intent fields.
- **Routing**: `AIInferenceEvent::routed()` derives the event instance whose
  class matches the recorded intent — returns `$this` unless structured
  intent is recorded and the class isn't already `StructuredInferenceEvent`,
  else builds the `StructuredInferenceEvent` copy. Parameterless, idempotent,
  reads intent but never sets it. Called only by the two birth nodes, always
  on a freshly built inference event — so no base-class stub, no polymorphism,
  no bootstrap `setStartEvent()` mutation. Intent set directly on the start
  event + bare `run()` routes correctly (the R1 path) because the birth node
  is an ordinary graph step every ignition passes through.
- **RAG chain events carry the origin start event forward**
  (`QueryPreProcessedEvent`, `DocumentsRetrievedEvent`,
  `DocumentsProcessedEvent` gain a `$startEvent` — the `ToolCallEvent`
  precedent, named for what it carries), so intent survives the retrieval
  boundary to reach
  `InstructionsNode`. This fixes a latent 4.x-design hole: a structured or
  streamed RAG run would otherwise lose its intent mid-flow.
- **Tool-cycle return routing is untouched**: `ToolCallEvent` embeds its
  originating inference event; `ToolNode` returns that instance, whose
  runtime class routes back to the right inference node. The birth node sits
  strictly upstream of the loop and is never re-entered.
- **StartNode must not write chat history**: the plain Agent's inbound
  messages ride the inference event and commit only after the provider call
  succeeds (deferred inbound write). RAG's `PreProcessNode` committing
  inbound up front is RAG's own existing behavior, unchanged.

### G3 — Ignition record (engine-level)

Every persistence-backed run stores, under the reserved stepId `__ignition`, a
`StepResult` whose `output` is:

```php
['version' => 1, 'startEvent' => $event, 'context' => $this->ignitionContext()]
```

- **No manual serialization**: backends already serialize whole `StepResult`s
  through the Serializer seam. Since the G2 revision the start event is *pure
  data* (messages + intent, no instructions, no tools) — nothing to strip,
  nothing to re-seed on adoption. Instructions and tools enter at the birth
  node, whose output is an ordinary persisted step (`AIInferenceEvent`'s
  tool-strip/re-seed machinery covers it, as it covers every mid-flow event).
- **No stepId collisions**: node steps are `NodeClass-index`, memos are
  `stepId::name`.
- **Swept for free**: clean completion runs `deleteSteps()` →
  `persistence->delete($runId)`; a completed run cannot be woken — correct.
- **Hook pair on `Workflow`, empty by default**: `ignitionContext(): array`
  (write side) and `applyIgnitionContext(array $context): void` (read side).
  `Agent` overrides both: `['threadId' => ...]` out; `setThreadId()` — which
  materializes the resolvers (G7) — in. The engine never learns what a thread is.
- **Plain workflows benefit**: a workflow suspended on `awaitEvent()` becomes
  wakeable from a blank factory (same disease, previously unfelt).
- **The turn's inbound messages ride the persisted start event** — which quietly
  fixes crash-recovery for runs that died before the first history commit
  (deferred inbound write means those messages exist nowhere else).

### G4 — `resolveIgnition()`: placement and store routing

A new phase in `events()`, **before** `bootstrap()` — ordering matters twice:
`validate()` materializes the default start event (adoption must precede it),
and `Agent::bootstrap()` constructs nodes with `getChatHistory()` (threadId must
be applied first).

```php
public function events(?array $payload = null, bool $timedOut = false): Generator
{
    $this->resolveIgnition($payload);   // NEW
    $this->bootstrap();
    // ... unchanged
}

protected function resolveIgnition(?array $payload): void
{
    $ignition = $this->loadIgnition();               // via the step engine

    if ($ignition === null) {
        if ($payload !== null) {
            throw new WorkflowException(
                "Cannot wake run {$this->runId}: no ignition record — the run "
                . "was never durably started, or already completed."
            );
        }
        $this->writeIgnition();                      // first segment
        return;
    }

    if (!isset($this->startEvent)) {                 // blank process: adopt
        $this->setStartEvent($this->restoreEventNode($ignition['startEvent']));
        $this->applyIgnitionContext($ignition['context']);
    }
    // same-instance segment: local state already matches the record
}
```

Consequences: crash-replay in a fresh process works via bare `run()` (recovery
workers don't need to know how the run was ignited); a never-started wake fails
loudly at the engine with a message naming the actual problem.

**Store routing — through `StepEngineInterface`, not `Workflow::$persistence`.**
A custom executor owns its own persistence (via its `LocalStepEngine`), so the
workflow's persistence reference and the real step store can be different
objects; writing ignition to the former would strand it where no wake reads.
The engine already owns the real store and the serializer; `getStep()` already
exists for the load side. Add one save-shaped method to `StepEngineInterface`
(engine-internal contract per ADR 0011 — not on the app surface).

### G5 — Persisted-wins on wake

On a wake, the persisted start event wins over the factory's `startEvent()`.
Local instance state wins only when already explicitly set (same-instance
resumes, where the two are identical). Sharp edge, accepted deliberately: the
instructions live inside the birth node's persisted step output (completed
steps are recalled, never re-run), so editing an agent's `instructions()`
between suspend and wake does **not** affect the woken run — correct replay
determinism, delivered by the ordinary step mechanism. State it in the ADR as
a feature: *the ignition record is the run's contract; the factory supplies
capability (tools, provider, history), the record supplies intent.*

### G6 — API: sugar ignites, `wake()` resumes

The functional model ("new turn → new run; answer → wake") maps 1:1 onto the
API:

- **`chat()`/`stream()`/`structured()` lose their `?array $payload` parameter.**
  They are pure ignition: set messages + intent on the start event, hand off.
  The "payload + messages in one call" confusion class becomes unrepresentable.
  `structured()` stays **eager** (returns the output object, not a handler).
- **`wake(array $payload = []): AgentHandler`** — one new mode-agnostic Agent
  sugar: runId tail-adoption, then
  `new AgentHandler($this->events($payload), $this->getChatHistory())`.
  Full handler ergonomics on the wake side: `->getMessage()` for approval
  endpoints, `foreach ->events()` for SSE resumes, `->interrupted()` /
  `->getInterruptRequest()` for incomplete decision sets (which re-suspend),
  `->getState()->get('structured_output')` for structured wakes.
- **`resume(array $payload = [], bool $timedOut = false): WorkflowState` is
  untouched** — it is the uniform engine verb the cloud handler drives (R3),
  and PHP covariance forbids an `AgentHandler` override anyway (`AgentHandler`
  is not a `WorkflowState`).
- **ADR 0005 tail-adoption relocates** from `checkRunId()` in the sugar into
  `wake()`/`resume()`: with no explicit runId, adopt the stamp on the history
  tail's `ToolCallMessage` (requires threadId set locally — true exactly where
  adoption matters, the approval endpoint). Stamp still wins over `make(runId:)`.
- Streaming chunks on a channel-less wake: the power path is public
  `events($payload)`; the intended path is an attached channel
  (`CHANNEL_INTERFACE.md` — this is precisely what the delivery seam is for).
- Internal migration: `Evaluation/Conversation/Conversation.php` resumes via
  `chat(payload:)` today → `wake()`.

### G7 — Thread identity & resolvers (D4/D7, carried forward)

Unchanged from the prior plan, minus the parts G3 obsoletes:

- `setThreadId(string)` / `getThreadId()` on Agent; one identity, three
  appearances (frontend threadId = history thread_id = channel name).
- Resolver-form union setters, Agent-only:
  `setChatHistory(ChatHistoryInterface|Closure)`,
  `setChannel(ChannelInterface|Closure)`; closures receive `string $threadId`;
  concrete instance clears a pending resolver (inline opt-out).
- `materializeResolvers()` — order-independent, idempotent; called from
  `setThreadId()` and both setters.
- **Fail loudly at the real hazard**: `getChatHistory()` throws if a resolver is
  wired and no threadId is set (never silently falls back to
  `InMemoryChatHistory`); `bootstrap()` backstops the channel resolver.
- On wakes the threadId arrives via `applyIgnitionContext()` (G3) — the
  developer never sets it on a wake path.
- Canonical wiring is unchanged (factory receives runId, resolvers receive
  threadId; identical for first run and wake).

### G8 — Kill list and kept-deliberately

**Never gets written (the patch layer this plan replaces):** `AgentMode` enum,
`setMode()`, mode-`match` composition, `__run_context` +
`writeRunContext()`/`recallRunContext()`, fill-only-unset `Agent::resume()`
override, the "same-factory replay invariant for mode" ADR warning
(structurally impossible to violate now).

**Deleted from the code:** `StreamingNode` (absorbed into `ChatNode`),
`compose()`'s parameter, the `payload` parameter on the three sugar methods,
`AIInferenceEvent::$maxRetries` (migrates to base intent).

**Kept deliberately:**
- `ToolNode`/`ParallelToolNode` stay two config-selected classes:
  `parallelToolCalls` is *definition-level* config (reproducible by any blank
  factory, like the provider choice), not per-call state — not the mode disease.
  Changing it mid-run is code-drift, covered by the standard replay caveat.
- `setMessages()` public setter (R1 — a start-event handler sets input and
  calls bare `run()`).
- `setStartEvent()` already public on `Workflow` — the sugar uses it.

---

## Implementation Phases

Each phase leaves `composer test` + `composer analyse` green. No commits — leave
uncommitted for review.

**Phase 1 — Intent vocabulary. ✅ DONE 2026-08-09** (revised same day, see
below)**.** `AgentStartEvent` intent fields + recording methods;
`StructuredInferenceEvent`; RAG chain events carry `$startEvent` (named
`$origin` until the same-day naming pass);
`InstructionsNode` honors the origin's intent.
→ verified: suite unchanged; new `tests/Agent/InferenceIntentTest.php` +
`tests/RAG/InstructionsNodeIntentTest.php` cover defer-on-base,
convert-on-inference, normalization, serialization round-trip (intent kept,
tools stripped), and intent surviving the retrieval boundary.
Implementation notes: `AIInferenceEvent::$maxRetries` (declared, never read)
removed; dead code noticed but untouched per surgical rule —
`src/RAG/Events/QueryPreProcessEvent.php` (referenced only by itself) and
`PreProcessNode`'s never-returned `AIInferenceEvent` union arm.

**Phase 2 — Static graph. ✅ DONE 2026-08-09.** `ChatNode` absorbed streaming
(branch on `$event->stream`; buffered path stays non-generator, streaming lives
in a separate `streamedInference()` generator method — a `yield` anywhere in
`__invoke` would force both paths through the generator protocol);
`StreamingNode` deleted; `StructuredOutputNode` slimmed to provider+history and
routed by `StructuredInferenceEvent`, reading `outputClass`/`maxTries` from the
event (own floor normalization kept); `compose()` parameterless, called from
the new `Agent::bootstrap()` override; sugar methods set intent only
(signatures + `payload` param untouched as planned); `RAG::compose()` appends
`ragNodes()` after `parent::compose()`.
→ verified: full suite green as a behavioral refactor; new
`tests/Agent/StaticGraphTest.php` asserts both inference routes + tool route in
`getEventNodeMap()`; `StreamingNodeTest` → `ChatNodeStreamingTest` (live chunks
+ crash-recovery through the merged node); `StructuredOutputNodeTest` on
event-carried intent.
~~Deviation adopted during implementation: `StructuredInferenceEvent` overrides
`withStructuredOutput()` to record **in place**.~~ Superseded by the G2
revision below.

**Phase 1–2 revision — recording/routing split + StartNode. ✅ DONE
2026-08-09** (review outcome, two steps; final state verified: suite 1492
green + PHPStan clean). Step one: `withStructuredOutput()` had three contracts
under one name (the in-place deviation above was patching the symptom) —
split into `setStream()`/`setStructuredOutput()` uniform in-place recorders
(renamed from `with*`: they mutate, and `set*` is the codebase idiom) and a
single-purpose `routed()` derivation. Step two: the derivation moved out of a
`bootstrap()` `setStartEvent()` mutation into the graph — new `StartNode`
births the inference event from a now-bare Agent start event via the new
`entryNodes()` hook; RAG's `startEvent()`/`compose()` overrides deleted
(`ragNodes()` → `entryNodes()` override); base `AgentStartEvent::routed()`
stub deleted (`routed()` lives only on `AIInferenceEvent`, called only by the
two birth nodes); `StructuredInferenceEvent` is an empty routing marker;
`structured()` no longer calls `setStartEvent()`. Step ids shifted by one
(StartNode is index 0 of the global traversal counter) — two tests with
hardcoded indices updated. See revised G1/G2/G3/G5 for the full rationale.

**Phase 3 — Ignition engine. ✅ DONE 2026-08-09.** `StepEngineInterface` save
method; `resolveIgnition()` in `events()` before `bootstrap()`;
`writeIgnition()`/`loadIgnition()`; hook pair (empty on `Workflow`); the
no-ignition wake guard.
→ verified: `tests/Workflow/IgnitionTest.php` covers written-on-first-segment
under `__ignition`, swept by clean completion, FilePersistence round-trip
(blank factory instance resumes with the adopted start event), crash-replay
via bare `run()` on a blank instance, unknown-runId wake throws the loud
`WorkflowException`, custom-executor store routing (ignition lands in the
executor's engine's persistence, not `Workflow::$persistence`).
Implementation notes: engine access is routed via a new
`WorkflowExecutorInterface::getStepEngine()` (the workflow must reach a
*custom* executor's engine — only the executor knows it);
`StepEngineInterface::saveStep()` is the save-shaped method (write counterpart
to `getStep()`); `resolveIgnition()` calls `prepareExecution($runId)` to key
the engine before the ignition IO — the executor re-stages the payload with
its own call before traversal. Ratified during review (Valerio): the record is
the run's *trigger envelope*, matching Inngest (stored trigger event: `data` ≈
startEvent, `user` ≈ context, re-delivery ≈ adoption) and Temporal
(`WorkflowExecutionStarted` inputs + `memo`); the hook pair is the
engine-opaque context slot, so the Agent-specific payload (threadId, Phase 4)
never leaks into the engine.

**Phase 4 — Identity & resolvers (G7). ✅ DONE 2026-08-09.** `setThreadId()`/
`getThreadId()`, union setters (`setChatHistory(ChatHistoryInterface|Closure)`,
`setChannel(ChannelInterface|Closure)` — PHP parameter contravariance,
`AgentInterface` untouched), `materializeResolvers()` (idempotence encoded by
nulling a resolver the moment it materializes), `getChatHistory()` guard,
bootstrap channel backstop, Agent's `ignitionContext()`/
`applyIgnitionContext()` overrides (`['threadId' => ...]` out; `setThreadId()`
— which materializes resolvers — in, before `bootstrap()` constructs nodes).
→ verified: `tests/Agent/ThreadIdentityTest.php` (9 tests) — either-order
materialization exactly once; both fail-loud guards; concrete instance clears
a pending resolver; resolver returning a wrong type throws; threadId recorded
in the persisted `__ignition` context; a blank instance (resolver wired, runId
only) adopts the threadId from the record and materializes on replay. Existing
suite unchanged (concrete wiring untouched); PHPStan clean on the unions.

**Phase 5 — API.** Remove `payload` from the three sugar methods; add
`wake(): AgentHandler`; relocate tail-adoption into `wake()`/`resume()`;
migrate `Conversation.php`.
→ verify (the point of the whole plan): agent starts inline with resolvers
(stream intent, approval-gated tool) + `setThreadId()`, suspends; a **fresh**
Agent from a bare factory (`runId` only, resolvers wired, no threadId) wakes
via `wake($payload)`: `ChatNode` streams from the adopted event, history
materialized for the right thread, post-wake chunks reach the FakeChannel,
approval settles, final message lands in history, `completed()` fires. Plus:
structured wake returns the output via `getState()`; RAG structured end-to-end
(intent survives the retrieval boundary); incomplete decision set re-suspends
and `wake()->interrupted()` is true; tail-adoption still resolves the runId
from thread id alone.

**Phase 6 — Documentation.** ADR 0015 (static agent graph & ignition records —
includes persisted-wins and the layering principle); AGENTS.md updates
(Workflow, Agent, RAG, Chat, Testing); migration notes (below); threadId trust
note (untrusted input used as a storage key — authorize user↔thread ownership
first). ADR 0014 (channel) remains as planned in `CHANNEL_INTERFACE.md`.
→ verify: docs reference no removed APIs (`StreamingNode`, `payload:` on sugar,
`AgentMode`, `__run_context`).

## Migration Notes (V4 breaking changes)

- `chat()`/`stream()`/`structured()` no longer accept `payload:` — resume via
  `wake($payload)` (handler ergonomics) or `resume($payload)` (engine verb).
- `StreamingNode` is deleted; custom subclasses or middleware registered against
  it target `ChatNode` (or `InferenceNode::class`, which still covers all
  modes).
- `StructuredOutputNode` constructor loses `outputClass`/`maxTries`.
- `Agent::compose()` is parameterless; the graph always starts with the
  `entryNodes()` chain (`StartNode` by default). Subclasses that redefine the
  entry flow override `entryNodes()` instead of `compose()` (RAG's
  `ragNodes()` is renamed to `entryNodes()`).
- `Agent::startEvent()` returns a bare `AgentStartEvent` (messages + intent
  only); instructions and tools enter the flow at the birth node. Code
  reading instructions off the start event moves to the inference event.
- `AIInferenceEvent::__construct` changes (`maxRetries` moves to base intent).
- Suspended 3.x runs are not portable to 4.x (no ignition record; step ids
  change with the node merge). Drain in-flight approvals before upgrading.
- Editing `instructions()` no longer affects already-suspended runs on wake
  (persisted-wins, G5).

## Deferred (unchanged): event-triggered ignition

The Inngest-style pure event model stays deferred to the cloud-SDK repo. The
contract requirements are all satisfied or preserved by this plan:

- **R1** — inbound start with developer payload: `setThreadId()` +
  `setMessages()` + intent on the start event + bare `run()`.
- **R2** — inbound wake delivers the payload to `resume()` (unchanged).
- **R3** — handlers stay ignition-agnostic: uniform `WorkflowInterface`,
  `resume()` signature untouched.
- **R4** — events carry the threadId as correlation key (now durably recorded
  in the ignition context).

## Out of Scope

- Everything out of scope in `CHANNEL_INTERFACE.md`.
- Any neuron-cloud-sdk or platform change.
- Multi-run-per-thread concurrency control (thread locking stays an application
  responsibility).
- Merging `ToolNode`/`ParallelToolNode` into one flag-driven class (revisit only
  if the two-class split ever bites).
