# Upgrade: Workflow signals and unified execution

Native approval decisions are submitted with `$agent->submitApprovalDecisions($decisions)`, the Agent shortcut for `submitInputs($decisions, new NeuronAI\Agent\Interrupt\ApprovalTranslator())`; the examples below use the shortcut.

## Summary

Application event delivery now uses named signals, while durable platform SDKs
retain typed, interrupt-addressed inputs for retry-safe delivery. This is an
intentional breaking change; the old nullable payload and positional flags are
removed without a compatibility shim.

Before:

```php
resume(
    ?array $payload = null,
    bool $timedOut = false,
    ?string $expectedRunId = null,
): WorkflowState
```

New application APIs (all staging methods finish with `run()` or `events()`):

```php
signal(string $name, array $payload = []): static
resume(array $inputs = [], ?string $expectedRunId = null, ?int $expectedExecutionAttempt = null): static
run(): WorkflowState
events(): Generator
```

Agent approval hides the internal signal name:

```php
$agent->submitApprovalDecisions(['call_123' => 'approve'])->run();
$agent->submitApprovalDecisions(['call_123' => 'approve'])->events();
```

Durable platform SDKs stage an addressed continuation before invoking either terminal:

```php
$state = $workflow->resume($inputs, expectedRunId: $runId)->run();
```

The two-layer contract removes coordination identity from ordinary application
continuation without weakening the platform protocol. In the addressed layer:

- Every platform input identifies the active interrupt request it resolves.
- An empty input batch continues without delivering an external answer.
- `expectedRunId`, when supplied, fences the whole delivery against the active
  run generation.
- `expectedExecutionAttempt`, when supplied, fences the continuation against
  the exact ownership attempt observed by a durable worker.

## Choose application signal or exact platform semantics

Application code delivers an event to the currently active waits by name:

```php
$state = $workflow
    ->signal('order.approved', ['approved' => true])
    ->run();
```

Agent tool approval uses the thread as Workflow identity and tool-call IDs as
domain payload keys:

```php
$state = $agent
    ->submitApprovalDecisions(['call_123' => 'approve'])
    ->run();
```

Neither application path requires a run ID, execution attempt, interrupt
request, or interrupt ID.

Supply `expectedRunId` when the input may have been delayed, retried, queued, or
delivered by an external platform:

```php
$state = $workflow->resume(
    [$request->getId() => ['approved' => true]],
    expectedRunId: $runId,
)->run();
```

The value is copied from the earlier suspended outcome; callers never invent it.
It changes the operation from "resume whichever run is current" to "resume only
this exact run." A mismatch rejects the entire batch before node execution.

The Cloud SDK must always use exact-run semantics. Application code uses
`signal()` or the Agent decision-map wrapper instead.

## Update event delivery

Before:

```php
$state = $workflow->resume(
    ['approved' => true],
    expectedRunId: $runId,
);
```

After:

```php
$state = $workflow
    ->signal('order.approved', ['approved' => true])
    ->run();
```

The application payload remains `array<string, mixed>`. Workflow resolves the
currently active waits internally. A signal is broadcast to every active wait
with the same name; it is not queued for future waits. When nothing matches,
`signal()` throws without persisting the payload.

An explicitly empty event payload remains valid:

```php
$state = $workflow->signal('order.approved')->run();
```

## Update deadline and timer delivery

Before — an external scheduler manufactured a timeout input:

```php
$state = $workflow->resume([], timedOut: true, expectedRunId: $runId);
```

After — the platform schedules only the earliest workflow deadline:

```php
$state = $workflow->resume(
    [],
    expectedRunId: $runId,
)->run();
```

Workflow evaluates the clock and resolves every currently due `sleepUntil()` or
expiring `awaitEvent()` request. Future deadlines remain suspended.

Timer and expiry inputs are internal to the executor. Public continuations do not
accept input kinds, and timer jobs do not need interruption IDs.

