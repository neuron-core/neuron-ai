# Upgrade: WorkflowHandler removed: run and stream the workflow directly

## Summary

In 3.x a workflow ran through a handler. `init()` (or the deprecated `start()`) returned a `WorkflowHandler`. Its `run()` returned the final state and its `events()` streamed. `Workflow::run()` and `Workflow::resume()` were public generators too. 4.x removes the handler, `init()`, `start()` and `resume()`. Call `run()` or `events()` on the workflow itself.

| 3.x | 4.x |
|---|---|
| `$workflow->init()` / `$workflow->start()` returned a `WorkflowHandler` | Removed. Call `run()` / `events()` on `$workflow` |
| `$handler->run()` / `$handler->start()` | `$workflow->run(): WorkflowState` (runs to the end before returning) |
| `$handler->events()` / `$handler->streamEvents()`, then `$handler->run()` for the state | `$stream = $workflow->events();`, loop over it, then `$stream->getReturn()` |
| `Workflow::run(): Generator`, iterated by the app | `Workflow::run(?ExecutionRequest $request = null): WorkflowState`. Iterate `events()` instead |
| `Workflow::resume(InterruptRequest): Generator` | Removed. Guide 15 migrates it |
| `WorkflowHandler` / `WorkflowHandlerInterface` types | `WorkflowState` (for the result) or `Generator` (for a stream) |
| Overrides of `init()`, `start()`, `run()`, `resume()` | One override of `events(?ExecutionRequest $request = null): Generator` |

Stored data is not affected: handlers were never persisted. Guide 14 covers persisted runs.

### Leave these to their guides

- **Construction and the workflow ID: guide 13.** Keep the `make()`/`new` arguments as they are and do not bind a workflow ID here. Until guide 13 is applied, every `run()`/`events()` you write throws `WorkflowException: This workflow has no workflow ID: bind one with setWorkflowId() first.`, and PHPStan reports the 3.x constructor arguments (`$persistence`, `resumeToken:`). Both are expected.
- **Streams with a stream adapter: guide 36.** This covers `->events($adapter)`, `->streamEvents($adapter)` and `->events(new VercelAIAdapter())`. Leave the whole chain untouched: the `init()`/`start()` that created the handler, the stream call and any later `$handler->run()`.
- **Resumes: guide 15.** This covers `init($request)`/`start($request)` with an argument, `->resume(...)`, the handlers they return, and every `catch (WorkflowInterrupt ...)` block around a workflow run (catch blocks around Agent or RAG calls belong to guide 29). If an argument-less `init()->run()` sits inside a `try`, rewrite the call and leave the `catch` block as it is.
- **Agent and RAG results: guide 23.** `$agent->chat()`/`stream()` returned a 3.x `AgentHandler`, which is a `WorkflowHandler` subclass, and `structured()` returned the output object. Leave all three call sites as they are.
- **State: guide 18.** `resolveState()` and `$this->state` in subclasses.
- **Direct `WorkflowInterface` implementations: guide 57.**

## What to Search For

Run from the application root:

```bash
# 1. Handler types, init()/start(), the deprecated aliases, resume()
grep -rnE -e 'WorkflowHandler(Interface)?\b' -e '->(init|start|streamEvents|resume)\(' --include='*.php' --exclude-dir=vendor .

# 2. Handler calls and the 3.x generator Workflow::run()
grep -rnE -e '->(run|events)\(\)' --include='*.php' --exclude-dir=vendor .

# 3. Workflow, Agent and RAG subclasses, and overrides of the removed methods
grep -rnE -e 'extends [[:alnum:]_\\]*(Workflow|Agent|RAG)\b' -e 'function (init|start|run|resume)\(' --include='*.php' --exclude-dir=vendor .
```

Sort each hit by its receiver:

- **A Workflow instance, or a handler from its `init()`/`start()`:** this guide migrates it. Follow each `init()`/`start()` to the variable that holds the handler. The later `run()`/`start()`/`events()`/`streamEvents()` calls on that variable belong to the same rewrite, and so do the helpers it is passed to.
- **`->run()` on a Workflow whose result is iterated, returned or passed on** (`foreach`, `iterator_to_array()`, `yield from`, `->current()`, `->getReturn()`, `return`, a function argument; follow it to the code that iterates it): Case 3.
- **Out of scope:** hits listed under "Leave these to their guides", and unrelated libraries (`->start()` on a process or session, `->run()` on a job or command). Leave them.
- **Search 3:** keep `function` hits that are declared in classes extending Workflow, Agent or RAG, either directly or through another app class. They are Case 5.

