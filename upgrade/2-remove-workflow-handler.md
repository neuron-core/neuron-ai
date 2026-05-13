# Upgrade: Remove WorkflowHandler and WorkflowHandlerInterface

## Summary

The `WorkflowHandler` class and `WorkflowHandlerInterface` have been removed. The intermediate handler step is no longer necessary — `run()` and `events()` are now called directly on the `Workflow` instance.

**Important:** This change only affects `Workflow` usage. The `Agent` class continues to return an `AgentHandler` from its `chat()` and `stream()` methods. Do not modify Agent handler usage.

## What to Search For

First, identify all Workflow classes in the application — these are classes that extend `Workflow`:

```
grep -rn "extends Workflow" --include="*.php" .
```

Then search for handler-related usage patterns:

1. **The `init()` call** — this was the method that created the WorkflowHandler:

```
grep -rn "->init()" --include="*.php" .
```

2. **WorkflowHandler import** — any file importing the removed classes:

```
grep -rn "WorkflowHandler" --include="*.php" .
```

3. **Handler variable patterns** — look for variables receiving the result of `init()` then calling `run()` or `events()`:

```
grep -rn "\$handler->run()\|\$handler->events()\|\$workflow->init()" --include="*.php" .
```

Also check config files, service containers, or route files where workflows may be instantiated and executed.

## Refactoring Instructions

### Case 1: One-shot execution with `run()`

**Before:**

```php
$handler = MyWorkflow::make()->init();
$finalState = $handler->run();
```

**After — call `run()` directly on the workflow:**

```php
$finalState = MyWorkflow::make()->run();
```

### Case 2: Streaming events with `events()`

**Before:**

```php
$handler = MyWorkflow::make()->init();

foreach ($handler->events() as $chunk) {
    // process events
}

$finalState = $handler->getResult();
```

**After — call `events()` directly, get state from the generator return value:**

```php
$generator = MyWorkflow::make()->events();

foreach ($generator as $chunk) {
    // process events
}

$finalState = $generator->getReturn();
```

Note: `getResult()` was a method on the handler. The new pattern uses PHP's `$generator->getReturn()` to retrieve the `WorkflowState` after the generator is fully consumed.

### Case 3: Workflow constructed with dependencies before `init()`

**Before:**

```php
$workflow = new MyWorkflow($someDependency);
$handler = $workflow->init();
$finalState = $handler->run();
```

**After:**

```php
$finalState = (new MyWorkflow($someDependency))->run();
```

Or with `make()` if the workflow uses the `StaticConstructor` trait:

```php
$finalState = MyWorkflow::make($someDependency)->run();
```

### Case 4: Handler stored in a variable for reuse

**Before:**

```php
$handler = MyWorkflow::make()->init();

$state = $handler->run();
// ... later ...
foreach ($handler->events() as $event) {
    // ...
}
```

**After — create separate workflow instances, or chain directly:**

```php
$state = MyWorkflow::make()->run();
// ... later, for streaming ...
$generator = MyWorkflow::make()->events();
foreach ($generator as $event) {
    // ...
}
$state = $generator->getReturn();
```

## What NOT to Change

- **Agent handler usage is unchanged.** If the code calls `$agent->chat()` or `$agent->stream()` and uses the returned `AgentHandler`, leave it as-is. The `AgentHandler` still exists and works the same way.
- **Do not remove `AgentHandler` imports or change Agent execution patterns.**
- Only refactor code that uses `Workflow::init()` or references `WorkflowHandler`/`WorkflowHandlerInterface`.

## Checklist

For each file you modify:

- [ ] All calls to `->init()` on Workflow instances are removed
- [ ] `$handler->run()` is replaced with `$workflow->run()`
- [ ] `$handler->events()` is replaced with `$workflow->events()` (store the generator in a variable)
- [ ] `$handler->getResult()` is replaced with `$generator->getReturn()`
- [ ] No imports of `WorkflowHandler` or `WorkflowHandlerInterface` remain
- [ ] Agent handler usage (`AgentHandler`) is left untouched
- [ ] The application still uses the same Workflow classes (extending `Workflow`) — only the invocation pattern changed
