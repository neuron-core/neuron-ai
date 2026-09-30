# Upgrade: Parallel branches: branch runners replace executors, branches are named

## Summary

You can no longer replace the executor. The only execution strategy left to configure is how the branches of a `ParallelEvent` fork run, and a `BranchRunner` decides that. Branches must also be named: a `ParallelEvent` no longer derives branch IDs from a list.

| 3.x | 4.x |
|---|---|
| `setExecutor(new AsyncExecutor())` | `setBranchRunner(new AsyncBranchRunner())` |
| `setExecutor(new WorkflowExecutor())` | delete the call: `SequentialBranchRunner` is the default |
| `protected function executor(): WorkflowExecutorInterface` | `protected function branchRunner(): BranchRunner` |
| `NeuronAI\Workflow\Executor\AsyncExecutor` / `WorkflowExecutor` | `NeuronAI\Workflow\Executor\AsyncBranchRunner` / `SequentialBranchRunner` |
| `class X extends WorkflowExecutor` overriding `executeParallelBranches()` | `class X implements NeuronAI\Workflow\Executor\BranchRunner` |
| `WorkflowExecutorInterface`, `NeuronAI\Workflow\Interrupt\BranchInterrupt`, the 3.x `BranchResult` | removed, no replacement |
| `$this->executor()->getPersistence()` in a subclass | `$this->getPersistence()` |
| `new MyParallelEvent([new A(), new B()])`, IDs `A::class` and `B::class` | `new MyParallelEvent([A::class => new A(), B::class => new B()])` |
| `getResult()` for a branch without a result: warning and `null` | throws `NeuronAI\Exceptions\WorkflowException` |
| `hasResult()` is false for a branch that completed with `null` | true |

- `setBranchRunner(BranchRunner $runner): static` is declared on `Workflow`, so `Agent` and `RAG` inherit it. It is not declared on `WorkflowInterface`.
- `setBranchRunner()` beats a `branchRunner()` override. This reverses 3.x, where an `executor()` override beat `setExecutor()` (Case 5).

Stored data: nothing this guide migrates is stored. Runs paused under 3.x, including runs paused inside a branch, cannot be resumed by 4.x. Guide 14 covers draining them.

## What to Search For

Run from the application root:

```bash
# 1. Executors: calls, hooks, the executor property, custom executors, container definitions
grep -rnE 'setExecutor|function executor\(|->executor\b|AsyncExecutor|WorkflowExecutor|\bBranchInterrupt\b|\bBranchResult\b' --include='*.php' --include='*.yaml' --include='*.yml' --include='*.xml' --exclude-dir=vendor .

# 2. Forks and join nodes
grep -rnE 'extends ParallelEvent\b|ParallelEvent\(|ParallelEvent \$' --include='*.php' --exclude-dir=vendor .
```

Follow the hits:
- For every class that extends `WorkflowExecutor` or `AsyncExecutor`, grep its name to find where it is registered: `setExecutor(new X())`, container definitions, `X::class` (Case 6).
- For every `setExecutor(` call, check two things. What type does the receiving variable have (Case 3)? Does the workflow's class, or one of its parents, override `executor()` (Case 5)?
- `->executor` hits matter only inside classes that extend Workflow, Agent or RAG (Case 4). Ignore unrelated properties with the same name.
- For every `ParallelEvent` subclass that search 2 finds, grep its short name. `new <Name>(` calls are forks (Case 7). `<Name> $` parameters are join nodes (Case 8). Also read the subclass's own constructor.
- `instanceof <Name>` or `instanceof ParallelEvent` inside a middleware `after()`: Case 9.

If both searches find nothing, this guide does not apply.

## How to Refactor

### Case 1: Choosing how branches run on an instance

Before (3.x, with the workflow ID that guide 13 bound):

```php
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Workflow;

$workflow = Workflow::make($documentId)
    ->setExecutor(new AsyncExecutor())
    ->addNodes([new ExtractText(), new AnalyzeImages()]);
```

After (4.x):

```php
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Workflow;

$workflow = Workflow::make($documentId)
    ->setBranchRunner(new AsyncBranchRunner())
    ->addNodes([new ExtractText(), new AnalyzeImages()]);
```

