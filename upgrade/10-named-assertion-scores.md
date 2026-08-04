# Upgrade: Assertion scores are now labeled `Score` objects

## Summary

Evaluation assertion scores used to be collected as bare floats (`array<float>`), which
made it impossible to know which metric a score belonged to. Scores are now collected as
`NeuronAI\Evaluation\Score` value objects carrying a `label`, the float `value`, and the
`passed` flag. The label defaults to the assertion's `getName()` and can be overridden
per call:

```php
$this->assert(new TaskCompletionJudge($judge, goal: $goal), $trajectory, 'task_completion');
```

What changed:

1. **`BaseEvaluator::assert()` gained an optional third parameter** —
   `assert(AssertionInterface $rule, mixed $actual, ?string $label = null)`. Existing
   calls keep working unchanged.
2. **The float-reading APIs are unchanged** — `EvaluatorResult::getAssertionScores()`,
   `EvaluatorSummary::getAllAssertionScores()`, and all average/min/max methods keep
   their signatures and return the same values (now derived from the labeled records).
   Custom output drivers that only read these methods need no changes.
3. **New labeled-score APIs** — `EvaluatorResult::getScores(): array<Score>`,
   `EvaluatorSummary::getAllScores()`, `EvaluatorSummary::getScoresByLabel()`, and
   `EvaluatorSummary::getScoreStatisticsByLabel()` (per-metric average/min/max/count).
4. **Constructor argument types changed** — `AssertionOutcomes` (4th argument) and
   `EvaluatorResult` (9th argument) now take `array<Score>` instead of `array<float>`.
   This only affects code that constructs these objects directly (custom runners, direct
   `EvaluatorInterface` implementations, test fixtures).
5. **`RuleExecutor::getScores()` still returns `array<float>`** — the labeled records
   are available via the new `RuleExecutor::getScoreRecords()`.
6. **JSON output gained additive keys** — each result now includes a `scores` list of
   `{label, value, passed}` objects, and the summary includes a `metrics` object keyed
   by label with `{average, min, max, count}`. The existing `assertion_scores` and
   `score_statistics` keys are unchanged.

## Update your code

Only needed where result objects are constructed by hand:

```php
use NeuronAI\Evaluation\Score;

// Before
new EvaluatorResult(0, true, $input, $output, 0.1, 1, 0, [], [0.85]);

// After
new EvaluatorResult(0, true, $input, $output, 0.1, 1, 0, [], [
    new Score('task_completion', 0.85, true),
]);
```

To adopt named metrics in evaluators, pass a label where a shared metric name is more
useful than the assertion class name:

```php
// Before (label defaults to "TaskCompletionJudge")
$this->assert(new TaskCompletionJudge($this->judge, goal: $item['goal']), $trajectory);

// After
$this->assert(new TaskCompletionJudge($this->judge, goal: $item['goal']), $trajectory, 'task_completion');
```

## What to search for

```
grep -rn "new EvaluatorResult\|new AssertionOutcomes" --include="*.php" . | grep -v vendor/
```

Also review any code parsing the JSON output file if it validates the full document
shape (two new keys: `metrics` at the summary level, `scores` per result).
