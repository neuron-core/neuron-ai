# Upgrade: Run snapshots carry the start event, and pending approvals need a suspended run

## Summary

- **`WorkflowRunSnapshot` has a required `startEvent`.** It is the run's original input, the event the run
  was started with. `WorkflowEngine::inspect()` and `Workflow::inspect()` read it from the run's
  `__ignition` record, one more read per inspection, and each snapshot carries its own copy. A run that ends
  or is replaced between the two reads is read again, and a control record without its ignition raises
  `WorkflowException`. An Agent's start event holds the user's messages, so a reload can show the question
  of a turn whose first inference has not succeeded yet: until then the question is not in history.
- **`Agent::pendingApprovals()` returns an empty array unless the run is suspended.** An answered approval
  request stays attached to the run while the approved tools execute, and after they fail. The method used to
  list its actions as pending in that window, inviting a second answer that execution refuses.

| Before | After |
|---|---|
| `new WorkflowRunSnapshot($runId, $status, $executionAttempt, $interrupt, $workflowId)` | `new WorkflowRunSnapshot($runId, $status, $executionAttempt, $interrupt, $workflowId, $startEvent)` |

## How to Refactor

### Case 1: Code constructing `WorkflowRunSnapshot`

The constructor requires the start event. Snapshots read through `inspect()` have it. A snapshot built from a
returned state has no start event to pass: report the run with your own value object, reading the identity
and status from the state.

Before:

```php
return new WorkflowRunSnapshot(
    $state->getRunId(),
    $state->getStatus(),
    $state->getExecutionAttempt(),
    $state->getInterruptRequest(),
    $state->getWorkflowId(),
);
```

After:

```php
return new RunReport(
    runId: $state->getRunId(),
    status: $state->getStatus(),
    executionAttempt: $state->getExecutionAttempt(),
    interrupt: $state->getInterruptRequest(),
    workflowId: $state->getWorkflowId(),
);
```

### Case 2: Tests that write a control record by hand

Inspection now reads `__ignition` next to `__control`. A test seeding persistence with a control record only
must seed the matching ignition as well; otherwise inspection raises `WorkflowException`.

Before:

```php
$persistence->initializeIfAbsent('thread', '__control', serialize($control));
```

After:

```php
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\Ignition;

$persistence->initializeIfAbsent('thread', '__control', serialize($control), [
    '__ignition' => serialize(new Ignition($control->runId, new StartEvent())),
]);
```

A mocked `PersistenceInterface::get()` answers the ignition read too.

### Case 3: Code reading `pendingApprovals()` while a run executes

Nothing is pending while the run executes or after it failed. Show the run's state from `inspect()` instead:
`$agent->inspect()?->status` is `Running` while the approved tools execute and `Failed` when they failed,
which a plain `run()` recovers.

## What to Search For

```
grep -rn "new WorkflowRunSnapshot(" --include="*.php" .
grep -rn "__control" --include="*.php" .
grep -rn "pendingApprovals()" --include="*.php" .
```

## Checklist

- No code constructs a `WorkflowRunSnapshot` without a start event; reports built from states use their own value object.
- Tests seeding `__control` by hand seed the matching `__ignition`.
- No UI treats approvals as pending while the run is running or failed.
