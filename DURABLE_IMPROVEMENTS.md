# Durable Improvements — Output Delivery & Background Agent Wakes

**Status**: Planned (grilled 2026-08-07) · **Branch**: 4.x · **Scope**: neuron-ai framework only (cloud SDK changes deferred, see [Deferred](#deferred-event-triggered-ignition))

## The Gap

Agentic workflows are user-facing: their output powers a live conversation, not a
fire-and-forget job. But `Workflow::run()`/`resume()` consume the traversal generator
internally and **discard every yielded item** — so when a run is driven by the Cloud
Handler (background wake), all streamed output falls on the floor. Delivery is
currently coupled to *who holds the generator*: in controller mode the caller holds it;
in background mode nobody does.

A second, harder gap: **background wake of an Agent does not work at all.** The
Agent's inference nodes are composed inside `chat()`/`stream()`/`structured()`; a
Handler calling bare `resume()` on a factory-built Agent finds no nodes to run. And
even if it did, the fresh process has no way to know which conversation it belongs to.

The resolution (three seams, one per concern):

| Seam | Owns | Owner |
|---|---|---|
| `PersistenceInterface` | state — where steps live | the app (its DB) |
| `SchedulerInterface` | coordination — when a suspended run wakes | the platform |
| **`ChannelInterface`** (new) | **delivery — where output goes while in flight** | **the app (websocket, SSE, …)** |

Guiding principle (from Temporal/Inngest/Vercel survey): *nobody streams through the
durable engine; production is decoupled from consumption by a named intermediary keyed
by app-owned identity; durable state (chat history) is the record of truth, the live
stream is ephemeral catch-up on top of it.*

## Decisions (ratified)

### D1 — ChannelInterface contract

Location: `src/Workflow/Channel/ChannelInterface.php` (Workflow module is
dependency-free; the interface only references Workflow-native types).

```php
interface ChannelInterface
{
    /** A yielded stream item (chunk, event, custom object). */
    public function send(object $item): void;

    /** Run segment ended in a suspension. Upsert semantics (see D9). */
    public function suspended(InterruptRequest $request): void;

    /** Run segment ended cleanly. */
    public function completed(WorkflowState $state): void;
}
```

Terminals are explicit methods — implementations never instanceof-sniff
`InterruptEvent` out of a stream, and the upsert-vs-append contract has a named home.

The channel receives **framework-native objects** (`TextChunk`, custom yields), never
protocol strings. Formatting is per-consumer edge work (see D6).

### D2 — Fire semantics: always forward, single choke point

Forwarding lives in `Workflow::events()` — one code path, zero mode detection:

```php
foreach ($executorGenerator as $item) {
    try { $this->channel->send($item); }
    catch (Throwable $e) { /* D3 */ }
    yield $item;
}
// terminal: channel->suspended($request) or channel->completed($state)
```

- A wired channel is *always* fed, whether a caller holds the generator or
  `run()`/`resume()` consume internally.
- Double delivery in inline mode (HTTP pull + websocket push) is a **feature**:
  other tabs/devices see the turn live.
- Default is `NullChannel` (inert, same role as `NullScheduler`) — zero overhead,
  today's behavior unchanged.

The pull path is untouched: `AgentHandler::events(?StreamAdapterInterface)` keeps its
signature and behavior for callers that manage streaming directly.

### D3 — Channel errors: catch, report, continue

Every channel call is wrapped at the choke point. A `Throwable` is dispatched as a
PSR-14 observability event and traversal proceeds as if the send succeeded. Losing
liveness must never lose the run: chat history is the record; the UI reconciles from
it (snapshot-then-delta). Refinement allowed in implementation: after N consecutive
failures, mute the channel for the remainder of the segment (still reported once).

### D4 — Thread identity: `setThreadId()` on Agent

One identity, three appearances — the frontend `threadId`, the chat history
`thread_id`, and the channel name are **the same string**. It enters the framework
through one explicit seam:

```php
$agent = BookingAgent::make()->setThreadId($threadId);
```

- Fed from the HTTP request inline, or (later) from a start-event payload in the
  delegated case — same setter, different transport.
- Required when resolver-form wiring (D7) is used on a first run: the framework
  **throws** if a resolver exists but no threadId is set (fail loudly).
- Recalled from the run-context record (D5) on wakes — never set by the developer
  on a resume path.
- `run_id` remains engine identity: developers never handle it beyond pass-through
  (outbound it is stamped into history per ADR 0005; inbound it arrives in the wake).

### D5 — Run-context record (reserved stepId)

A run's "birth certificate": the scalars a blank process needs to reconstruct the
agent **before traversal** — `{threadId, mode}`. Stored once, at first run, under a
reserved stepId in the existing persistence KV:

```php
$persistence->save($runId, '__run_context', $context);
```

- **Zero `PersistenceInterface` changes** — all backends (InMemory, File, Database,
  Eloquent, custom) work untouched.
- Read cheaply at resume-bootstrap by `Agent::resume()` before node composition.
- Extensible for future context scalars without schema changes.
- Why not WorkflowState: state lives inside step records and is only reconstructed
  *during* traversal — but these facts are needed before the graph can even be
  composed (chicken-and-egg).

### D6 — Agent entry-point unification (lazy composition)

`chat()`/`stream()`/`structured()` currently fuse three things: set turn input, pick
mode, execute. Unfuse them:

- Turn input (messages) and mode become settable/recallable Agent properties.
- Node composition moves to a lazy step at execution time, reading mode + input from
  whatever populated them: the sugar methods (first run) or run-context recall (wake).
- `chat()`/`stream()`/`structured()` keep their **exact current signatures** as sugar.
- Result: bare `run()` and `resume()` work on an Agent — the Cloud Handler (and any
  future event trigger) drives agents through the uniform `WorkflowInterface`,
  ignition-agnostic.

`Agent::resume()` override sequence: peek run-context → set threadId → materialize
resolvers (D7) → compose node for recalled mode → delegate to `parent::resume()`.

### D7 — Resolver API: union setters, closures are Agent-only

`Workflow::setChannel(ChannelInterface)` stays concrete-only (plain workflows have no
thread; their factory builds the channel directly). Agent widens both setters:

```php
/** @param ChatHistoryInterface|Closure(string): ChatHistoryInterface $history */
public function setChatHistory(ChatHistoryInterface|Closure $history): self;

/** @param ChannelInterface|Closure(string): ChannelInterface $channel */
public function setChannel(ChannelInterface|Closure $channel): static;
```

Closures always receive `string $threadId`. Materialization: immediately when the
threadId is known (first run, after `setThreadId()`), or at resume-bootstrap after
run-context recall (wake). Concrete-instance wiring stays valid and unchanged —
it is the inline-only opt-out.

Canonical wiring (identical for first run and wake — the factory never sees a threadId):

```php
$cloud->register('booking-agent', fn (string $runId) =>
    BookingAgent::make(runId: $runId)
        ->setChatHistory(fn (string $tid) => new SQLChatHistory($tid, $pdo))
        ->setChannel(fn (string $tid) => new RedisChannel("chat.{$tid}"))
        ->setScheduler($cloud->scheduler('booking-agent'))
);
```

### D8 — Shipped implementations

| Class | Module | Role |
|---|---|---|
| `NullChannel` | Workflow | Inert default |
| `CallbackChannel` | Workflow | Wraps three closures — universal userland escape hatch |
| `StreamAdapterChannel` | Chat | Owns a `StreamAdapterInterface` + output sink; the pull/push bridge |
| `FakeChannel` | Testing | Records calls for assertions |

Redis/Pusher/Ably are **documentation examples** built on `CallbackChannel` — zero
new composer dependencies.

**Adapter-sharing rule (documented, not typed)**: adapters are stateful transcoders;
the same adapter instance must never be shared between the pull side and a channel.
Two consumers → two adapter instances, even for the same protocol.

### D9 — Terminal semantics & replay behavior

- `suspended()` carries the outbound `InterruptRequest` — the run's last utterance
  before sleeping. Replay-by-rerun re-emits it on every re-suspension: channel/UI
  contract is **upsert by runId** ("current pending request"), never append.
- The request is delivered **in-process as a live object** — the framework never
  serializes it (existing invariant preserved). The app's channel renders its own
  request subclasses.
- Crash-replay never re-broadcasts: memoized steps are skipped, so their yields are
  never re-emitted. The abandoned partial stream is reconciled from history.
- For agents, the channel is a liveness signal; chat history (ADR 0003) remains the
  durable record the UI renders from on reload.

### D10 — AGUIAdapter: threadId required (4.x)

`new AGUIAdapter(string $threadId, ?string $runId = null)` — the auto-generation
fallback is removed. An invented threadId emits protocol events for a conversation
the store has never heard of; the failure mode moves from silent identity corruption
to a TypeError at the composition root. 4.x is the moment for this break.

Trust note (docs): the frontend-supplied threadId is untrusted input used as a
storage key — apps must authorize user↔thread ownership before constructing the
history with it.

## New-turn vs wake (functional model, unchanged)

A user message is (almost) never a resume payload:

- **New turn** → new run on the same thread (`chat($messages)`, new runId). Enforced
  today by the new-turn guard and history alternation.
- **Answer** → wake of the suspended run (`resume($payload)`).
- Legitimate third case: a run that suspended on `awaitEvent('user.reply')` — there
  the user's message *is* the wake payload, by the run's own declaration.

Routing the ambiguity (wake a waiter vs trigger a new run) is platform-side —
deferred (requirement R4 below).

## Implementation Phases

Each phase leaves `composer test` + `composer analyse` green. No commits — leave
uncommitted for review.

**Phase 1 — The seam.** `ChannelInterface`, `NullChannel`, `Workflow::setChannel()`,
choke-point forwarding in `Workflow::events()`, catch-report-continue error policy.
→ verify: unit tests — wired channel receives every yielded object in order on both
consumption paths (caller-held generator and internal consume); a throwing channel
never fails the run and dispatches an observability event; NullChannel default adds
no behavior change (existing suite untouched).

**Phase 2 — Terminals.** `suspended()`/`completed()` fired from the choke point on
`InterruptEvent` / clean `StopEvent` terminal.
→ verify: suspension delivers the live `InterruptRequest` (same instance, never
serialized); re-suspension on an incomplete approval payload re-emits (upsert
documented); completion carries the final state; crash-replay of a completed step
does not re-send its yields.

**Phase 3 — Implementations.** `CallbackChannel`, `StreamAdapterChannel` (own adapter
instance + sink), `FakeChannel`; `AGUIAdapter` threadId made required.
→ verify: StreamAdapterChannel produces byte-identical output to the equivalent
`AgentHandler::events($adapter)` pull for the same chunk sequence; FakeChannel
assertions used by Phases 1–2 tests; AGUIAdapter tests updated to required param.

**Phase 4 — Identity & background wakes.** `setThreadId()`, run-context record
(`__run_context` reserved stepId), Agent entry-point unification (lazy composition,
sugar preserved), resolver union setters, `Agent::resume()` override.
→ verify: end-to-end test — agent starts inline with resolvers (stream mode, gated
tool), suspends; a **fresh** Agent instance built by a bare factory (`runId` only)
resumes via bare `resume($payload)`: correct node composed from recalled mode,
history materialized for the right thread, chunks reach the channel, approval
settles, final message lands in history. Plus: first-run-with-resolver-but-no-threadId
throws; concrete-instance wiring passes the existing suite unchanged.

**Phase 5 — Documentation.** ADR 0014 (output delivery channel), ADR 0015 (thread
identity, run-context, agent entry unification); AGENTS.md updates (Workflow, Agent,
Chat); CallbackChannel transport examples (Redis/Pusher); adapter-sharing rule;
threadId trust note.
→ verify: docs mention every decision D1–D10; no doc references removed APIs.

## Deferred: event-triggered ignition

**Vision** (to be grilled in the neuron-cloud-sdk repo, its own session): converge on
an Inngest-style pure event model — functions registered with *triggers* (events
carrying payloads); a start is just a trigger-matched event, a wake is a
correlation-matched event; no dedicated `startRun()` API. Substantial SDK + platform
work (trigger matching, fan-out, idempotency).

Contract requirements this plan already satisfies or preserves, which the SDK
redesign must honor:

- **R1** — an inbound start delivers a developer-defined payload to the factory
  (the agent side is ready: `setThreadId()` + settable turn input + bare `run()`).
- **R2** — an inbound wake delivers the matched event payload to `resume()`
  (works today; unchanged).
- **R3** — the app-side handler stays ignition-agnostic (agents are drivable through
  the uniform `WorkflowInterface` after D6).
- **R4** — events must carry a correlation key (the threadId) so the router can
  decide wake-vs-start.

## Out of scope

- Buffered/resumable channel (RedisStreamChannel with TTL/offset replay) — deserves
  its own design pass; `CallbackChannel` covers the transport meanwhile.
- Any neuron-cloud-sdk or platform change (see Deferred).
- Platform-persisted output streams (LangGraph-style) — rejected: output must not
  transit the platform; state stays in the app.
