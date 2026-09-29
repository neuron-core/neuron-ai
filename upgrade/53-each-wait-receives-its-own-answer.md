# Upgrade: Each wait in a node receives its own answer

## Summary

A resumed node runs again from the top. In 3.x the answer went to the first wait the node reached on that run, whichever
wait had asked for it. A node that waited twice without memoizing the first answer handed every later answer to its first
wait and never completed, and a wait whose condition was false (`interruptIf(false, ...)`) took the answer meant for the
wait after it. Each answer is now recorded with the node's step:

- **Every wait the node already passed returns its own answer again**, and the new answer reaches the wait that asked.
- **A wait is identified by its order in the node.** Inside a `memoize()` closure its waits are counted apart, because a
  recorded closure is skipped when the node runs again.
- **The node must reach its waits in the same order every time it runs.** One that reaches a new wait before the one being
  answered fails with a `WorkflowException` instead of dropping the answer.
- **A run paused before the upgrade** gives the answer it is waiting for to the first wait the node reaches, as before.

| Before (3.x) | After |
|---|---|
| Two waits in one node: the second answer is returned by the first wait, and the node asks again | Each wait returns its own answer |
| A second wait whose return value is ignored makes the next run's first wait receive the new answer | The new answer is returned by the second wait |
| `interruptIf(false, ...)` before a wait takes that wait's answer | A wait that did not suspend never takes an answer |
| Earlier answers must be wrapped in `memoize()` | `memoize()` around a wait is no longer needed, and still works |

## How to Refactor

### Case 1: A node that asks again by waiting a second time

A node that re-asked with a second wait and ignored its return value relied on the next run's first wait receiving the
new answer. The first wait now returns the first answer again, so read the new answer where it arrives.

Before:

```php
$answer = $this->awaitEvent('code');
if (!$this->isValid($answer)) {
    $this->awaitEvent('code'); // the next run's first wait received the new answer
}

return new VerifiedEvent($answer);
```

After:

```php
do {
    $answer = $this->awaitEvent('code');
} while (!$this->isValid($answer));

return new VerifiedEvent($answer);
```

### Case 2: Side effects between waits

The code between waits runs again whenever the node does, now with every earlier answer returned again. A side effect
that depends on an answer, such as sending an email or writing a record, repeats on each resume unless it is wrapped in
`memoize()`:

```php
$approval = $this->awaitEvent('approval');
$this->memoize('notify', fn (): bool => $resources->mailer->send($approval));
$payment = $this->awaitEvent('payment');
```

### Case 3: Waits wrapped in `memoize()` to keep earlier answers

Nothing to change. The wrapper is no longer needed and can stay.

## What to Search For

```
grep -rnE "awaitEvent\(|->interrupt\(|interruptIf\(|sleepUntil\(" --include="*.php" .
```

For each node with more than one call, or with a call inside a loop, check the cases above.

## Checklist

- No node waits a second time only to have its first wait receive the new answer.
- Side effects between waits are wrapped in `memoize()`.
- Every node reaches its waits in the same order each time it runs.
