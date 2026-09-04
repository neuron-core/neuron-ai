# Evaluation Module

Dataset-driven AI evaluation with flexible assertions and output drivers, including
first-class multi-turn conversation evaluation (tool calls, human-in-the-loop, simulated
users).

**Dependencies**: Agent, Chat, Workflow, Tools (typed against their general contracts:
`AgentInterface`, `ChatHistoryInterface`, `InterruptRequest`, `ToolCall`).

## Running Evaluations

```bash
vendor/bin/neuron evaluation path/to/evaluators
vendor/bin/neuron evaluation --verbose path/to/evaluators
vendor/bin/neuron evaluation --concurrency=8 path/to/evaluators
```

`--concurrency=N` runs dataset items in N parallel child processes (requires
`ext-pcntl` and `spatie/fork`; falls back to sequential otherwise). Each forked
item gets its own copy of the evaluator, so per-item side effects (shared
counters, appending to files) won't be visible across items. Evaluator outputs
must be serializable to cross the process boundary; non-serializable outputs
are replaced with a placeholder string in the results.

## Run Output Caching (`--cache`)

One sentence anchors the design: **the cache stores the output of `run()`, never the
verdict — `evaluate()` always executes fresh.** Skipping an unchanged run is sound
because a re-run of unchanged inputs yields no new information about your change (it
samples the same distribution); re-evaluating stays free and lets you iterate on
assertions, thresholds, and judges against frozen trajectories without re-running agents.

```bash
vendor/bin/neuron evaluation path/to/evaluators --cache    # skip unchanged runs
vendor/bin/neuron evaluation path/to/evaluators --fresh    # re-run all, overwrite cache
```

The cache key (`Cache/CacheKey.php`) is a content fingerprint of what determines
`run()`'s output:

- the evaluator class and the **source of its `run()` method only** — editing
  `evaluate()` does not invalidate recorded runs;
- declared dependencies via `BaseEvaluator::cacheDependencies()` (override it to
  return class-strings or file paths — your Agent class, prompt files). Undeclared
  dependencies are invisible to invalidation: use `--fresh` after changing them;
- the dataset item content (content-addressed — reordering a dataset invalidates
  nothing, appended items simply miss);
- the installed framework version.

Entries live in `.neuron/cache/evaluation/` (override via `'cache' => ['path' => ...]`
in `evaluation.php`), one file per key, written atomically so `--concurrency` children
can't collide. Storage honors the same contract as the fork boundary: a non-serializable
`run()` output is silently not cached. Cached items are flagged per result
(`EvaluatorResult::isCachedRun()`, `cached_run` in JSON) and counted in the console
summary — a skip is never confusable with a fresh run. Cache hits tell you nothing about
provider drift: pair `--cache` in CI with a periodic `--fresh` run.

`EvaluationCacheInterface` (`has`/`get`/`set`) is the storage seam;
`FileEvaluationCache` is the built-in driver. To wipe the cache, delete the directory.

## Architecture

**Template Method Pattern**: `BaseEvaluator` workflow:
1. `setUp()` - Initialize resources
2. `getDataset()` - Provide test data (abstract)
3. `run()` - Execute application logic (abstract)
4. `evaluate()` - Assert results (abstract)

## Core Components

| Directory | Purpose |
|-----------|---------|
| `Contracts/` | Interfaces: `EvaluatorInterface`, `DatasetInterface`, `AssertionInterface`, `EvaluationOutputInterface` |
| `BaseEvaluator.php` | Abstract base with assertion management |
| `Dataset/` | `ArrayDataset`, `JsonDataset` |
| `Assertions/` | Built-in: string (via `StringAssertion` base), JSON, similarity, distance; `Assertions/Trajectory/` for tool-call/HITL assertions; `Assertions/Judges/` for LLM judges |
| `Conversation/` | `Conversation` (multi-turn runner), `UserSimulator`, `SimulatorOutput` |
| `Trajectory/` | `Trajectory` — the recorded evaluation subject (view over chat messages) |
| `Runner/` | `EvaluatorRunner`, `EvaluatorResult`, `EvaluationResults`, `EvaluatorReport`, `EvaluationReport` |
| `Output/` | `ConsoleOutput`, `JsonOutput`, `OutputPipeline` |
| `Config/` | Config loading and driver resolution |
| `Discovery/` | Auto-discover evaluator classes |

