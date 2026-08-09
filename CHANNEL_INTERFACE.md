# ChannelInterface — Output Delivery Seam

**Status**: Planned (extracted from DURABLE_IMPROVEMENTS.md, re-grilled 2026-08-09) ·
**Branch**: 4.x · **Scope**: the delivery seam only (D1–D3, D8–D10, Phases 1–3).
Thread identity, run-context, and agent entry unification stay in
`DURABLE_IMPROVEMENTS.md` (D4–D7, Phases 4–5).

## Why a channel

`Workflow::run()`/`resume()` consume the traversal generator internally
(`Workflow::consume()`, `src/Workflow/Workflow.php`) and discard every yielded item.
When a run is driven by a background wake, nobody holds the generator and all
streamed output falls on the floor. Delivery is coupled to *who holds the generator*.

The channel decouples production from consumption:

| Seam | Owns | Owner |
|---|---|---|
| `PersistenceInterface` | state — where steps live | the app (its DB) |
| `SchedulerInterface` | coordination — when a suspended run wakes | the platform |
| **`ChannelInterface`** (new) | **delivery — where output goes while in flight** | **the app (websocket, SSE, …)** |

Guiding principle: *nobody streams through the durable engine; durable state (chat
history) is the record of truth, the live stream is ephemeral catch-up on top of it.*

## Grill outcomes — deviations from the 2026-08-07 ratified plan

These four changes were forced by contact with the code. Each is marked ⚠ where it
appears below; veto individually if disagreed.

1. **⚠ `runId` added to terminal signatures.** The ratified D9 contract is "upsert
   by runId", but no ratified method received a runId — the contract was
   unimplementable. `suspended()`, `completed()`, and `failed()` now take
   `string $runId`. (`send()` stays runId-free: the channel is segment-scoped and
   per-thread; factories that need the runId per-chunk capture it from their own
   closure scope.)
2. **⚠ `failed(Throwable, string $runId)` fourth method.** The ratified interface
   had no error terminal. A run that throws would leave every push consumer (another
   tab on the websocket) with a stream that just stops — spinner forever, no signal
   to reconcile from history. Every surveyed protocol has an error event (AG-UI
   `RUN_ERROR`, Vercel error part). Adding it later is a BC break on a userland
   interface; adding it now is free. The exception still propagates to the caller
   unchanged — `failed()` is notification, not handling.
