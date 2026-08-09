# Durable Improvements — Thread Identity & Background Agent Wakes

**Status**: Planned (grilled 2026-08-07, implementation-grilled 2026-08-09) ·
**Branch**: 4.x · **Scope**: neuron-ai framework only (cloud SDK changes deferred,
see [Deferred](#deferred-event-triggered-ignition)).

> **Split note (2026-08-09)**: the output-delivery seam (ChannelInterface, fire
> semantics, error policy, shipped implementations, terminal/replay contract,
> AGUIAdapter break — formerly D1–D3, D8–D10, Phases 1–3) now lives in
> **`CHANNEL_INTERFACE.md`** with full implementation detail. This document keeps
> the identity/wake half: D4–D7 and Phases 4–5. Land the channel phases first —
> Phase 4 widens `setChannel()` on Agent, which must exist on Workflow first.

## The Gap

Agentic workflows are user-facing: their output powers a live conversation, not a
fire-and-forget job. Two gaps:

1. **Output delivery** — `run()`/`resume()` discard every yielded item when nobody
   holds the generator (background wakes). Resolved by the channel seam →
   `CHANNEL_INTERFACE.md`.

2. **Background wake of an Agent does not work at all.** The Agent's inference
   nodes are composed inside `chat()`/`stream()`/`structured()`
   (`Agent::compose()` is called from each sugar method); a Handler calling bare
   `resume()` on a factory-built Agent finds no nodes to run —
   `bootstrap()` → `validate()` throws "No nodes found that handle
   AIInferenceEvent". And even if composition worked, the fresh process has no way
   to know which conversation (thread) the run belongs to, or which mode's node
   classes to compose — and **step ids embed node class names**
   (`WorkflowExecutor::buildStepId()`: `NodeClass-index`), so composing the wrong
   mode on resume silently misses the entire replay cache.

The resolution (three seams, one per concern):

| Seam | Owns | Owner |
|---|---|---|
| `PersistenceInterface` | state — where steps live | the app (its DB) |
| `SchedulerInterface` | coordination — when a suspended run wakes | the platform |
| `ChannelInterface` | delivery — where output goes while in flight | the app (websocket, SSE, …) |

## Grill outcomes 2026-08-09 — corrections to the ratified plan

Contact with the code forced these precisions. Veto individually if disagreed.

1. **⚠ D5's snippet doesn't compile as ratified.**
   `PersistenceInterface::save(string $runId, string $stepId, StepResult $result)`
   takes a `StepResult`, not an arbitrary array. The "zero persistence changes"
   goal survives — the run context rides in `StepResult::$output`, the same
   `mixed` slot durable memos already use. Exact mechanics in D5 below.
2. **⚠ Mode alone is not enough to recompose `structured()`.**
   `StructuredOutputNode` needs the output class and `maxRetries`. The run context
   stores them (`structured` key). Without this, a wake of a suspended structured
   run cannot compose the correct node.
3. **The fail-loudly rule lands in `getChatHistory()`**, not in a separate check:
   an unmaterialized resolver makes `getChatHistory()` throw instead of silently
   falling back to the `InMemoryChatHistory` default — which is the *actual*
   hazard (composing nodes against a history for no thread). Bootstrap also
   guards the channel resolver. See D7.
4. **The inline paths barely touch `resume()`.** `chat(payload:)`/
   `stream(payload:)` call `$this->events($payload)` directly — they never go
   through `Workflow::resume()`. Only `structured(payload:)` and the new bare
   wake path do. The `Agent::resume()` override therefore recalls context with
   **fill-only-unset semantics** (locally set properties always win), making it a
   no-op for inline structured resumes. See D6.
5. **New replay invariant to document**: resume must run under the *same factory
   configuration* as the original run (same `parallelToolCalls`, same middleware
   set adding/removing nodes) because step ids derive from node classes. This was
   already true for crash-replay today; the wake path makes it worth stating in
   the ADR.

## Decisions

### D1–D3, D8–D10 — Output delivery channel

Moved to `CHANNEL_INTERFACE.md` (with signature changes: runId on terminals, a
`failed()` terminal, `StreamAdapterChannel` in the Agent module, terminal calls
exempt from muting).

### D4 — Thread identity: `setThreadId()` on Agent

One identity, three appearances — the frontend `threadId`, the chat history
`thread_id`, and the channel name are **the same string**. It enters the framework
through one explicit seam, on `Agent` (not `Workflow` — plain workflows have no
thread):

```php
protected ?string $threadId = null;

public function setThreadId(string $threadId): static
{
    $this->threadId = $threadId;
    $this->materializeResolvers();   // D7 — order-independent wiring
    return $this;
}

public function getThreadId(): ?string;
```

- Fed from the HTTP request inline, or (later) from a start-event payload in the
  delegated case — same setter, different transport.
- Required when resolver-form wiring (D7) is used on a first run: the framework
  **throws** (`AgentException`) if a resolver is still pending when the history is
  first needed or when `bootstrap()` runs — fail loudly, never fall back to
  `InMemoryChatHistory` (mechanics in D7).
- Recalled from the run-context record (D5) on wakes — never set by the developer
  on a resume path.
- `run_id` remains engine identity: developers never handle it beyond pass-through
  (outbound it is stamped into history per ADR 0005; inbound it arrives in the
  wake and goes to `make(runId: ...)`).

### D5 — Run-context record (reserved stepId)

A run's "birth certificate": the scalars a blank process needs to reconstruct the
agent **before traversal**. Stored under a reserved stepId in the existing
persistence KV, wrapped in a `StepResult` whose `output` slot carries the data —
exactly how durable memos already persist arbitrary values
(`StepMemoizer::memo()`), so **zero `PersistenceInterface` changes** and every
backend (InMemory, File, Database, Eloquent, custom) works untouched.

```php
// On Agent:
protected const RUN_CONTEXT_STEP_ID = '__run_context';

protected function writeRunContext(): void
{
    $this->persistence()->save($this->runId, self::RUN_CONTEXT_STEP_ID, new StepResult(
        stepId: self::RUN_CONTEXT_STEP_ID,
        output: [
            'version' => 1,
            'threadId' => $this->threadId,
            'mode' => $this->mode->value,                   // 'chat' | 'stream' | 'structured'
            'structured' => $this->mode === AgentMode::Structured
                ? ['class' => $this->structuredClass, 'maxRetries' => $this->structuredMaxRetries]
                : null,
        ],
    ));
}

protected function recallRunContext(): ?array
{
    return $this->persistence()->load($this->runId, self::RUN_CONTEXT_STEP_ID)?->getOutput();
}
```

- **No stepId collisions**: node steps are `NodeClass-index`, memo steps are
  `stepId::name` — `__run_context` matches neither. The `version` key allows
  future shape changes without a persistence migration.
- **Written from `Agent::bootstrap()`** on every segment start (see D6) —
  idempotent overwrite of identical scalars; cheaper than read-before-write and
  correct on crash-replay.
- **Deleted automatically**: clean completion runs `stepEngine->deleteSteps()` →
  `persistence->delete($runId)`, which sweeps the context with the steps. A
  completed run cannot be woken — correct.
- Read cheaply at resume-bootstrap by `Agent::resume()` **before** node
  composition.
- Why not `WorkflowState`: state lives inside step records and is only
  reconstructed *during* traversal — but these facts are needed before the graph
  can even be composed (chicken-and-egg).
- Agent-only: plain `Workflow` neither writes nor reads it (no thread, no mode).

### D6 — Agent entry-point unification (lazy composition)

`chat()`/`stream()`/`structured()` currently fuse three things: set turn input,
pick mode, execute. Unfuse them.

**New pieces on the Agent module:**

```php
// src/Agent/AgentMode.php
enum AgentMode: string
{
    case Chat = 'chat';
    case Stream = 'stream';
    case Structured = 'structured';
}
```

```php
// Agent properties
protected ?AgentMode $mode = null;
protected ?string $structuredClass = null;
protected int $structuredMaxRetries = 1;

// Turn input & mode become settable (R1: a future start-event handler
// sets both and calls bare run()):
public function setMessages(Message|array $messages): static
{
    $this->resolveStartEvent()->setMessages(...(is_array($messages) ? $messages : [$messages]));
    return $this;
}

public function setMode(AgentMode $mode): static;   // simple setter
```

**Composition moves to `bootstrap()`** — the lazy step every execution path
already passes through (`events()` calls it first):

```php
protected function bootstrap(): static
{
    if (!$this->mode instanceof AgentMode) {
        throw new AgentException(
            'No execution mode set. Call chat()/stream()/structured(), or setMode() before run()/resume().'
        );
    }

    if ($this->chatHistoryResolver !== null || $this->channelResolver !== null) {
        throw new AgentException(          // D4 fail-loudly: resolver wired, threadId never provided
            'Resolver-form wiring requires a threadId. Call setThreadId() before executing.'
        );
    }

    $this->compose(match ($this->mode) {
        AgentMode::Chat => new ChatNode($this->resolveProvider(), $this->getChatHistory()),
        AgentMode::Stream => new StreamingNode($this->resolveProvider(), $this->getChatHistory()),
        AgentMode::Structured => new StructuredOutputNode(
            $this->resolveProvider(),
            $this->getChatHistory(),
            $this->structuredClass ?? $this->getOutputClass(),
            $this->structuredMaxRetries,
        ),
    });

    $this->writeRunContext();   // D5 — after mode/threadId are final, before traversal

    return parent::bootstrap();
}
```

`compose()` keeps its existing `eventNodeMap !== []` idempotence guard, so a
handler consumed twice or an inline structured resume never double-composes.

**Sugar methods keep their exact current signatures** and shrink to: guard →
set input → set mode → hand off. `checkRunId()` (history-tail runId adoption,
ADR 0005) stays in the sugar, unchanged:

```php
public function chat(Message|array $messages = [], ?array $payload = null): AgentHandler
{
    $this->checkRunId($payload);
    $this->setMode(AgentMode::Chat)->setMessages($messages);

    return new AgentHandler($this->events($payload), $this->getChatHistory());
}
// stream(): identical with AgentMode::Stream.
// structured(): sets AgentMode::Structured + $structuredClass/$structuredMaxRetries,
//               then: $state = $payload === null ? $this->run() : $this->resume($payload);
//               return $state->get('structured_output');
```

Note the sugar's `getChatHistory()` call for the handler: with resolver wiring it
requires the threadId to already be set — same fail-loudly rule, surfaced even
earlier (good).

**`Agent::resume()` override** — the wake path:

```php
public function resume(array $payload = [], bool $timedOut = false): WorkflowState
{
    $context = $this->recallRunContext();

    if ($context !== null) {
        // Fill-only-unset: locally set values win, so the inline structured()
        // resume (same instance, everything already set) passes through untouched.
        if ($this->threadId === null && $context['threadId'] !== null) {
            $this->setThreadId($context['threadId']);      // materializes resolvers (D7)
        }
        $this->mode ??= AgentMode::from($context['mode']);
        if ($context['structured'] !== null) {
            $this->structuredClass ??= $context['structured']['class'];
            $this->structuredMaxRetries = $this->structuredClass === $context['structured']['class']
                ? $context['structured']['maxRetries']
                : $this->structuredMaxRetries;
        }
    }

    return parent::resume($payload, $timedOut);   // → events($payload) → bootstrap() composes
}
```

Sequence matches the ratified plan: peek run-context → set threadId (which
materializes resolvers) → recall mode → delegate; composition happens inside
`events()`'s `bootstrap()` as for every other path.

- Bare `resume()` on a fresh factory Agent with **no run context and no mode**
  hits the bootstrap `AgentException` — a wake for a runId that was never durably
  started fails loudly, not with a graph-validation error.
- Result: bare `run()` and `resume()` work on an Agent — the Cloud Handler (and
  any future event trigger) drives agents through the uniform
  `WorkflowInterface`, ignition-agnostic.
- **Replay invariant (document in ADR 0015)**: the factory must reproduce the
  original node-affecting configuration (`parallelToolCalls`, node-contributing
  middleware) — step ids embed node class names, so a drifted factory silently
  invalidates the replay cache.

### D7 — Resolver API: union setters, closures are Agent-only

`Workflow::setChannel(ChannelInterface)` stays concrete-only (plain workflows have
no thread; their factory builds the channel directly). Agent widens both setters —
PHP parameter contravariance makes the union widening a legal override:

```php
protected ?Closure $chatHistoryResolver = null;
protected ?Closure $channelResolver = null;

/** @param ChatHistoryInterface|Closure(string): ChatHistoryInterface $history */
public function setChatHistory(ChatHistoryInterface|Closure $history): self
{
    if ($history instanceof Closure) {
        $this->chatHistoryResolver = $history;
        unset($this->chatHistory);
        $this->materializeResolvers();     // no-op until threadId is known
        return $this;
    }

    $this->chatHistoryResolver = null;     // concrete instance wins — inline opt-out
    $this->chatHistory = $history;
    return $this;
}

/** @param ChannelInterface|Closure(string): ChannelInterface $channel */
public function setChannel(ChannelInterface|Closure $channel): static;   // same shape

protected function materializeResolvers(): void
{
    if ($this->threadId === null) {
        return;
    }

    if ($this->chatHistoryResolver !== null) {
        $this->chatHistory = ($this->chatHistoryResolver)($this->threadId);
        $this->chatHistoryResolver = null;
    }

    if ($this->channelResolver !== null) {
        parent::setChannel(($this->channelResolver)($this->threadId));
        $this->channelResolver = null;
    }
}
```

- **Order-independent**: `setThreadId()` and both setters each call
  `materializeResolvers()`, so wiring order never matters. Materialization is
  idempotent (resolvers null themselves after firing).
- **Fail loudly, at the real hazard**: `getChatHistory()` gains a guard —

  ```php
  public function getChatHistory(): ChatHistoryInterface
  {
      if ($this->chatHistoryResolver !== null && !isset($this->chatHistory)) {
          throw new AgentException(
              'Chat history resolver is wired but no threadId is set. Call setThreadId() first.'
          );
      }

      return $this->chatHistory ??= $this->chatHistory();
  }
  ```

  This is what prevents the silent-`InMemoryChatHistory` disaster; `bootstrap()`
  backstops the channel resolver the same way (D6).
- Closures always receive `string $threadId` — and only that. A factory that
  needs the runId (e.g. to build an `AGUIAdapter`) captures it from its own
  closure scope; see the canonical wiring below.
- Concrete-instance wiring stays valid and unchanged — it is the inline-only
  opt-out, and passing a concrete instance clears any pending resolver.

Canonical wiring (identical for first run and wake — the factory never sees a
threadId):

```php
$cloud->register('booking-agent', fn (string $runId) =>
    BookingAgent::make(runId: $runId)
        ->setPersistence(new DatabasePersistence($pdo))
        ->setChatHistory(fn (string $tid) => new SQLChatHistory($tid, $pdo))
        ->setChannel(fn (string $tid) => new RedisChannel("chat.{$tid}"))
        ->setScheduler($cloud->scheduler('booking-agent'))
);
```

## New-turn vs wake (functional model, unchanged)

A user message is (almost) never a resume payload:

- **New turn** → new run on the same thread (`chat($messages)`, new runId).
  Enforced today by the new-turn guard and history alternation.
- **Answer** → wake of the suspended run (`resume($payload)`).
- Legitimate third case: a run that suspended on `awaitEvent('user.reply')` —
  there the user's message *is* the wake payload, by the run's own declaration.

Routing the ambiguity (wake a waiter vs trigger a new run) is platform-side —
deferred (requirement R4 below).

## Implementation Phases

Each phase leaves `composer test` + `composer analyse` green. No commits — leave
uncommitted for review.

**Phases 1–3 — Channel seam, terminals, implementations.** See
`CHANNEL_INTERFACE.md`. Prerequisite for Phase 4.

**Phase 4 — Identity & background wakes.** In dependency order:

1. `AgentMode` enum; `setMessages()`/`setMode()`; mode/structured properties.
   → verify: sugar methods still pass the entire existing agent suite unchanged
   (behavioral refactor only).
2. `bootstrap()` override: mode-driven composition + no-mode `AgentException`.
   → verify: `chat()`/`stream()`/`structured()` compose identical node sets to
   today (assert on `getEventNodeMap()` keys); bare `run()` without mode throws.
3. `setThreadId()` + resolver union setters + `materializeResolvers()` +
   `getChatHistory()` guard.
   → verify: resolver+threadId in either order materializes exactly once;
   resolver without threadId throws from `getChatHistory()` and from
   `bootstrap()`; concrete-instance wiring passes the existing suite unchanged;
   PHPStan accepts the union override signatures.
4. Run-context record: `writeRunContext()`/`recallRunContext()` on the reserved
   stepId.
   → verify: written on first run under `__run_context`; overwritten
   idempotently on resume; swept by clean completion (`load()` returns null
   after); survives suspension across a fresh persistence-backed process
   (FilePersistence round-trip); no collision with node/memo step ids.
5. `Agent::resume()` override.
   → verify (the end-to-end wake test, the point of the whole plan): agent
   starts inline with resolvers (stream mode, approval-gated tool) and
   `setThreadId()`, suspends; a **fresh** Agent instance built by a bare factory
   (`runId` only, resolvers wired, no threadId) resumes via bare
   `resume($payload)`: StreamingNode composed from recalled mode, history
   materialized for the right thread, post-resume chunks reach the channel
   (FakeChannel), approval settles, final message lands in history, `completed()`
   fires. Plus: inline `structured(payload:)` resume behaves exactly as today
   (fill-only-unset makes recall a no-op); wake of an unknown runId throws the
   no-mode `AgentException`; structured wake recomposes with recalled
   class/maxRetries.

**Phase 5 — Documentation.** ADR 0014 (output delivery channel — includes the
runId/`failed()` signature rationale and the adapter-sharing rule), ADR 0015
(thread identity, run-context record, agent entry unification — includes the
same-factory replay invariant); AGENTS.md updates (Workflow, Agent, Chat,
Testing); CallbackChannel transport examples (Redis/Pusher); threadId trust note
(untrusted input used as a storage key — authorize user↔thread ownership first).
→ verify: docs mention every decision D1–D10 across the two files; no doc
references removed APIs (AGUIAdapter optional threadId, `Workflow::consume`'s
discard-everything claim).

## Deferred: event-triggered ignition

**Vision** (to be grilled in the neuron-cloud-sdk repo, its own session): converge
on an Inngest-style pure event model — functions registered with *triggers*
(events carrying payloads); a start is just a trigger-matched event, a wake is a
correlation-matched event; no dedicated `startRun()` API. Substantial SDK +
platform work (trigger matching, fan-out, idempotency).

Contract requirements this plan already satisfies or preserves, which the SDK
redesign must honor:

- **R1** — an inbound start delivers a developer-defined payload to the factory
  (the agent side is ready after D6: `setThreadId()` + `setMessages()` +
  `setMode()` + bare `run()`).
- **R2** — an inbound wake delivers the matched event payload to `resume()`
  (works today; unchanged).
- **R3** — the app-side handler stays ignition-agnostic (agents are drivable
  through the uniform `WorkflowInterface` after D6).
- **R4** — events must carry a correlation key (the threadId) so the router can
  decide wake-vs-start.

## Out of scope

- Everything listed out of scope in `CHANNEL_INTERFACE.md` (buffered channels,
  adapter protocol extensions, platform-persisted streams).
- Any neuron-cloud-sdk or platform change (see Deferred).
- Multi-run-per-thread concurrency control (thread locking stays an application
  responsibility, as with approval flows today).
