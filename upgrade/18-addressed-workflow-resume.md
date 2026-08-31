# Upgrade: typed, suspension-addressed Workflow resume

## Summary

Workflow resume is changing from a nullable payload plus positional flags to a
typed, suspension-addressed input API. This is an intentional breaking change;
the old signature is removed without a compatibility shim.

Before:

```php
resume(
    ?array $payload = null,
    bool $timedOut = false,
    ?string $expectedRunId = null,
): WorkflowState
```

New signature:

```php
/** @param non-empty-list<ResumeInput> $inputs */
resume(
    array $inputs,
    ?string $expectedRunId = null,
): WorkflowState
```

The new contract makes suspension addressing mandatory without exposing run
generation identity during ordinary manual continuation:

- Every input identifies the active suspension it intends to resolve.
- `expectedRunId`, when supplied, fences the whole delivery against the active
  run generation.

At least one input is required. Payload-less crash recovery is a separate
operation; it is no longer encoded as `resume(null)`.

```php
recover(
    ?string $expectedRunId = null,
    ?int $expectedExecutionAttempt = null,
): WorkflowState
```

## Choose current-run or exact-run semantics

Omit `expectedRunId` when application code is deliberately resuming the run that
is currently active under the Workflow ID. Core obtains that run from `__control`:

```php
$state = $workflow->resume([
    ResumeInput::event($suspensionId, ['approved' => true]),
]);
```

This is the normal manually managed flow. It also preserves thread-first Agent
continuation: the thread/chat history identifies the Workflow partition, while
core resolves its current run. Developers do not copy or generate a run ID.

Supply `expectedRunId` when the input may have been delayed, retried, queued, or
delivered by an external platform:

```php
$state = $workflow->resume(
    [ResumeInput::event($suspensionId, ['approved' => true])],
    expectedRunId: $runId,
);
```

The value is copied from the earlier suspended outcome; callers never invent it.
It changes the operation from "resume whichever run is current" to "resume only
this exact run." A mismatch rejects the entire batch before node execution.

The Cloud SDK must always use exact-run semantics. Omitting the fence is intended
for direct application-controlled continuation, not durable delivery machinery.

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
use NeuronAI\Workflow\Resume\ResumeInput;

$state = $workflow->resume(
    [ResumeInput::event($suspensionId, ['approved' => true])],
);
```

The application payload remains `array<string, mixed>`. Only lifecycle and
routing information moves into the typed value.

An explicitly empty event payload remains valid:

```php
$state = $workflow->resume(
    [ResumeInput::event($suspensionId, [])],
);
```

## Update deadline and timer delivery

An expired `awaitEvent()` deadline and a fired `sleepUntil()` timer are different
inputs. Neither is represented by a boolean flag or a sentinel payload.

Before — expired event deadline:

```php
$state = $workflow->resume([], timedOut: true, expectedRunId: $runId);
```

After:

```php
$state = $workflow->resume(
    [ResumeInput::expired($suspensionId)],
    expectedRunId: $runId,
);
```

After — fired sleep timer:

```php
$state = $workflow->resume(
    [ResumeInput::timer($suspensionId)],
    expectedRunId: $runId,
);
```

`event` and `expired` can target only a `wait_for_event` suspension. `expired`
also requires that the suspension has an expiry. `timer` can target only a
`sleep_until` suspension. The Workflow core validates these combinations before
delivering anything to a node.

## Resume multiple suspensions in one segment

Pass all currently available answers in the same call. This supports parallel
branches without making the caller choose an execution order:

```php
$state = $workflow->resume(
    [
        ResumeInput::event($approvalSuspensionId, ['approved' => true]),
        ResumeInput::event($documentSuspensionId, ['documentId' => 'doc-7']),
        ResumeInput::timer($delaySuspensionId),
    ],
    expectedRunId: $runId,
);
```

Each suspension ID may appear only once in a batch. A matching run may partially
accept a batch: inputs for active suspensions execute, while already-settled or
unknown suspension IDs are reported as stale. A stale `runId` rejects the entire
batch before any node executes.

## Replace payload-less resume

These old calls do not mean "deliver an empty answer":

```php
$workflow->resume();
$workflow->resume(null, expectedRunId: $runId);
```

They requested crash recovery/replay. Move them to the explicit recovery operation
introduced by the Workflow ownership refactor. Do not manufacture an empty
`ResumeInput` or reuse a suspension ID: external delivery and process recovery are
different transitions with different fencing requirements.

```php
$state = $workflow->recover();

// Durable worker recovery fences the exact ownership state it observed.
$state = $workflow->recover(
    expectedRunId: $runId,
    expectedExecutionAttempt: $executionAttempt,
);
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