## Continue multiple interrupts in one segment

Pass all currently available answers in the same call. This supports parallel
branches without making the caller choose an execution order:

```php
$state = $workflow->resume(
    [
        $approvalRequest->getId() => ['approved' => true],
        $documentRequest->getId() => ['documentId' => 'doc-7'],
    ],
    expectedRunId: $runId,
)->run();
```

Each interrupt ID may appear only once in a batch. A matching run may partially
accept a batch: inputs for active interrupts execute, while already-settled or
unknown interrupt IDs are reported as stale. A stale `runId` rejects the entire
batch before any node executes.

## Use explicit empty input for deadlines and replay

An explicit empty batch requests continuation without delivering an answer:

```php
$workflow->resume()->run();
```

It evaluates due deadlines and continues the current run without inventing an
external input. Do not manufacture an answer or reuse an interrupt ID for
ordinary timer delivery or crash replay. A real empty application event payload
uses `signal('event.name')`; an exact platform delivery uses
`resume([$request->getId() => []])->run()`.

```php
$state = $workflow->resume()->run();

// A durable worker fences the exact ownership state it observed.
$state = $workflow->resume(
    [],
    expectedRunId: $runId,
    expectedExecutionAttempt: $executionAttempt,
)->run();
```

## Update custom persistence backends

`PersistenceInterface` no longer exposes unconditional `put()` and `delete()`
or the multi-mode `conditionalCommit()` method. Custom backends now implement
four focused operations:

```php
get(string $partition, string $key): ?string

initializeIfAbsent(
    string $partition,
    string $conditionKey,
    string $initialValue,
    array $records = [],
): bool

writeIfUnchanged(
    string $partition,
    string $conditionKey,
    string $expectedValue,
    array $records,
): bool

deleteIfUnchanged(
    string $partition,
    string $conditionKey,
    string $expectedValue,
): bool
```

Each mutation must be atomic. A `false` result means the condition did
not match and no record was written or deleted; storage failures still throw.
Backends continue to treat partitions, condition keys, and values as opaque
strings. `WorkflowRunStore` owns reserved keys, serialization, and selection of
the appropriate operation.

Calls using PHP named arguments must rename `guardKey` to `conditionKey`,
`guardValue` to `initialValue`, and `expectedGuard` to `expectedValue`.

## Remove scheduler injection

`SchedulerInterface`, `NullScheduler`, `setScheduler()`, and executor-owned
`onSuspend`/`onResume`/`onComplete` callbacks are removed. Workflow core now
returns its lifecycle outcome synchronously to whichever component invoked it.

Move durable coordination to the platform SDK invocation boundary:

```php
$workflow = $factory->make($fnId, $workflowId)
    ->setPersistence($productionStore)
    ->retainCompletionUntilAcknowledged();

$state = $workflow->resume($inputs, expectedRunId: $runId)->run();

// The SDK/platform durably reconciles $state->getInterruptRequests() here.
```

The factory owns reconstruction and dependency injection. Workflow persistence
does not store platform jobs, callback commands, factory IDs, or permanent
history.

## Choose completion cleanup behavior

Manual workflows retain their existing smooth lifecycle: completion atomically
deletes the workflow partition by default.

Platform SDK factories should enable replayable completion before invoking core:

```php
$workflow->retainCompletionUntilAcknowledged();
```

With retention enabled, a lost completion response can retry `resume()->run()` and
receive the same completed state without rerunning nodes. Once the platform has
durably accepted that outcome, acknowledge the exact run:

```php
$workflow->acknowledgeCompletion($runId);
```

Acknowledgement conditionally deletes the complete partition. It is not a
permanent core history mechanism.

## Cloud SDK wire protocol

Transport values remain JSON-compatible. The platform owns its delivery envelope;
Workflow accepts a decoded map of interruption IDs to payload arrays:

```php
$workflow->resume(
    [4 => ['approved' => true], 7 => ['documentId' => 'doc-7']],
    expectedRunId: $runId,
)->run();
```