### Assertion type contract

`AssertionInterface::evaluate(mixed $actual)` stays `mixed` (the polymorphic seam), but each
assertion family enforces its concrete input type and **throws `InvalidArgumentException`** on
a mismatch — a wrong input type is a coding error in the evaluator (surfaced as a per-item
*error* by the runner), never a failed assertion about the agent. `StringAssertion` and
`TrajectoryAssertion` are the shared bases; subclasses implement `evaluateString()` /
`evaluateTrajectory()`.

## Multi-Turn Conversation Evaluation

One sentence anchors the design: **you run a Conversation; you evaluate its Trajectory**.

### Trajectory — the recorded subject

A read-only **view over the original typed chat messages** — no parallel data schema.
Accessors answer evaluation questions directly from framework types:

```php
$trajectory = Trajectory::fromChatHistory($agent->getChatHistory()); // or fromMessages()

$trajectory->messages();          // Message[] — full fidelity
$trajectory->toolCalls('refund'); // ToolCall[] — one entry per call (the "fold":
                                  // pending snapshot + final outcome merged, final wins)
$trajectory->lastToolCall();      // ?ToolCall
$trajectory->finalAnswer();       // string ('' on a suspended tail)
$trajectory->usage();             // Usage — aggregated provider-reported tokens
$trajectory->toTranscript();      // canonical rendering (judges & simulator read this);
                                  // attachments described, approval decisions annotated
```

`fromMessages()` is a public seam: any hand-rolled multi-turn loop can project its history
and use the whole assertion/judge layer — the Conversation runner is sugar over it.
Serialization reuses the chat-history storage format, so a Trajectory survives the parallel
runner's fork boundary (messages carry `ToolCall` data). Gotchas:
a call that never executed has no result — check `ToolCall::hasResult()` before calling
`getResult()`; accessors return the LIVE call entries, which the approval flow annotates
in place on resume — read values, don't hold objects across a resume.

### Conversation — the runner

```php
public function run(array $item): mixed
{
    return Conversation::make($this->makeAgent())
        ->withTurns($item['turns'])              // scripted: list<string|UserMessage>
        ->withApprovals(function (InterruptRequest $request, Trajectory $soFar): array {
            $payload = [];
            foreach ($request->getActions() as $action) {
                if ($action->isPending()) {
                    $payload[$action->id] = 'approve';   // or ['reject', $reason]
                }
            }
            return $payload;
        })
        ->run();                                 // : Trajectory
}
```

- Each scripted turn is sent only after the previous one fully completed (including any
  suspend → decide → resume cycle).
- **Approval policy** (`withApprovals`): the callable plays the human whenever the agent
  suspends, at any point. Typed against the generic `InterruptRequest` — narrow with
  `instanceof` as needed. Fail-loud: a suspension with no policy throws
  `EvaluationException`, and for `ApprovalRequest`s the returned payload must cover every
  pending action id (an incomplete set would re-suspend and loop the runner).
- Simulated path: `->withUser($simulator, maxTurns: 10)` instead of `withTurns()` (mutually
  exclusive). `maxTurns` is required; hitting the cap ends the conversation *normally* —
  whether unfinished is a failure is the assertions' judgment.

### UserSimulator — goal-driven conversations

An `Agent` subclass that plays the user and declares its own stop:

```php
$simulator = UserSimulator::make()
    ->withPersona('An impatient customer')
    ->withGoal('Get a refund for order 123');
$simulator->setAiProvider($provider);   // returns AgentInterface — keep it off the chain
```

Each step is stateless (self-contained prompt: persona + goal + transcript; own history
flushed per call). The simulator never answers suspensions — the user and the approver are
different humans; approvals stay with the policy.

### Trajectory assertions

```php
$this->assert(new ToolWasCalled('refund_order', ['order_id' => '123']), $trajectory);
$this->assert(new ToolWasNotCalled('delete_account'), $trajectory);
$this->assert(new TrajectoryMatches(['search', 'refund_order'], Mode::Subset), $trajectory);
$this->assert(new ToolWasApproved('send_email'), $trajectory);
$this->assert(new ToolWasRejected('refund_order'), $trajectory);
```