$state = $workflow->resume($inputs, expectedRunId: $runId);

// The SDK/platform durably reconciles $state->getSuspensions() here.
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

With retention enabled, a lost completion response can retry `resume()` or
`recover()` and receive the same completed state without rerunning nodes. Once
the platform has durably accepted that outcome, acknowledge the exact run:

```php
$workflow->acknowledgeCompletion($runId);
```

Acknowledgement conditionally deletes the complete partition. It is not a
permanent core history mechanism.

## Cloud SDK wire protocol

Transport values remain JSON-compatible. The SDK validates this envelope and maps
each `inputs` entry to a `ResumeInput`; decoded arrays are not passed directly to
Workflow core.

```json
{
  "type": "wake",
  "fnId": "shipment",
  "workflowId": "order-42",
  "runId": "run-abc",
  "inputs": [
    {
      "suspensionId": 4,
      "kind": "event",
      "payload": {"approved": true}
    },
    {
      "suspensionId": 5,
      "kind": "expired"
    },
    {
      "suspensionId": 6,
      "kind": "timer"
    }
  ]
}
```

Wire rules:

- `type` is `wake`.
- `workflowId`, `runId`, and a non-empty `inputs` list are required.
- `suspensionId` is a positive, run-scoped integer and is unique in the batch.
- `kind` is exactly `event`, `expired`, or `timer`.
- `event` requires a JSON object `payload`; `{}` is valid and `null` is invalid.
- `expired` and `timer` omit `payload`.
- `fnId` is owned by the Cloud SDK/platform factory and is not passed to
  `ResumeInput`.
- Platform job and delivery IDs remain platform metadata and are not core inputs.
- The SDK passes the envelope's `runId` as `expectedRunId`; it never uses the
  optional current-run behavior.

Constructor mapping:

| Wire entry | Core value |
|---|---|
| `{"suspensionId": 4, "kind": "event", "payload": {...}}` | `ResumeInput::event(4, [...])` |
| `{"suspensionId": 5, "kind": "expired"}` | `ResumeInput::expired(5)` |
| `{"suspensionId": 6, "kind": "timer"}` | `ResumeInput::timer(6)` |

The response contains the complete current suspension set so the platform can
reconcile its registrations after duplicate delivery or a lost response:

```json
{
  "workflowId": "order-42",
  "runId": "run-abc",
  "executionAttempt": 3,
  "status": "suspended",
  "inputResults": [
    {"suspensionId": 4, "status": "accepted"},
    {"suspensionId": 3, "status": "stale"}
  ],
  "suspensions": [
    {
      "suspensionId": 7,
      "type": "wait_for_event",
      "eventName": "payment.received",
      "expiresAt": "2026-09-01T12:00:00+00:00"
    }
  ]
}
```

A completed response uses `"status": "completed"` and an empty `suspensions`
list. Transport-specific HTTP status codes and platform delivery metadata remain
outside the Workflow protocol.

Core exposes these identity fields as `WorkflowState::getWorkflowId()`,
`getRunId()`, and `getExecutionAttempt()`; SDK code should not read the internal
state keys directly.

## What to search for

Search application code, packages, and tests for:

- `->resume(` calls whose array contains raw application payload values rather
  than `ResumeInput` objects.
- `timedOut:` and positional timeout booleans.
- `expectedRunId:` passed as the third argument.
- bare `->resume()` and `->resume(null` recovery calls.
- Cloud handlers that forward decoded JSON arrays directly into `resume()`.
- timer handlers that deliver `[]` only to make the old nullable sentinel work.
- `setScheduler()`, `SchedulerInterface`, and scheduler lifecycle callbacks.
- SDK factories that need retained completion but do not enable and acknowledge it.

## Verification checklist

- [ ] Every delayed, queued, or platform resume supplies the expected run ID.
- [ ] Direct manual resumes omit it only when "current active run" is intended.
- [ ] Every external input supplies the suspension ID it resolves.
- [ ] Event, expiry, and timer delivery use the matching named constructor.
- [ ] Multiple inputs for one segment contain no duplicate suspension IDs.
- [ ] No old `$timedOut` or nullable-payload resume calls remain.
- [ ] Crash recovery uses the explicit recovery operation, not `resume()`.
- [ ] The Cloud SDK validates the JSON discriminator before constructing core values.
- [ ] A stale run rejects the whole batch without executing nodes.
- [ ] A mixed active/stale suspension batch reports each input disposition.
- [ ] Platform factories call `retainCompletionUntilAcknowledged()` and acknowledge
      only after the completed outcome is durable outside Workflow.
- [ ] Scheduler callbacks and injection have been removed from integration code.
