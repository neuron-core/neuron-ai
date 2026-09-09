# Evaluation Module

Dataset-driven evaluation of agents and workflows, including multi-turn conversations with tool calls, human-in-the-loop approvals and simulated users. It types against the general contracts (`AgentInterface`, `ChatHistoryInterface`, `InterruptRequest`, `ToolCall`), so anything built on the framework is evaluable.

## Evaluator = template method

`BaseEvaluator` fixes the shape: `setUp()`, `getDataset()` (the items), `run($item)` (execute the application, return its output) and `evaluate($output, $item)` (assert). `EvaluatorDiscovery` finds evaluator classes in a directory and `EvaluatorRunner` executes them; `vendor/bin/neuron evaluation <path>` is the CLI.

```php
class RefundEvaluator extends BaseEvaluator
{
    protected AgentInterface $judge;

    public function setUp(): void
    {
        $this->judge = JudgeAgent::make();
    }

    public function getDataset(): DatasetInterface
    {
        return new JsonDataset(__DIR__.'/refunds.json');
    }

    public function run(array $item): mixed
    {
        return Conversation::make(RefundAgent::make())
            ->withTurns($item['turns'])
            ->withApprovals(function (ApprovalRequest $request, Trajectory $soFar): array {
                $decisions = [];
                foreach ($request->getActions() as $action) {
                    $decisions[$action->id] = 'approve';
                }
                return $decisions;
            })
            ->run();
    }

    public function evaluate(mixed $trajectory, array $item): void
    {
        $this->assert(new ToolWasCalled('refund_order', ['order_id' => $item['order_id']]), $trajectory);
        $this->assert(new TaskCompletionJudge($this->judge, goal: $item['goal']), $trajectory, 'task_completion');
    }
}
```

### Assertions and scores

`AssertionInterface::evaluate(mixed $actual)` is the polymorphic seam, but each family (`StringAssertion`, `TrajectoryAssertion`, judges) enforces its concrete input and **throws `InvalidArgumentException`** on a mismatch: a wrong input type is a coding error in the evaluator, reported by the runner as a per-item *error*, never as a failed assertion about the agent.

Every `assert()` records a `Score` (`label`, `value`, `passed`). The label defaults to the assertion name and can be overridden (third argument) to aggregate several assertions under one metric. `EvaluationResults` is the single home for statistics: per-label groups and `{average, min, max, count}` are derived there and rendered by `ConsoleOutput` / `JsonOutput`. `EvaluationReport` wraps the whole run (UTC start/finish instants, one `EvaluatorReport` per discovered evaluator with its class, pre-execution error and results), so empty and errored evaluators stay visible.

## Multi-turn: you run a Conversation, you evaluate its Trajectory

`Trajectory` is a read-only **view over the original typed chat messages**, not a parallel data schema: `toolCalls()`, `lastToolCall()`, `finalAnswer()`, `usage()` and `toTranscript()` (the canonical rendering that judges and the simulator read) answer evaluation questions directly from framework types. `fromMessages()` / `fromChatHistory()` are public seams, so any hand-rolled loop can project its history and reuse the assertion layer; the `Conversation` runner is sugar over them. A call that never executed has no result: check `ToolCall::hasResult()` first. Accessors return the live call entries, which the approval flow annotates in place on resume, so read values rather than holding objects across a resume.

`Conversation` sends each scripted turn only after the previous one fully completed, suspend → decide → resume cycles included:

- `withApprovals(callable)` plays the human whenever the agent suspends, receiving the interrupt request and the trajectory so far. It is invoked for every suspension with the generic `InterruptRequest`, so type the callable against `ApprovalRequest` only when tool approval is the sole way the agent pauses. Fail-loud by design: a suspension with no policy throws `EvaluationException`, and an `ApprovalRequest` payload must cover every pending action id, otherwise the runner would re-suspend and loop.
- `withUser(UserSimulator, maxTurns)` replaces scripted turns with a goal-driven simulated user (mutually exclusive with `withTurns()`). `maxTurns` is required; hitting it ends the conversation *normally*, and whether that is a failure is the assertions' judgment.

`UserSimulator` is an `Agent` subclass with a persona and a goal that declares its own stop. Each step is stateless (persona + goal + transcript, own history flushed per call), and it never answers suspensions: the user and the approver are different humans, so approvals stay with the policy.

Trajectory assertions: `ToolWasCalled` (optional argument constraint: subset array or predicate), `ToolWasNotCalled`, `TrajectoryMatches` (names only, with `Mode::Strict` / `Unordered` / `Subset` / `Superset`), `ToolWasApproved`, `ToolWasRejected`. There are deliberately no final-answer assertions: the string assertions and judges apply to `$trajectory->finalAnswer()`, and every judge accepts `string|Trajectory` (`TaskCompletionJudge` is the conversation-level one: goal + full trajectory).

## Run output caching

**The cache stores the output of `run()`, never the verdict; `evaluate()` always executes fresh.** Skipping an unchanged run yields no new information about your change (it samples the same distribution), while re-evaluating stays free and lets you iterate on assertions, thresholds and judges against frozen trajectories.

The key (`Cache/CacheKey`) is a content fingerprint of what determines `run()`'s output: the evaluator class and the source of its `run()` method only, the declared `cacheDependencies()` (class-strings or file paths: your Agent class, prompt files), the dataset item content (content-addressed, so reordering invalidates nothing) and the framework version. Undeclared dependencies are invisible to invalidation; `--fresh` re-runs everything. Cached items are flagged per result and counted in the summary, so a skip is never confusable with a fresh run. Cache hits say nothing about provider drift: pair `--cache` in CI with a periodic `--fresh`. `EvaluationCacheInterface` is the storage seam and `FileEvaluationCache` the built-in driver (`.neuron/cache/evaluation/`, atomic writes so concurrent children cannot collide).

## Concurrency

`--concurrency=N` forks dataset items into N child processes (`ext-pcntl` + `spatie/fork`, sequential fallback). Each child gets its own evaluator copy, so per-item side effects are invisible across items, and `run()` outputs must be serializable to cross the boundary (non-serializable outputs become a placeholder and are not cached; `Trajectory` serializes through the chat-history format).

## Output drivers

`evaluation.php` in the project root lists `output` drivers: a class string for a zero-argument driver, or a constructed instance. Drivers with dependencies **must** be instances, which lets the host framework resolve them through its DI container; a custom driver implements `EvaluationOutputInterface::output(EvaluationReport $report)`.
