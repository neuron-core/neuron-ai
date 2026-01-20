## Summary

This PR fixes an observer accumulation bug in `EventBus` that affects long-running PHP processes such as:

- Laravel queue workers (`php artisan queue:work`)
- PHP-FPM with persistent workers
- Swoole/RoadRunner applications
- PHPUnit test suites

## The Problem

`EventBus` uses static storage for observers. When `observe()` is called, observers are appended to a static array that persists for the lifetime of the PHP process. In short-lived request cycles this is fine, but in long-running processes, observers accumulate:

```php
// Job 1
$agent->observe(new MyObserver($task1));  // 1 observer

// Job 2 (same PHP process)
$agent->observe(new MyObserver($task2));  // Now 2 observers!

// Events fire - BOTH observers receive events
// Observer 1 references stale $task1 - causes bugs
```

### Real-World Impact

We discovered this bug in a Laravel queue worker processing AI automation tasks. After multiple jobs, observers accumulated and caused:

1. **Duplicate log entries** - Each event logged N times (N = job count)
2. **Foreign key violations** - Old observers referenced deleted database records
3. **Memory growth** - Observer array grew unbounded

## The Solution

### Part 1: Observer Lifecycle Methods (Manual Cleanup)

1. **`removeObserver(ObserverInterface $observer): bool`**
   - Explicitly remove a specific observer
   - Returns `true` if removed, `false` if not found
   - Useful for cleanup in `finally` blocks or destructors

2. **`hasObserver(ObserverInterface $observer): bool`**
   - Check if an observer is already registered
   - Prevents duplicate registration

3. **`getObserverCount(): int`**
   - Returns the current observer count
   - Useful for debugging accumulation issues

### Part 2: Scoped Observers (Automatic Cleanup) ✨

**The preferred solution.** Observers registered via `Workflow::observe()` are automatically scoped to that workflow instance and cleaned up when the workflow completes.

```php
// OLD WAY - Manual cleanup required
$workflow = new MyWorkflow();
EventBus::observe($observer);  // Persists forever!
$workflow->init()->run();
EventBus::clear();  // Must remember to call this

// NEW WAY - Automatic cleanup
$workflow = new MyWorkflow();
$workflow->observe($observer);  // Scoped to this workflow
$workflow->init()->run();       // Observer auto-cleared on completion!
```

#### New Scope Methods

4. **`observe(ObserverInterface $observer, ?string $scope = null): void`**
   - Now accepts optional scope parameter
   - Scoped observers can be selectively cleared

5. **`clearScope(string $scope): int`**
   - Clears only observers with the specified scope
   - Returns the number of observers removed
   - Does NOT affect unscoped observers

6. **`getObserverScope(ObserverInterface $observer): ?string`**
   - Returns the scope of an observer, or null if unscoped

### How Scoping Works

```
Workflow A registers Observer 1 (scoped to A)
Workflow B registers Observer 2 (scoped to B)
Global code registers Observer 3 (no scope)

Workflow A completes → Only Observer 1 is removed
Workflow B completes → Only Observer 2 is removed
Observer 3 persists across all workflows
```

## Migration Guide

### For Queue Workers (Recommended: Scoped Observers)

Simply use `$workflow->observe()` instead of `EventBus::observe()`:

```php
public function handle(): void
{
    $workflow = new MyWorkflow();
    $workflow->observe(new MyObserver());  // Scoped!
    $workflow->init()->run();              // Auto-cleared!
}
```

### For Queue Workers (Alternative: Manual Clear)

If you're not using workflows, call `EventBus::clear()` at the start of each job:

```php
public function handle(): void
{
    EventBus::clear();  // Clear all observers

    $agent = new MyAgent();
    $agent->observe(new MyObserver());
    // ...
}
```

### For Concurrent Workflows

Scoped observers enable safe concurrent execution:

```php
// Both can run concurrently without interfering
$workflowA->observe($observerA);  // Scoped to A
$workflowB->observe($observerB);  // Scoped to B

// When A completes, only $observerA is cleared
// $observerB continues receiving events from B
```

## Backwards Compatibility

This PR is fully backwards compatible:

- `observe($observer)` without scope works exactly as before
- Unscoped observers persist until `removeObserver()` or `clear()` is called
- Existing code that calls `clear()` manually still works
- New scope methods are additive only

## Testing

All 19 tests pass (11 original + 8 new scoped tests):

```
PHPUnit 9.6.31 by Sebastian Bergmann and contributors.

...................                                               19 / 19 (100%)

Time: 00:00.013, Memory: 6.00 MB

OK (19 tests, 49 assertions)
```

### New Test Coverage

- `test_observe_with_scope_tracks_scope`
- `test_observe_without_scope_has_null_scope`
- `test_clear_scope_removes_only_scoped_observers`
- `test_clear_scope_returns_zero_for_unknown_scope`
- `test_remove_observer_also_removes_scope_tracking`
- `test_clear_also_clears_scope_tracking`
- `test_workflow_completion_clears_only_its_observers`
- `test_scoped_observers_receive_events`

## Files Changed

| File | Change |
|------|--------|
| `src/Observability/EventBus.php` | Added scope tracking, `clearScope()`, `getObserverScope()` |
| `src/Workflow/Workflow.php` | `observe()` now scopes to workflow ID |
| `src/Workflow/WorkflowHandler.php` | Auto-calls `clearScope()` after workflow completion |
| `tests/Observability/EventBusTest.php` | Added 8 scoped observer tests |

## Checklist

- [x] Code follows project style guidelines
- [x] Added unit tests for new functionality
- [x] Updated PHPDoc documentation
- [x] No breaking changes
- [x] Tests pass locally