1. Replace `use NeuronAI\Workflow\Executor\AsyncExecutor;` with `use NeuronAI\Workflow\Executor\AsyncBranchRunner;`. The call chains the same way, on Workflow, Agent and RAG instances.
2. `setExecutor(new WorkflowExecutor())` selected the default, so delete it. There is one exception. If the workflow's class chose `AsyncExecutor` in its constructor (Case 4), the call switched back to sequential in 3.x. Write `setBranchRunner(new SequentialBranchRunner())` there instead (`use NeuronAI\Workflow\Executor\SequentialBranchRunner;`).
3. In container definitions, rename the `setExecutor` call to `setBranchRunner`, and rename the `AsyncExecutor` service to `NeuronAI\Workflow\Executor\AsyncBranchRunner`.
4. If two branches of a fork run by `AsyncBranchRunner` reach the same node, report it to the developer. Do not restructure the graph.

### Case 2: A subclass choosing its executor in the hook

Before (3.x):

```php
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
use NeuronAI\Workflow\Workflow;

class DocumentWorkflow extends Workflow
{
    protected function executor(): WorkflowExecutorInterface
    {
        return new AsyncExecutor();
    }
}
```

After (4.x):

```php
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Executor\BranchRunner;
use NeuronAI\Workflow\Workflow;

class DocumentWorkflow extends Workflow
{
    protected function branchRunner(): BranchRunner
    {
        return new AsyncBranchRunner();
    }
}
```

If the override returns `new WorkflowExecutor()`, delete it.

### Case 3: `setExecutor()` on a value typed `WorkflowInterface`

Before (3.x):

```php
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\WorkflowInterface;

class WorkflowConfigurator
{
    public function concurrent(WorkflowInterface $workflow): WorkflowInterface
    {
        return $workflow->setExecutor(new AsyncExecutor());
    }
}
```

After (4.x):

```php
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Workflow;

class WorkflowConfigurator
{
    public function concurrent(Workflow $workflow): Workflow
    {
        return $workflow->setBranchRunner(new AsyncBranchRunner());
    }
}
```

- Retype the variable, parameter or property to `NeuronAI\Workflow\Workflow`, or to the concrete subclass.
- A class that implements `WorkflowInterface` directly: delete its `setExecutor()` method. Guide 57 migrates the rest of its contract.

### Case 4: Subclass code that used the executor

Before (3.x):

```php
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;

class DocumentWorkflow extends Workflow
{
    public function __construct(protected PersistenceInterface $store)
    {
        parent::__construct();
        $this->executor = new AsyncExecutor();
        $this->executor->setPersistence($store);
    }

    protected function persistenceBackend(): ?PersistenceInterface
    {
        return $this->executor()->getPersistence();
    }
}
```

After (4.x):

```php
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Executor\BranchRunner;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;

class DocumentWorkflow extends Workflow
{
    public function __construct(protected PersistenceInterface $store)
    {
        parent::__construct();
        $this->setPersistence($store);
    }

    protected function branchRunner(): BranchRunner
    {
        return new AsyncBranchRunner();
    }

    protected function persistenceBackend(): PersistenceInterface
    {
        return $this->getPersistence();
    }
}
```

| 3.x, inside a Workflow, Agent or RAG subclass | 4.x |
|---|---|
| `$this->executor()->getPersistence()`, `$this->executor->getPersistence()` | `$this->getPersistence()` (final protected; it never returns `null`) |
| `$this->executor()->setPersistence($p)`, `$this->executor->setPersistence($p)` | `$this->setPersistence($p)` |
| `$this->executor = new AsyncExecutor();` or `$this->setExecutor(new AsyncExecutor());` in the constructor | a `branchRunner()` override (Case 2). A choice made under a condition becomes `$this->setBranchRunner(new AsyncBranchRunner());` in the same place |
| `$this->executor()->run($this)`, `$this->executor()->resume($this, ...)` | handle them like `parent::run()` / `parent::resume()` in guide 12: `yield from parent::events($request)` |

### Case 5: A hook override and `setExecutor()` calls on the same workflow

In 3.x an `executor()` override won, so `setExecutor()` calls on instances of that class had no effect. After Cases 1 and 2, `setBranchRunner()` wins over the `branchRunner()` override. Where the hook's choice must still apply, as it did in 3.x, remove the `setBranchRunner()` call. Tell the developer that this call never took effect in 3.x.

### Case 6: A custom executor

Before (3.x):