`ToolWasCalled` takes an optional argument constraint (array = subset match, strict
equality per listed key; or `fn(array $inputs): bool`). `TrajectoryMatches` is names-only,
with `Mode::Strict` (exact sequence), `Unordered` (same multiset), `Subset` (in-order
subsequence, extras allowed), `Superset` (no call outside the allowed set). Final-answer
assertions deliberately don't exist: the string catalog and judges apply to
`$trajectory->finalAnswer()`.

### Transcript-aware judges

`AgentJudge` (and all prebuilt judges) accept `string|Trajectory` — a Trajectory is rendered
into the prompt via the protected `renderTranscript()` seam (default `toTranscript()`).
`TaskCompletionJudge` is the conversation-level judge: goal + full trajectory → did the
assistant accomplish it.

```php
$this->assert(new TaskCompletionJudge($this->judge, goal: $item['goal']), $trajectory);
```

## Creating Evaluators

```php
class MyEvaluator extends BaseEvaluator
{
    public function getDataset(): DatasetInterface {
        return new ArrayDataset([...]);
    }

    public function run(array $datasetItem): mixed {
        // Your application logic
        return $result;
    }

    public function evaluate(mixed $output, array $datasetItem): void {
        $this->assert(new StringContains('expected'), $output);
    }
}
```

## Named Scores & Per-Metric Aggregation

Every `assert()` records a `Score` (`label`, `value`, `passed`). The label defaults to the
assertion's `getName()` and can be overridden to aggregate under a shared metric name:

```php
$this->assert(new TaskCompletionJudge($this->judge, goal: $item['goal']), $trajectory, 'task_completion');
```

`EvaluationResults::getScoresByLabel()` groups the records;
`getScoreStatisticsByLabel()` returns per-metric `{average, min, max, count}` across the
dataset (rendered by `ConsoleOutput`, and emitted by `JsonOutput` as the `metrics` block
plus a per-result `scores` list). The flat float views (`EvaluatorResult::getAssertionScores()`,
`EvaluationResults::getAllAssertionScores()` and the result collection avg/min/max) are derived from
the same records — the result collection is the single home for statistics.

## Evaluator Attribution

The CLI passes one `EvaluationReport` to the output pipeline. It owns the exact UTC start and finish instants for the complete run and retains an ordered `EvaluatorReport` for each discovered evaluator. Each evaluator report owns its evaluator class, optional Agent/Workflow namespace, exact UTC start and finish instants, any error raised before item execution, and its `EvaluationResults`. Suite and evaluator durations are derived from their timestamps; item execution averages remain derived from individual results.

Empty and errored evaluators therefore remain visible. Every `EvaluatorResult` also carries the producing evaluator class (`getEvaluatorClass()` / `getShortEvaluatorClass()`) so flat result exports remain attributable. Item indexes restart at zero for each evaluator.

## Output Configuration

Create `evaluation.php` in project root. Each `output` entry is either a
class string of a zero-argument driver, or a fully-constructed driver instance.
Drivers that need constructor arguments (dependencies, options) **must** be
supplied as concrete instances - this lets the host framework resolve them
through its DI container.

```php
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;

return [
    'output' => [
        // Zero-argument driver, resolved as `new ConsoleOutput()`
        ConsoleOutput::class,

        // Constructed instance (e.g. to pass a path)
        new JsonOutput('results.json'),
    ],
];
```

## Custom Output Drivers

Implement `EvaluationOutputInterface`. Because drivers are registered as
instances, dependencies can be injected through the constructor:

```php
class DatabaseOutput implements EvaluationOutputInterface
{
    public function __construct(
        private readonly \PDO $pdo
    ) {
    }

    public function output(EvaluationReport $report): void
    {
        // Store in database
    }
}
```

Register the constructed instance in config:

```php
return [
    'output' => [
        new DatabaseOutput($container->get(\PDO::class)),
    ],
];
```

## Directory Setup

```json
// composer.json
{
    "autoload-dev": {
        "psr-4": {
            "App\\Evaluators\\": "evaluators/"
        }
    }
}
```
