# Upgrade: Branch runners replace executors

## Summary

A workflow's lifecycle is no longer pluggable. Admission, traversal, persistence, failure handling and observability
belong to the engine, which the workflow builds for every call from its persistence and serializer. The only strategy
left to choose is how the branches of a fork run, and a branch runner makes that choice.

- **`setExecutor()` and the `executor()` hook are replaced by `setBranchRunner()` and `branchRunner()`.** They take a
  `NeuronAI\Workflow\Executor\BranchRunner`. The setter wins over the hook.
- **`WorkflowExecutor` and `AsyncExecutor` are removed.** Branches run one after another by default
  (`SequentialBranchRunner`); `AsyncBranchRunner` runs them concurrently as Amp futures.
- **`WorkflowExecutorInterface` is removed.** A custom executor has no replacement. If what it changed was how
  branches run, implement `BranchRunner` instead.

| Before (3.x) | After |
|---|---|
| `$workflow->setExecutor(new AsyncExecutor())` | `$workflow->setBranchRunner(new AsyncBranchRunner())` |
| `protected function executor(): WorkflowExecutorInterface` | `protected function branchRunner(): BranchRunner` |
| `new WorkflowExecutor()` | `new SequentialBranchRunner()`, the default |
| `NeuronAI\Workflow\Executor\AsyncExecutor` | `NeuronAI\Workflow\Executor\AsyncBranchRunner` |

## How to Refactor

### Case 1: Running branches concurrently

Before:

```php
use NeuronAI\Workflow\Executor\AsyncExecutor;

$workflow = Workflow::make()
    ->setExecutor(new AsyncExecutor())
    ->addNodes([...]);
```

After:

```php
use NeuronAI\Workflow\Executor\AsyncBranchRunner;

$workflow = Workflow::make()
    ->setBranchRunner(new AsyncBranchRunner())
    ->addNodes([...]);
```

The branches share the workflow's nodes, so two branches running concurrently must not reach the same node: give each
branch its own event and node, or keep the sequential runner.

### Case 2: A subclass choosing its executor in the hook

Before:

```php
protected function executor(): WorkflowExecutorInterface
{
    return new AsyncExecutor();
}
```

After:

```php
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Executor\BranchRunner;

protected function branchRunner(): BranchRunner
{
    return new AsyncBranchRunner();
}
```

### Case 3: Setting the default executor explicitly

Remove the call: `setExecutor(new WorkflowExecutor())` selected what is now the default.

### Case 4: A custom executor

An executor that changed only how branches run becomes a `BranchRunner`. The segment keeps the durable protocol of
every branch: the runner decides when each unfinished branch runs, passes on what it streams and reports whether one
paused.

```php
use Generator;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Executor\BranchRunner;
use NeuronAI\Workflow\Executor\Segment;

class ReversedBranchRunner implements BranchRunner
{
    public function run(Segment $segment, ParallelEvent $fork, string $forkStepId): Generator
    {
        $paused = false;
        foreach (array_reverse(array_keys($fork->branches)) as $branchId) {
            if ($segment->shouldPause()) {
                return true;
            }
            if ($fork->hasResult($branchId)) {
                continue;
            }

            $completed = yield from $segment->branch($fork, $branchId, $forkStepId);
            if (!$completed) {
                $paused = true;
            }
        }

        return $paused;
    }
}
```

Anything else a custom executor did, such as reading the persistence it held or wrapping the lifecycle, has no
extension point. Keep a reference to the persistence you configure, observe the lifecycle through events (guide 7),
and wrap nodes with middleware.

## What to Search For

```
grep -rnE "setExecutor\(|function executor\(" --include="*.php" .
grep -rnE "AsyncExecutor|WorkflowExecutor(Interface)?\b" --include="*.php" .
```

## Checklist

- No code calls `setExecutor()` or overrides `executor()`.
- No code references `AsyncExecutor`, `WorkflowExecutor` or `WorkflowExecutorInterface`.
- In workflows using `AsyncBranchRunner`, no node is reached by two branches of the same fork.