Also check container definitions, route files, console commands, queued jobs and tests that run workflows. If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: Eager run

Before (3.x):

```php
$workflow = OrderWorkflow::make();   // construction: unchanged in this guide

$handler = $workflow->init();        // or $workflow->start()
$state = $handler->run();            // or $handler->start()

$state = $workflow->init()->run();   // chained form; same for ->start()->run()
```

After (4.x):

```php
$workflow = OrderWorkflow::make();

$state = $workflow->run();           // NeuronAI\Workflow\WorkflowState
```

In 3.x, calling `$handler->run()` on a handler that had already run returned the cached state. Reuse `$state` there. A second `$workflow->run()` would run the workflow again.

### Case 2: Streaming events without an adapter

Before (3.x):

```php
$handler = $workflow->init();

foreach ($handler->events() as $event) {   // or $handler->streamEvents()
    if ($event instanceof ProgressEvent) {
        echo $event->message . PHP_EOL;
    }
}

$state = $handler->run();                  // the result cached by the loop above
```

After (4.x):

```php
$stream = $workflow->events();

foreach ($stream as $event) {
    if ($event instanceof ProgressEvent) {
        echo $event->message . PHP_EOL;
    }
}

$state = $stream->getReturn();
```

Call `getReturn()` only after the loop has finished, because it throws on a generator that has not returned yet. Some 3.x code called `getReturn()` on the generator from `$handler->events()`. Rewrite it the same way.

### Case 3: Iterating the 3.x `Workflow::run()` generator

Before (3.x):

```php
foreach ($workflow->run() as $event) {
    // ...
}

$generator = $workflow->run();
foreach ($generator as $event) {
    // ...
}
$state = $generator->getReturn();

iterator_to_array($workflow->run());       // drained only to run it

return yield from $workflow->run();        // inside an app generator
```

After (4.x):

```php
$stream = $workflow->events();
foreach ($stream as $event) {
    // ...
}
$state = $stream->getReturn();

$state = $workflow->run();                 // drained only to run it

return yield from $workflow->events();
```

- 4.x `run()` returns a `WorkflowState`, not a generator. A `foreach` over it streams nothing, and `->getReturn()` on it is a fatal error.
- In 3.x, a `$workflow->run()` whose result nothing iterated did not run the workflow, because the generator was lazy. 4.x `run()` runs it at the call. Ask the developer whether that call should now run the workflow or be deleted. If it should run, write `$workflow->run();`, and assign the result only where it is used.
- Leave `$workflow->resume($request)` for guide 15.

### Case 4: Handler types, helpers and custom handlers

Before (3.x):

```php
use Generator;
use NeuronAI\Workflow\WorkflowHandlerInterface;

class ReportService
{
    public function finish(WorkflowHandlerInterface $handler): string
    {
        return $handler->run()->get('report');
    }

    public function relay(WorkflowHandlerInterface $handler): Generator
    {
        foreach ($handler->events() as $event) {
            yield $event;
        }

        return $handler->run();
    }
}

$report = $service->finish($workflow->init());
$relay = $service->relay($workflow->init());
```

After (4.x):

```php
use Generator;
use NeuronAI\Workflow\WorkflowState;

class ReportService
{
    public function finish(WorkflowState $state): string
    {
        return $state->get('report');
    }

    public function relay(Generator $stream): Generator
    {
        foreach ($stream as $event) {
            yield $event;
        }

        return $stream->getReturn();
    }
}

$report = $service->finish($workflow->run());
$relay = $service->relay($workflow->events());
```

1. Retype parameters, properties, return types and `@var`/`@param` docblocks. Use `WorkflowState` where the code needed the result and `Generator` where it streamed. Remove the `use NeuronAI\Workflow\WorkflowHandler;` and `use NeuronAI\Workflow\WorkflowHandlerInterface;` lines. Skip a helper that passes an adapter to the handler it receives (`$handler->events($adapter)`/`streamEvents($adapter)`), together with the call sites that feed it: guide 36 retypes it.
2. Replace `new WorkflowHandler($workflow)` with a `run()`/`events()` call on `$workflow`. If it has a second argument (a resume request), write `$workflow->init($request)` in its place and leave it for guide 15.
3. Delete app classes that extend `WorkflowHandler` or implement `WorkflowHandlerInterface`, and call the workflow directly. Move any behaviour such a class added around the run into an `events()` override (Case 5).
4. Some helpers also receive `$agent->chat()`/`stream()` results. They take the same new types: after guide 23, `chat()` returns an `AgentState`, which extends `WorkflowState`, and `stream()` returns a `Generator`. Leave the Agent call sites for guide 23.

