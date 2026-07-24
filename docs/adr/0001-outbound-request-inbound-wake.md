# InterruptRequest is outbound-only; the resume answer is a separate inbound payload

**Status:** accepted

## Context & decision

A workflow pause sends data **out** of a node (the description of the pause, for the outside world
to act on), and a resume brings data **back in** (the event payload / decision that satisfied the
pause). Historically these were one object: `InterruptRequest` carried the pause terms *and* was
mutated to carry the resume answer (`WaitForEventRequest::setPayload()`/`markExpired()`,
`ApprovalRequest` receiving a modified `Action[]`). That conflation — inherited from the original
ApprovalRequest-for-ToolApproval design — forced every resume caller to reconstruct a *full*
`InterruptRequest` (terms and all), which cross-process callers (the Cloud SDK) could only do by
reaching into persistence to load the stored request and staple the answer onto it.

We decide:

- **`InterruptRequest` is purely outbound and immutable.** A node constructs it; it carries the
  pause terms (`eventName`, `expiresAt`) and any custom context the developer wants surfaced. The
  node receives the **answer** directly on resume, never the request object back. `InterruptRequest`
  gains no inbound methods.
- **The resume answer is a separate inbound value.** `resume(array $payload = [], bool $timedOut = false)`
  is the sole resume path. `$payload` is the delivered event body (a plain, serialization-safe
  array); `$timedOut` is a dedicated boolean signalling the resume was a deadline elapsing. The
  framework shapes the payload into the answer for built-in verbs only (`awaitEvent` → `?array`,
  `null` on `$timedOut`); `interrupt()` on a custom request returns the raw payload and the node
  interprets it.
- **The request is fire-and-forget — it is not persisted.** Only an `interrupted` boolean flag is
  stored per step. The node reconstructs the request by re-executing (replay-by-rerun guarantees
  deterministic re-derivation); the app and scheduler already received it at suspend time, in
  memory, via the returned `WorkflowState` and `onSuspend()`.
- **`resume()` takes no `stepId`.** Traversal walks to the interrupted step in order; the framework
  discovers it. `run()` becomes start/replay-only.

## Considered options

- **Design A — the request round-trips (rejected).** The caller passes a full `InterruptRequest` to
  `run()`; the framework holds two instances per suspend (persisted terms vs incoming answer) and
  must reconcile them. Rejected because: (a) the reconciliation is not just complex but *broken
  today* — `LocalStepEngine::runStep` injects the incoming request verbatim and never reads the
  stored terms, so a caller-reconstructed request silently loses terms; (b) persisting the request
  serializes whatever the developer stuffed into it, so the stated extension goal ("carry object
  instances") hits a `serialize()` failure; (c) it leaves a mutable, post-resume request in storage,
  destroying the ability to distinguish "what was asked" from "what was answered."
- **Persist the request, but keep it outbound-only (rejected).** Considered as a middle ground. Still
  serializes object instances (the extension model's headline feature) and is redundant: replay-by-
  rerun reconstructs the request, and the app/scheduler already hold it at suspend time. Persisting
  it buys only zero-run inspection of pause terms — a convenience we instead assign to whoever wants
  it (they received the request at suspend through two channels).
- **`expired` flag baked into the `$payload` array (rejected).** `$timedOut` is not event data — it is
  the *nature* of the resume. Mixing it into the payload array conflates responsibilities; it gets
  its own argument.

## Consequences

- **Carrying object instances in a custom `InterruptRequest` is now safe** — the request is never
  serialized. This was previously a latent failure mode.
- **The Cloud SDK stops depending on framework internals.** Its resume handler collapses to
  `$workflow->resume($payload, $timedOut)` — one method — instead of `PersistenceInterface` +
  `StepResult::getInterrupt()` + `InterruptRequest` mutators.
- **No zero-run inspection of pause terms from persistence.** A process that wants to know "what is
  workflow X waiting for?" without resuming must either `run()` it (replay re-suspends and returns
  the request via the state) or have stored the request itself at suspend time. This is a deliberate
  shift: the framework owns execution position; durable visibility into pause terms is the caller's
  or scheduler's job.
- **`WaitForEventRequest` loses `setPayload`/`markExpired`/`isExpired`/`getPayload`.** Payload and
  timeout become inbound (`$payload` / `$timedOut`). `expiresAt` (the deadline) stays, as an outbound
  term.
- **Both control topologies — caller-driven (user-facing agents) and scheduler-driven (background) —
  share one resume mechanism.** The framework does not know or care who triggered a given resume.

## Why this is irreversible / surprising

The request-not-persisted choice and the `resume(payload, timedOut)` signature are public API and a
persistence-contract change. A future reader seeing an `interrupted` boolean where they'd expect a
stored request, or a `resume()` that takes no `stepId`, will reasonably ask why — this ADR is the
answer.
