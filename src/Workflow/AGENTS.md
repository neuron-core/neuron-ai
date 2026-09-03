# Workflow module

Workflow is Neuron's event-driven orchestration foundation. Agent and RAG are
compositions built on it.

## Core model

Workflows route events through nodes until a `StopEvent`:

```text
StartEvent -> NodeA -> EventA -> NodeB -> StopEvent
```

A node's `__invoke()` input type determines routing. The executor owns traversal,
durable replay, suspension, and lifecycle transitions; nodes remain unaware of
workers, HTTP requests, queues, or cloud platforms.

```php
$workflow = Workflow::make(state: $state)
    ->addNodes([new NodeA(), new NodeB()]);

$state = $workflow->run();
```

## Suspend, inspect, and resume

Nodes suspend through focused helpers:

```php
$payload = $this->awaitEvent('order.approved', expiresAt: $deadline);
$this->sleepUntil($wakeAt);
$payload = $this->interrupt(new MyApprovalRequest(...));
```

`InterruptRequest` is the canonical, portable description of the pause. The
executor assigns its ID, persists it, and returns the same ID-bound value to
the caller. Request subclasses may add portable presentation metadata but must
remain serializable:

```php
$state = $workflow->run();

foreach ($state->getInterruptRequests() as $request) {
    // getId(), type(), plus request-specific coordination and metadata
}
```

Application code normally resumes an event wait by signal name:

```php
$state = $workflow
    ->signal('order.approved', ['approved' => true])
    ->run();
```

The signal is delivered to every currently active `awaitEvent()` with that
name, including waits in parallel branches. It is never queued: when no active
wait matches, `signal()` throws a `WorkflowException` without persisting the
payload. Use distinct names or a domain correlation key when delivery must
target only one wait.

Call `events()` instead of `run()` after `signal()` to stream the resumed segment.

Every interrupt receives a positive, run-scoped, monotonically increasing ID.
Parallel branches may expose multiple active interrupts at once. A partial
resume executes only addressed interrupted nodes; unaddressed requests are
returned directly from persistence without rerunning their nodes.

Durable platform SDKs use addressed, typed inputs:

```php
use NeuronAI\Workflow\Interrupt\ResumeInput;

$state = $workflow->run([
    ResumeInput::event($eventWaitRequest, ['approved' => true]),
    ResumeInput::expired($expiredEventWaitRequest),
    ResumeInput::timer($sleepRequest),
]);
```

- `event` targets `wait_for_event`.
- `expired` and `timer` remain available to infrastructure integrations, but
  ordinary timer coordination invokes `run([])` and lets Workflow
  determine which waits are due.
- Event payloads must be JSON-compatible arrays; `{}`/`[]` is a real empty
  answer.
- A matching-run batch accepts active IDs and reports already-settled/unknown
  IDs as `stale` through `WorkflowState::getInputResults()`.
- A stale `expectedRunId` rejects the whole batch before traversal.

For directly controlled current-run continuation, `expectedRunId` may be
omitted. A durable SDK or delayed delivery supplies the `runId` copied from the
earlier outcome:

```php
$state = $workflow->run($inputs, expectedRunId: $runId);
```

An inputless continuation evaluates the clock and resolves every active
`sleepUntil()` or expiring `awaitEvent()` whose deadline is due. It also replays
a crashed or failed attempt without inventing an external answer. Future waits
remain suspended:

```php
$state = $workflow->run(
    [],
    expectedRunId: $runId,
    expectedExecutionAttempt: $attempt,
);
```

Streaming uses the same continuation model. `events()` starts a run;
`signal($name, $payload)->events()` delivers an application signal;
`events()` continues with addressed platform inputs, and `events([])` performs inputless continuation. Fences require the explicit input array.

Nodes never receive `ResumeInput`. The executor translates an accepted input
into the existing node semantics: event payload, timeout null, or timer wake.
One input satisfies exactly one interruption, including payload-less expiry and
timer inputs.

## Platform-owned coordination

Workflow core does not depend on a scheduler or coordination platform. There is
no scheduler interface and no suspend/resume/complete callback from the
executor.

A platform SDK is an invocation gateway:

1. reconstruct the Workflow and all live dependencies through its own factory;
2. configure persistence, serializer, lease, and completion retention;
3. call `run()` with addressed inputs or `[]` for inputless continuation;
4. inspect the returned status, run ID, input dispositions, and complete active
   interrupt set;
5. durably reconcile the platform's timers, subscriptions, and jobs.

This makes HTTP round trips, queue workers, CLI loops, and other infrastructures
equivalent callers. Platform job IDs, delivery attempts, scheduler commands,
and factory identity do not enter Workflow persistence.

`WorkflowState::getWorkflowId()`, `getRunId()`, and `getExecutionAttempt()` expose
portable ownership identity. `getStatus()`, `getInputResults()`, and
`getInterruptRequests()` expose the lifecycle result.

The complete `InterruptRequest` remains authoritative in Workflow persistence.
The platform stores only a JSON projection needed to route or schedule work:
`workflowId`, `runId`, `interruptId`, type, event name, deadline, and its own job
metadata. A delivery job stores the interrupt-ID set resolved when the external
signal was first accepted and reuses exactly that set on every retry; it never
rematches by signal name after a lost response. The request object itself is
never sent back as continuation input.

For timers, the platform stores the workflow/run identity and earliest deadline,
then invokes `run([])`. Workflow validates the clock and selects all
currently due waits. `expectedExecutionAttempt` is available for exact recovery
fencing but is normally omitted from delayed event delivery because legitimate
recovery may advance the execution attempt.