Each key is a positive run-scoped interruption ID. Each value is an array payload;
`[]` is a valid empty answer. Platform job IDs, factory IDs, and delivery IDs
remain outside these payloads unless they are part of the application's data.
No `kind` field or framework input object is required.

The platform stores a projection containing workflow/run identity, interruption
ID, event name or deadline, and its own job metadata. When accepting a named
event, resolve and store its matching interruption IDs once. Every retry reuses
that set and the expected run ID instead of matching the signal name again.

Timer jobs store the workflow/run identity and earliest deadline, then invoke
`resume(expectedRunId: $runId)->run()`. The executor evaluates deadlines internally.

The response contains the complete current interrupt request set so the platform can
reconcile its registrations after duplicate delivery or a lost response:

```json
{
  "workflowId": "order-42",
  "runId": "run-abc",
  "executionAttempt": 3,
  "status": "suspended",
  "inputResults": [
    {"interruptId": 4, "status": "accepted"},
    {"interruptId": 3, "status": "stale"}
  ],
  "interrupts": [
    {
      "interruptId": 7,
      "type": "wait_for_event",
      "eventName": "payment.received",
      "expiresAt": "2026-09-01T12:00:00+00:00"
    }
  ]
}
```

A completed response uses `"status": "completed"` and an empty `interrupts`
list. Transport-specific HTTP status codes and platform delivery metadata remain
outside the Workflow protocol.

Core exposes these identity fields as `WorkflowState::getWorkflowId()`,
`getRunId()`, and `getExecutionAttempt()`; SDK code should not read the internal
state keys directly.

## What to search for

Search application code, packages, and tests for:

- Workflow `->resume(` calls whose array contains raw application payload
  values; migrate them to `signal($name, $payload)`.
- Agent approval endpoints that retain an interrupt request; migrate them to `submitApprovalDecisions($decisions)` followed by `run()` or `events()`.
- `timedOut:` and positional timeout booleans.
- `expectedRunId:` passed as the third argument.
- bare `->resume()` calls; finish the staged continuation with `->run()` or `->events()`.
- Cloud handlers that forward decoded JSON arrays directly into `run()`; use `submitInputs()` with a translator.
- timer handlers that manufacture addressed timer/expiry inputs; ordinary
  scheduling now invokes `resume()->run()` after the deadline.
- `setScheduler()`, `SchedulerInterface`, and scheduler lifecycle callbacks.
- SDK factories that need retained completion but do not enable and acknowledge it.

A plain terminal automatically recovers a persisted failed execution. Agent new-turn methods select a fresh execution internally.

## Verification checklist

- [ ] Application event delivery uses `signal()` followed by `run()` or `events()` and does not
      retain Workflow interrupt IDs.
- [ ] Agent approval uses decision maps keyed by tool-call ID.
- [ ] Every delayed, queued platform continuation supplies the expected run ID.
- [ ] Every exact platform event input supplies the interrupt ID it resolves.
- [ ] Ordinary timer delivery calls `resume()->run()`; Workflow evaluates
      which deadlines are due.
- [ ] Addressed delivery supplies payload arrays keyed by interruption ID;
      timer and expiry processing uses inputless continuation.
- [ ] Multiple inputs for one segment contain no duplicate interrupt IDs.
- [ ] No old `$timedOut` or nullable-payload resume calls remain.
- [ ] Crash recovery uses `resume()->run()`, without manufacturing an input.
- [ ] The Cloud SDK validates the JSON discriminator before constructing core values.
- [ ] A stale run rejects the whole batch without executing nodes.
- [ ] A mixed active/stale interrupt batch reports each input disposition.
- [ ] Platform factories call `retainCompletionUntilAcknowledged()` and acknowledge
      only after the completed outcome is durable outside Workflow.
- [ ] Scheduler callbacks and injection have been removed from integration code.)->run()