3. **⚠ `StreamAdapterChannel` moves from Chat to the Agent module.** The interface
   references `InterruptRequest`/`WorkflowState` (Workflow types). Placing the class
   in Chat adds a Chat→Workflow dependency and breaks the "Chat is self-contained"
   property in AGENTS.md; placing it in Workflow breaks Workflow's zero-dependency
   rule (it needs Chat's `StreamAdapterInterface`). Agent already depends on both →
   `NeuronAI\Agent\Channel\StreamAdapterChannel`.
4. **Mute-after-N deleted** (supersedes ratified D3 and the earlier
   "terminals exempt from muting" refinement — 2026-08-09 review). The framework
   guard is catch-report-continue only: every call is attempted, every failure
   dispatches a `ChannelError`. Counting and thresholds are the listener's policy;
   circuit-breaking belongs to the channel implementation, which understands its
   transport's failure semantics and can short-circuit its own `send()` (a PSR-14
   listener can count errors but cannot gate the engine's future calls). This
   deletes the failure counter, the muted flag, the threshold constant, and the
   terminal-exemption special case from `Workflow`.

Resolved ambiguities (no contract change, but the implementer must know):

- **`InterruptEvent` is never passed to `send()`.** The executor yields it as the
  last stream item on suspension (`WorkflowExecutor::execute()`, line `yield
  $terminal`); the choke point filters it out and delivers the pause via
  `suspended()` instead. This is the *one* instanceof check, and it lives in the
  framework choke point — implementations never sniff. Pull consumers still receive
  the `InterruptEvent` (unchanged behavior for `AgentHandler`).
- **`StopEvent` is never yielded by the executor** (only node-streamed items and the
  `InterruptEvent` terminal are). So `send()` receives exactly: chunks and custom
  node yields. No filtering needed beyond `InterruptEvent`.
- **`yield from` → `foreach` in `events()`** changes generator keys (fresh
  auto-increment) and drops send-through semantics. Both are safe: no consumer uses
  keys (`AgentHandler` iterates `current()`/`next()`), and nothing `->send()`s into
  the workflow generator.
- **Replay silence is already guaranteed.** `LocalStepEngine::runStep()` returns a
  cached `StepResult` without yielding anything, so crash-replayed steps can never
  reach `send()`. No new code needed — only a regression test.

## D1 — The contract

Location: `src/Workflow/Channel/ChannelInterface.php` (Workflow module; references
only Workflow-native types plus `Throwable`).

```php
<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Channel;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Delivery seam: where in-flight output goes, decoupled from who holds the
 * generator. The channel receives framework-native objects, never protocol
 * strings — formatting is per-consumer edge work (see StreamAdapterChannel).
 */
interface ChannelInterface
{
    /**
     * A yielded stream item (TextChunk, ToolCallChunk, custom node yields).
     * Never an InterruptEvent — terminals are explicit methods below.
     */
    public function send(object $item): void;

    /**
     * Run segment ended in a suspension. The request is the live, in-process
     * object — never serialized. Contract for consumers: UPSERT by runId
     * ("current pending request"), never append — replay-by-rerun re-emits
     * this on every re-suspension of the same run.
     */
    public function suspended(InterruptRequest $request, string $runId): void;

    /** Run segment ended cleanly. */
    public function completed(WorkflowState $state, string $runId): void;

    /**
     * Run segment died on an unhandled throwable. Notification only — the
     * exception propagates to the caller regardless.
     */
    public function failed(Throwable $exception, string $runId): void;
}
```

Contract notes:

- **Framework-native objects in, always.** A channel that needs wire format wraps a
  `StreamAdapterInterface` (see `StreamAdapterChannel`). Two consumers → two adapter
  instances, even for the same protocol: adapters are stateful transcoders
  (`AGUIAdapter` tracks open message/tool/reasoning streams) and must never be
  shared between the pull side and a channel. *Documented rule, not typed.*
- **Channels must not throw** as a courtesy, but the framework does not trust them:
  every call is guarded at the choke point (D3).
- **A channel instance is segment-scoped**: constructed by the factory/resolver for
  one `events()` consumption. Implementations may hold per-segment state (lazy
  protocol start) without cross-run leakage.

## D2 — Fire semantics: one choke point in `Workflow::events()`

`Workflow` gains (mirroring the existing scheduler wiring pattern —
nullable property, inert default, `setX()` setter):

```php
protected ?ChannelInterface $channel = null;

/** Where in-flight output is delivered. Default is inert (NullChannel). */
public function setChannel(ChannelInterface $channel): static
{
    $this->channel = $channel;
    return $this;
}

protected function channel(): ChannelInterface
{
    return $this->channel ??= new NullChannel();
}
```

`Workflow::setChannel()` is **concrete-only**. The `Closure` resolver form is
Agent-only (D7 in DURABLE_IMPROVEMENTS.md) — plain workflows have no thread; their
factory builds the channel directly.

`events()` is rewritten from `yield from` to an explicit loop — the single choke
point, zero mode detection:

```php
public function events(?array $payload = null, bool $timedOut = false): Generator
{
    $this->bootstrap();

    $generator = $this->resolveExecutor()->execute($this, $payload, $timedOut);

    try {
        foreach ($generator as $item) {
            if (!$item instanceof InterruptEvent) {
                $this->fireChannel(fn () => $this->channel()->send($item));
            }
            yield $item;
        }
    } catch (Throwable $e) {
        $this->fireChannel(fn () => $this->channel()->failed($e, $this->runId));
        throw $e;
    }

    $state = $this->resolveState();

    if ($state->isInterrupted()) {
        $this->fireChannel(fn () => $this->channel()->suspended($state->getInterruptRequest(), $this->runId));
    } else {
        $this->fireChannel(fn () => $this->channel()->completed($state, $this->runId));
    }

    return $state;
}
```

- **Push before pull**: `send()` fires before the `yield`, so a stalled pull
  consumer never delays other tabs behind it within an item (ordering per item;
  no buffering).
- A wired channel is *always* fed — caller-held generator (`foreach
  $workflow->events()`) and internal consume (`run()`/`resume()` →
  `consume(events())`) share this one path. Double delivery in inline mode (HTTP
  pull + websocket push) is a **feature**: other tabs/devices see the turn live.
- Terminal detection is state-based (`isInterrupted()`), not stream-sniffing, and
  runs after the generator is exhausted — the same place `consume()` reads the
  return today.
- The pull path is untouched: `AgentHandler::events(?StreamAdapterInterface)`
  keeps its exact signature and behavior.
- Suspension is NOT failure: on suspend, only `suspended()` fires (the executor
  returns normally on `InterruptEvent`; no throwable reaches the catch).

## D3 — Channel errors: catch, report, continue

Losing liveness must never lose the run: chat history is the record; the UI
reconciles from it (snapshot-then-delta). Implementation, all inside `Workflow`:

```php
/**
 * Run one channel call under the catch-report-continue policy: a channel
 * error never fails the run — every failure is dispatched as a ChannelError
 * and delivery moves on. Circuit-breaking (stop trying after N failures,
 * retry, back off) is the channel implementation's own policy, not the
 * engine's.
 */
protected function fireChannel(Closure $op): void
{
    if ($this->channel === null || $this->channel instanceof NullChannel) {
        return;
    }

    try {
        $op();
    } catch (Throwable $e) {
        $event = new ChannelError($e);
        $event->source = $this;
        $this->getEventDispatcher()->dispatch($event);
    }
}
```

- Every failure dispatches a PSR-14 `ChannelError` observability event — new class
  `src/Observability/Events/ChannelError.php` extending `ObservabilityEvent`,
  carrying `public readonly Throwable $exception`. Source is stamped manually since
  this dispatch doesn't go through the executor's `dispatchEvent()` helper.
- No framework mute (⚠ deviation 4): every delivery is attempted. A listener that
  wants a threshold counts `ChannelError`s itself; a channel that wants to stop
  hammering a dead transport short-circuits its own `send()` — it is the only party
  that knows whether its errors are fatal or transient.
- The `NullChannel` fast-path guard keeps the per-chunk overhead at one property
  check when no channel is wired — the "zero overhead, today's behavior unchanged"
  promise.

## D8 — Shipped implementations

| Class | Location | Role |
|---|---|---|
| `NullChannel` | `src/Workflow/Channel/NullChannel.php` | Inert default; four empty bodies |
| `CallbackChannel` | `src/Workflow/Channel/CallbackChannel.php` | Wraps up to four closures — universal userland escape hatch |
| `StreamAdapterChannel` | ⚠ `src/Agent/Channel/StreamAdapterChannel.php` | Owns a `StreamAdapterInterface` + output sink; the pull/push bridge |
| `FakeChannel` | `src/Testing/FakeChannel.php` | Records calls for assertions |

Redis/Pusher/Ably are **documentation examples** built on `CallbackChannel` — zero
new composer dependencies.

### CallbackChannel

```php
final class CallbackChannel implements ChannelInterface
{
    /**
     * @param ?Closure(object): void $onSend
     * @param ?Closure(InterruptRequest, string): void $onSuspended
     * @param ?Closure(WorkflowState, string): void $onCompleted
     * @param ?Closure(Throwable, string): void $onFailed
     */
    public function __construct(
        protected ?Closure $onSend = null,
        protected ?Closure $onSuspended = null,
        protected ?Closure $onCompleted = null,
        protected ?Closure $onFailed = null,
    ) {
    }
    // each method: null-safe invoke of its closure
}
```

All closures optional — a Redis example only needs `$onSend`.

### StreamAdapterChannel

```php
final class StreamAdapterChannel implements ChannelInterface
{
    protected bool $started = false;

    /** @param Closure(string): void $sink Receives each protocol output line. */
    public function __construct(
        protected StreamAdapterInterface $adapter,
        protected Closure $sink,
    ) {
    }
}
```

Semantics, chosen for **byte parity with the pull path**
(`AgentHandler::events($adapter)`):

- `send($item)`: lazily emit `adapter->start()` output once (before the first
  transformed item), then emit every line of `adapter->transform($item)` to the sink.
- `suspended()` / `completed()` / `failed()`: emit `adapter->start()` if never
  started (the pull path emits start/end even for a zero-item run), then every line
  of `adapter->end()`. The pull path calls `end()` on suspension too (it runs after
  the generator completes regardless of interrupt state) — so the push path does the
  same. Protocol-level *suspension/error events* (e.g. AG-UI `RUN_ERROR`) are future
  adapter work, out of scope here.
- The channel never renders the `InterruptRequest` itself — an app channel subclass
  or `CallbackChannel` does that; this class is a pure transcoder bridge.

Because the adapter is stateful, the channel **owns** its instance: construct one
per channel, never share with a pull consumer (adapter-sharing rule, D1 notes).

### FakeChannel

```php
final class FakeChannel implements ChannelInterface
{
    /** @var object[] */
    public array $sent = [];
    /** @var array{request: InterruptRequest, runId: string}[] */
    public array $suspensions = [];
    /** @var array{state: WorkflowState, runId: string}[] */
    public array $completions = [];
    /** @var array{exception: Throwable, runId: string}[] */
    public array $failures = [];

    public ?Throwable $throwOnSend = null;   // failure-policy tests
}
```

Note: adds a Testing→Workflow dependency (Testing currently lists only Providers) —
acceptable for a test-utilities module; update `src/Testing/AGENTS.md` if it states
dependencies.

## D9 — Terminal semantics & replay behavior (contract, mostly already true)

- `suspended()` carries the outbound `InterruptRequest` — the run's last utterance
  before sleeping — **as a live object, same instance, never serialized** (existing
  invariant: `LocalStepEngine` persists only an `interrupted: true` marker, never
  the request). The app's channel renders its own request subclasses.
- Replay-by-rerun re-emits `suspended()` on every re-suspension (e.g. an incomplete
  approval payload re-suspends): consumer contract is **upsert by runId**, never
  append.
- Crash-replay never re-broadcasts item streams: cached steps yield nothing
  (`LocalStepEngine::runStep()` returns the cached result without yielding). The
  abandoned partial stream is reconciled from history. Regression test required, no
  code required.
- For agents, the channel is a **liveness signal**; chat history (ADR 0003) remains
  the durable record the UI renders from on reload.

## D10 — AGUIAdapter: threadId required (4.x break)

`src/Chat/Messages/Stream/Adapters/AGUIAdapter.php`:

```php
public function __construct(protected string $threadId, protected ?string $runId = null)
```

Remove the `?? $this->generateId('thread')` fallback and the `?string $threadId`
property type. An invented threadId emits protocol events for a conversation the
store has never heard of; the failure mode moves from silent identity corruption to
a TypeError at the composition root.

Trust note (goes in docs, Phase 5): the frontend-supplied threadId is untrusted
input used as a storage key — apps must authorize user↔thread ownership before
constructing the history with it.

## Implementation Phases

Each phase leaves `composer test` + `composer analyse` green. No commits — leave
uncommitted for review. PHPStan: closures in `array_map` etc. need explicit param
types; no `assertTrue(true)` (use a `$matched` flag).

**Phase 1 — The seam.**
Files: `ChannelInterface`, `NullChannel`, `Observability\Events\ChannelError`;
`Workflow`: `setChannel()`, `channel()`, `fireChannel()`, `events()` rewrite.
→ verify (`tests/Workflow/Channel/ChannelForwardingTest.php`):
1. Wired channel receives every yielded object, in order, via `run()` (internal
   consume).
2. Same via caller-held `foreach ($workflow->events() as $e)`.
3. A channel whose `send()` throws: run completes normally, one `ChannelError`
   dispatched per failure (subscribe via `$workflow->subscribe()`).
4. Every delivery is attempted — no framework mute: N failing sends produce N
   `ChannelError`s and the terminal is still delivered.
5. No channel wired: existing suite untouched (green as-is proves it).

**Phase 2 — Terminals.**
`fireChannel(..., terminal: true)` wiring for `suspended`/`completed`/`failed` in
`events()`.
→ verify (extend `ChannelForwardingTest` + interrupt-flow tests):
1. Suspension delivers the **same `InterruptRequest` instance** the node created,
   plus the runId; `send()` never saw the `InterruptEvent`.
2. Re-suspension (incomplete approval payload) fires `suspended()` again — upsert
   documented in the test name.
3. Completion fires `completed()` with the final state and runId; a suspended
   segment never fires `completed()`.
4. A node that throws: `failed()` fires with the exception + runId, exception still
   propagates to the caller, `completed()`/`suspended()` do not fire.
5. Resume after suspend: channel of the resume segment receives only post-resume
   items (cached steps re-emit nothing).
6. A terminal call that throws is caught and reported (`ChannelError`), never
   thrown to the caller.

**Phase 3 — Implementations.**
Files: `CallbackChannel`, `Agent\Channel\StreamAdapterChannel`,
`Testing\FakeChannel`; `AGUIAdapter` required threadId.
→ verify:
1. `StreamAdapterChannel` output is **byte-identical** to
   `AgentHandler::events($adapter)` pull for the same chunk sequence (drive both
   from `FakeAIProvider` fixtures; collect sink strings vs iterated strings;
   remember: two adapter instances).
2. Zero-item run: push path still emits start+end, matching pull.
3. `CallbackChannel` with partial closures: unset hooks are silent no-ops.
4. Retro-fit Phases 1–2 assertions onto `FakeChannel` where it shortens them.
5. `AGUIAdapter` tests updated: constructor requires threadId; no generated-thread
   assertions remain.

Phases 4–5 (identity, run-context, entry unification, docs/ADR 0014-0015) — see
`DURABLE_IMPROVEMENTS.md`. Phase 4 depends on this seam (`setChannel` resolver form
widens the Agent setter); land 1–3 first.

## Out of scope

- Buffered/resumable channel (RedisStreamChannel with TTL/offset replay) — own
  design pass; `CallbackChannel` covers the transport meanwhile.
- Protocol-level suspension/error events in adapters (AG-UI `RUN_ERROR` etc.).
- Any neuron-cloud-sdk or platform change.
- Platform-persisted output streams — rejected: output must not transit the
  platform; state stays in the app.