```php
use Generator;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Interrupt\BranchInterrupt;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowInterface;

class ReversedExecutor extends WorkflowExecutor
{
    protected function executeParallelBranches(
        WorkflowInterface $workflow,
        ParallelEvent $parallelEvent,
        ?WorkflowInterrupt $interrupt = null,
        ?InterruptRequest $resumeRequest = null,
    ): Generator {
        foreach (array_reverse($parallelEvent->branches, true) as $branchId => $branchEvent) {
            if ($parallelEvent->hasResult($branchId)) {
                continue;
            }

            $isResuming = $branchId === $interrupt?->getBranchId();

            try {
                $result = $this->executeBranch(
                    $workflow,
                    $branchId,
                    $isResuming ? $interrupt->getEvent() : $branchEvent,
                    $isResuming ? $resumeRequest : null,
                    $isResuming ? $interrupt->getNode() : null,
                );

                $parallelEvent->setResult($branchId, $result->result);

                foreach ($result->streamedEvents as $streamedEvent) {
                    yield $streamedEvent;
                }
            } catch (BranchInterrupt $branchInterrupt) {
                throw new WorkflowInterrupt(
                    request: $branchInterrupt->original->getRequest(),
                    node: $branchInterrupt->original->getNode(),
                    state: $workflow->resolveState(),
                    event: $branchInterrupt->original->getEvent(),
                    branchId: $branchInterrupt->branchId,
                    parallelEvent: $parallelEvent,
                    completedBranchResults: $parallelEvent->getAllResults(),
                );
            }
        }

        return $parallelEvent;
    }
}

$workflow->setExecutor(new ReversedExecutor());
```

After (4.x):

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

$workflow->setBranchRunner(new ReversedBranchRunner());
```

Replace the whole class, including any part of it that guide 15 already changed:
1. Change `extends WorkflowExecutor` to `implements BranchRunner`. Replace `executeParallelBranches(...)` with `public function run(Segment $segment, ParallelEvent $fork, string $forkStepId): Generator`.
2. Loop over `array_keys($fork->branches)` in the order the old loop used.
3. Before each branch, add `if ($segment->shouldPause()) { return true; }`. Keep skipping branches where `$fork->hasResult($branchId)` is true.
4. Replace `executeBranch()`, `setResult()` and the loop that re-yields `streamedEvents` with `$completed = yield from $segment->branch($fork, $branchId, $forkStepId);`. This call records the result and streams the branch's output as it is produced.
5. Replace the `catch (BranchInterrupt ...)` block that throws a `WorkflowInterrupt` with `if (!$completed) { $paused = true; }`. Return `$paused` (a bool) instead of the `ParallelEvent`.
6. Delete the `$interrupt` and `$resumeRequest` parameters and the `$isResuming` logic. 4.x resumes the paused branch by itself.
7. Change the registration from `setExecutor(new ReversedExecutor())` to `setBranchRunner(new ReversedBranchRunner())`, in code and in container definitions.
8. Do not port `BranchInterrupt` or the 3.x `BranchResult`. `NeuronAI\Workflow\Executor\BranchResult` still exists in 4.x, but it is an internal value of `AsyncBranchRunner` with a different constructor.

Other executors:
- A subclass of `AsyncExecutor` that ran branches as concurrent futures: if it only reproduced `AsyncExecutor`, use `AsyncBranchRunner`. If it did more, report it to the developer. A concurrent runner must drive each `$segment->branch()` generator in its own fiber, as `AsyncBranchRunner` does.
- An executor that overrides `run()`, `resume()`, `traverseNodes()`, `executeNode()`, `executeBranch()`, `runBeforeMiddleware()`, `runAfterMiddleware()`, `workflowEnd()`, `setPersistence()` or `getPersistence()`, or that implements `WorkflowExecutorInterface` directly, has no replacement. The class cannot stay, because its parent class or interface no longer exists. Ask the developer what to do with the behaviour it added. There are two nearest 4.x mechanisms: event listeners for lifecycle observation (guide 46 introduces `subscribe()`), and workflow middleware for logic around each node (guide 18 migrates its signature). The developer may also choose to drop the behaviour.

### Case 7: Forks that pass a list of branches

3.x derived each branch ID from the event's fully qualified class name. 4.x throws `WorkflowException: Parallel branches must use non-empty string names.`

Before (3.x):

```php
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class AnalyzeDocument extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): DocumentParallelEvent
    {
        return new DocumentParallelEvent([
            new ExtractTextEvent(),
            new AnalyzeImagesEvent(),
        ]);
    }
}
```

After (4.x):

```php
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