### Case 5: Overrides of `init()`, `start()`, `run()` or `resume()`

This case applies to any class that extends Workflow, Agent or RAG. 4.x has no `init()`, `start()` or `resume()`, and `run()` has a new signature. Every execution goes through `events()`: `run()`, `events()`, resumes, and Agent `chat()`/`stream()`/`structured()`. So merge the overrides into a single `events()` override.

Before (3.x):

```php
use Generator;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowHandlerInterface;

class OrderWorkflow extends Workflow
{
    public function init(?InterruptRequest $resumeRequest = null): WorkflowHandlerInterface
    {
        $this->loadCatalog();

        return parent::init($resumeRequest);
    }

    public function run(): Generator
    {
        $this->logger->info('Order workflow started');

        $state = yield from parent::run();

        $this->logger->info('Order workflow finished');

        return $state;
    }

    public function resume(InterruptRequest $resumeRequest): Generator
    {
        $this->logger->info('Order workflow resumed');

        return yield from parent::resume($resumeRequest);
    }
}
```

After (4.x):

```php
use Generator;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Workflow;

class OrderWorkflow extends Workflow
{
    public function events(?ExecutionRequest $request = null): Generator
    {
        $resuming = $request?->starting === false;

        $this->loadCatalog();                                    // was init(): starts and resumes

        $this->logger->info($resuming ? 'Order workflow resumed' : 'Order workflow started');

        $state = yield from parent::events($request);

        if (!$resuming && !$state->isInterrupted()) {            // 3.x run() got here only when a start completed
            $this->logger->info('Order workflow finished');
        }

        return $state;
    }
}
```

1. Move the body of an `init()`/`start()` override to the top of `events()`. In 3.x it ran for both starts and resumes.
2. Move the body of a `run()` override into `events()` and replace `parent::run()` with `parent::events($request)`. Guard it with `!$resuming`, because 3.x resumes did not call `run()`.
3. Move the body of a `resume()` override into `events()`, under `if ($resuming)`.
4. Guard any code after the `yield from` that must run only on completion with `!$state->isInterrupted()`. In 4.x a pause returns the state instead of throwing `WorkflowInterrupt`.
5. Always return the value of `yield from parent::events($request)`, because `run()` returns it as the final state.
6. An `init()`/`start()` override on an Agent or RAG subclass never ran from 3.x `chat()`/`stream()`/`structured()`. Delete it without moving its body. If the app called `$agent->init()`/`start()` itself, ask the developer what that call was for.

## Checklist

- [ ] Search 1 finds no `WorkflowHandler` or `WorkflowHandlerInterface`, except in helpers that stream their handler through an adapter (left for guide 36).
- [ ] Every remaining `->init(`/`->start(` on a workflow falls into one of two groups. Either it has an argument (a resume, left for guide 15), or it creates a handler whose `events()`/`streamEvents()` gets an adapter (left for guide 36). Every remaining `->streamEvents(` has an adapter argument, and `->resume(` remains only for guide 15.
- [ ] No search 2 hit iterates a workflow's `run()` result (`foreach`, `iterator_to_array()`, `yield from`, `->getReturn()`) or reads a workflow handler, except the handlers returned by `init($request)`/`start($request)` (guide 15) and the handlers streamed through an adapter (guide 36).
- [ ] Every `getReturn()` you wrote comes after the loop over the same generator has finished.
- [ ] No class extending Workflow, Agent or RAG declares `init()`, `start()`, `resume()` or the 3.x `run(): Generator`. Their logic is now in `events(?ExecutionRequest $request = null): Generator`, which returns `yield from parent::events($request)`.
- [ ] The following are unchanged: constructor/`make()` arguments, `catch (WorkflowInterrupt ...)` blocks, Agent/RAG verb results, and adapter streams.
- [ ] PHPStan reports no error about `WorkflowHandler(Interface)`, or about `init()`/`start()`/`streamEvents()`/`resume()`/`run()` on a workflow, outside the call sites left for guides 15 and 36.