Reconstruction is explicitly the application/platform SDK factory's
responsibility. Ignition context may restore small domain identity, but it is not
a dependency container or core factory registry.

## One partition and optimistic ownership

All records for a Workflow ID stay in one persistence partition:

| key | purpose |
|---|---|
| `__ignition` | immutable start event, run ID, and engine-opaque context |
| `__control` | mutable lifecycle authority and optimistic condition value |
| `<runId>/<stepId>` | durable step result or marker; branch steps include a hash of their complete parent-fork path |
| `<runId>/<stepId>::<memo>` | durable memo result |

`__control` contains the current run ID, status, monotonic execution attempt,
optional lease deadline, next interrupt ID, active interrupts, and—only when
enabled—the retained completed state.

Every runtime mutation uses one of the explicit atomic persistence operations:
`initializeIfAbsent()`, `writeIfUnchanged()`, or `deleteIfUnchanged()`. Writes
and deletion expect the byte-identical control value from which they were
derived. A successful claim increments `executionAttempt`; an older process can
still finish local work, but it cannot commit a step, memo, suspension, failure,
or cleanup after a new owner changes control.

This is optimistic ownership in plain terms:

```text
read control A -> calculate transition -> write only if control is still A
```

The persistence backend treats all keys and values as opaque strings. It does
not understand runs, attempts, leases, or suspensions.

`WorkflowRunStore` is the internal boundary between lifecycle traversal and
that low-level persistence contract. It owns the reserved keys, serialization,
the current byte-exact control snapshot, conditional record writes, and
conditional partition cleanup. `WorkflowExecutor` works with typed
`WorkflowControl` instances and does not track raw persisted control values.

## Execution lease

Leases are opt-in:

```php
$workflow->setLeaseTimeout(300);
```

The lease deadline lives inside `__control`; there is no separate lease record.
The executor refreshes it at step boundaries. Suspension and caught failure
clear it because no process is intentionally executing. An inputless
continuation worker may take over a `running` attempt only when leases are
disabled or the enabled lease has expired. The conditional claim still ensures
only one contender advances the attempt.

A lease is a crash-overlap safeguard, not proof that the prior process died.
Choose a timeout longer than the longest silent node operation, and use external
idempotency for uncertain side effects.

## Failed and dead generations

A caught failure commits `failed` into `__control` and leaves the partition in
place. Two verbs settle it:

- `run([])` replays the generation: committed steps and memos are reused and
  only the failed step runs again. The run ID is unchanged.
- A fresh `run()` at the same workflow ID supersedes it: the dead generation
  is conditionally swept and a new generation ignites with the new start event.

A `running` generation whose lease deadline has expired is dead in the same
sense (the process never committed again) and is superseded the same way.
Suspended generations, retained completions, and `running` generations with
no lease deadline or a fresh one still refuse a fresh ignition. The sweep is
fenced by the control bytes just read: a recovery worker that claims the
generation first keeps it, and the ignition is refused.

## Completion and cleanup

Manual workflows clean up by default. A clean `StopEvent` conditionally deletes
the whole owned partition, so completed step data does not grow indefinitely and
the Workflow ID becomes available for a new generation.

A platform SDK that must survive a lost completion response opts into retained
completion at construction time:

```php
$workflow->retainCompletionUntilAcknowledged();
```

The executor then commits `completed` plus the terminal state into `__control`.
Retries replay the same terminal state without executing nodes. After the
platform durably records the outcome, it purges the exact generation:

```php
$workflow->acknowledgeCompletion($runId);
```

Acknowledgement requires the retained run ID and control value. Core keeps
no permanent history; history belongs to the platform or application.

## Persistence backends

`PersistenceInterface` provides `get()` plus three explicit atomic mutation
intents: initialize an absent condition key and its records, write records while
the condition value is unchanged, and delete a partition while the condition
value is unchanged. There is no unconditional runtime write or delete path.
Production multi-worker backends must perform each condition check and its
complete mutation in one database transaction or equivalent storage-native
atomic operation.

- `DatabasePersistence` and `EloquentPersistence` are the built-in
  multi-process candidates.
- `InMemoryPersistence` is process-local.
- `FilePersistence` provides restart durability for controlled single-process
  use only. It is deliberately not a worker-farm lock manager. Corrupt or
  unreadable files fail loudly rather than appearing absent.

All backends preserve one silo per Workflow: one array, one directory containing
one file per partition, or one table keyed by `(partition, key)`.

## Durable steps and memoization

Every completed node step is persisted and skipped on replay. If a node fails
before its step result commits, it runs again.

Use `memoize()` around expensive or non-deterministic sub-operations:

```php
$result = $this->memoize('provider-call', fn () => $provider->invoke(...));
```

The successful memo write is fenced by the same control record as step writes.
`memoize()` reuses committed results; it cannot make an uncertain external side
effect exactly once. Supply a provider/application idempotency key where that
matters. `recallMemo()` remains the read-only counterpart for streaming flows.

## Executors and middleware

`WorkflowExecutor` owns lifecycle decisions and sequential traversal.
`WorkflowRunStore` owns the persistence protocol used to commit those
decisions. `AsyncExecutor` changes only parallel branch execution and returns
all active interruptions; storage and coordination ownership do not change.

Executors type against `WorkflowRuntimeInterface`, which exposes definition,
state, persistence, serializer, lease configuration, completion-retention
policy, ignition, and bootstrap. Application code uses `WorkflowInterface`.

Middleware wraps node execution:

```php
$workflow->addMiddleware(NodeClass::class, new LoggingMiddleware());
$workflow->addGlobalMiddleware(new PerformanceMiddleware());
```

Keep platform integration outside middleware and executors. The returned
lifecycle outcome is the integration boundary.