class AnalyzeDocument extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): DocumentParallelEvent
    {
        return new DocumentParallelEvent([
            ExtractTextEvent::class => new ExtractTextEvent(),
            AnalyzeImagesEvent::class => new AnalyzeImagesEvent(),
        ]);
    }
}
```

1. Key each event by its class name. This keeps the 3.x IDs, so join nodes, `getAllResults()` keys, `$this->branchId` comparisons and listeners that read branch IDs keep working.
2. Apply the same change to lists built in a `ParallelEvent` subclass constructor, with `array_map()`, or in a loop. Numeric strings such as `'0'` become integer keys in PHP and are rejected too.
3. In 3.x, several events of the same class in one list ran only the last one, because they shared one ID. Ask the developer which behaviour they want. They can keep one branch keyed by the class name that holds the last event. Or they can run every event under its own non-numeric key, such as `'summary-0'` and `'summary-1'`, and then decide how the join node reads those results.

### Case 8: Join nodes reading branch results

Before (3.x):

```php
class CompileResults extends Node
{
    public function __invoke(DocumentParallelEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('text', $event->getResult(ExtractTextEvent::class));

        if ($event->hasResult(AnalyzeImagesEvent::class)) {
            $state->set('images', $event->getResult(AnalyzeImagesEvent::class));
        }

        return new StopEvent();
    }
}
```

After (4.x):

```php
class CompileResults extends Node
{
    public function __invoke(DocumentParallelEvent $event, WorkflowState $state): StopEvent
    {
        $state->set('text', $event->getResult(ExtractTextEvent::class));

        if ($event->hasResult(AnalyzeImagesEvent::class) && $event->getResult(AnalyzeImagesEvent::class) !== null) {
            $state->set('images', $event->getResult(AnalyzeImagesEvent::class));
        }

        return new StopEvent();
    }
}
```

1. `getResult($name)` for a branch without a result now throws `WorkflowException: No completed parallel branch is named '<name>'.`. In 3.x it gave a warning and `null`. Every name a join node reads must be a key of its fork. Where a name may be absent, guard the read: `$event->hasResult($name) ? $event->getResult($name) : null`.
2. A join node may read a name that 3.x never produced. One example is the short class name that the 3.x docs promised, while the code actually used the fully qualified name. In 3.x that read always returned `null`. Ask the developer whether it should read the real branch or keep `null`.
3. `hasResult()` is now true for a branch that completed with a `null` result. Where it served as a non-null check, write `$event->hasResult($name) && $event->getResult($name) !== null`.

### Case 9: Middleware that reads branch results after the fork node

In 3.x, `after()` of a middleware registered on the fork node (or of a global middleware) ran once every branch had finished, so the `ParallelEvent` it received held the branch results. In 4.x it runs as soon as the fork node returns, before any branch starts: `getAllResults()` is `[]` and `getResult()` throws.

Before (3.x):

```php
class LogBranchResults implements WorkflowMiddleware
{
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void {}

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        if ($result instanceof DocumentParallelEvent) {
            $this->logger->info('branches', $result->getAllResults());
        }
    }
}

$workflow->addMiddleware(AnalyzeDocument::class, new LogBranchResults());
```

1. Move the code that reads results from `after()` into `before()`, and register that middleware on the join node (the node whose `__invoke()` takes the `ParallelEvent` subclass) instead of the fork node: `$workflow->addMiddleware(CompileResults::class, new LogBranchResults());`.
2. For a global middleware, move the `instanceof ...ParallelEvent` block from `after()` to `before()`.
3. Leave the `before()`/`after()` signatures alone: guide 18 adds the fourth parameter.

## Checklist

- Search 1 finds nothing, apart from app classes unrelated to Neuron that have an `executor` member.
- No class extends `WorkflowExecutor` or `AsyncExecutor` or implements `WorkflowExecutorInterface`. Custom branch scheduling lives in classes that implement `BranchRunner`, and those classes are registered with `setBranchRunner()`.
- `setBranchRunner()` is called only on values typed `Workflow`, `Agent`, `RAG` or one of their subclasses.
- No subclass reads `$this->executor` or calls `$this->executor()`. Persistence is read with `$this->getPersistence()`.
- A class that overrides `branchRunner()` has no `setBranchRunner()` calls on its instances, unless the 3.x `setExecutor()` call there took effect (a constructor-chosen executor, Case 1 step 2).
- Every `ParallelEvent` is built with non-empty, non-numeric string keys. Same-class lists were reported to the developer.
- Every `getResult()` name is a key of its fork or is guarded with `hasResult()`. Every `hasResult()` used as a non-null check also compares `getResult()` with `null`.
- Forks run by `AsyncBranchRunner` whose branches reach the same node were reported to the developer, not changed.
- No middleware `after()` reads `ParallelEvent` results. That code runs in `before()` on the join node.
- PHPStan reports no error about `setExecutor`, `executor()`, `WorkflowExecutor`, `AsyncExecutor`, `BranchInterrupt`, `BranchResult`, `setBranchRunner` or `branchRunner()`.
