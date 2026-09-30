# Upgrade: Evaluation assertion scores are labeled Score objects

## Summary

3.x collected evaluation assertion scores as bare floats. 4.x collects `NeuronAI\Evaluation\Score` objects with three public readonly properties: `label`, `value` and `passed`. The label is the assertion's `getName()`, which is the short class name (for example `CorrectnessJudge`) for every assertion Neuron ships. An evaluator can override it by passing a label as the new optional third argument: `$this->assert($rule, $actual, 'correctness')`. The result objects that carry the scores lost their per-object aggregates, and subclasses of the evaluator, assertion and runner base classes must match the new signatures.

Evaluators that extend `BaseEvaluator` and only call `$this->assert($rule, $actual)` need no change.

| 3.x | 4.x |
|-----|-----|
| `new EvaluatorResult($index, $passed, $input, $output, $executionTime, $assertionsPassed, $assertionsFailed, $assertionFailures, $assertionScores, $error)`, where the scores are `array<float>` | `new EvaluatorResult($evaluatorClass, $index, ...same arguments..., $error, $cachedRun = false)`, where the scores are `array<Score>` |
| `EvaluatorResult::getAverageAssertionScore()`, `getMinAssertionScore()`, `getMaxAssertionScore()` | Removed. `getAssertionScores()` is unchanged (`array<float>`). The new `getScoreRecords()` returns `array<Score>` |
| `new AssertionOutcomes($passedCount, $failedCount, $failures, $scores)`, where the scores are `array<float>`; `getTotalCount()`, `getAverageScore()`, `getMinScore()`, `getMaxScore()` | The scores are `array<Score>`. Only the readonly properties and `isPassed()` remain |
| `new RuleExecutor()`, `execute($rule, $actual)`, and the getters `getPassedCount()`, `getFailedCount()`, `getTotalCount()`, `getFailures()`, `getScores()`, `getAverageScore()`, `getMinScore()`, `getMaxScore()` | `new RuleExecutor(string $evaluatorClass)`, `execute($rule, $actual, int $line, ?string $label = null)`, and `snapshot(): AssertionOutcomes` in place of every getter. `reset()` is unchanged |
| `BaseEvaluator::assert(AssertionInterface $rule, mixed $actual): bool` | `assert(AssertionInterface $rule, mixed $actual, ?string $label = null): bool`. Calls stay as they are; overrides change |
| `EvaluatorInterface` declares `setUp()`, `getDataset()`, `run()` and `performEvaluation()` | It also declares `namespace(): ?string`. `BaseEvaluator` implements it and adds `cacheDependencies(): array` |
| `AssertionFailure::isAIJudgeFailure()`, `getAIJudgeScore()` | Removed |
| The ten built-in string assertions implement `public function evaluate(mixed $actual)` | They extend `StringAssertion`, whose `evaluate()` is `final`. Subclasses override `protected function evaluateString(string $actual)` |
| `EvaluatorRunner` has no constructor and a `runItem($evaluator, $index, $item)` hook. `EvaluatorResult::getError()` is the exception message | `__construct(?EvaluationCacheInterface $cache = null, bool $refresh = false, ?callable $beforeChild = null, ?callable $afterChild = null)` and `runItem($evaluator, $index, $item, bool $forked = false)`. `getError()` is `<ExceptionClass>: <message> (<file>:<line>)` |

**Stored data:** Neuron 3.x does not persist any of these objects, so there is nothing to migrate. If the application stored `serialize()`d `EvaluatorResult` or `AssertionOutcomes` objects itself, 4.x cannot use them: they have no evaluator class and their scores are floats, so reading them throws. Delete them and run the evaluators again.

Guide 54 migrates `EvaluatorSummary` (renamed), the return type of `EvaluatorRunner::run()`, output drivers and the JSON report. Leave them for that guide.

## What to Search For

Run from the application root:

```bash
grep -rnE '(new |extends )([A-Za-z0-9_\\]*\\)?(EvaluatorResult|AssertionOutcomes)([^A-Za-z0-9_]|$)|[Rr]uleExecutor' --include='*.php' --exclude-dir=vendor .
grep -rnE '(get(Average|Min|Max)AssertionScore|get(Average|Min|Max)Score|getTotalCount)\(' --include='*.php' --exclude-dir=vendor .
grep -rnE 'implements [^{]*EvaluatorInterface|performEvaluation\(|function (assert|namespace|cacheDependencies)\(' --include='*.php' --exclude-dir=vendor .
grep -rnE 'isAIJudgeFailure|getAIJudgeScore' --include='*.php' --exclude-dir=vendor .
grep -rnE 'extends ([A-Za-z0-9_\\]*\\)?(StringContains(All|Any)?|StringStartsWith|StringEndsWith|StringLengthBetween|StringDistance|StringSimilarity|MatchesRegex|IsValidJson)([^A-Za-z0-9_]|$)|function (validateThreshold|stringList|foldCase|renderTranscript|pcreError)\(' --include='*.php' --exclude-dir=vendor .
grep -rnE 'extends ([A-Za-z0-9_\\]*\\)?EvaluatorRunner([^A-Za-z0-9_]|$)|getError\(\)' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:
- **`new` / `extends` `EvaluatorResult` or `AssertionOutcomes`**: Case 1, including `parent::__construct(...)` calls in subclasses.
- **`RuleExecutor` / `ruleExecutor`**: Case 3. An import line only leads you to the code that uses the class.
- **The reader names**: Case 2, but only when the receiver is an `EvaluatorResult` (for example an element of `getResults()`) or an `AssertionOutcomes` (for example the return value of `performEvaluation()`). On a `RuleExecutor` they are Case 3. The summary that `EvaluatorRunner::run()` returns (`EvaluatorSummary` in 3.x) keeps `getTotalCount()`, `getAverageAssertionScore()`, `getMinAssertionScore()` and `getMaxAssertionScore()` unchanged. Leave those calls alone (guide 54 migrates the summary class).
- **`implements EvaluatorInterface`**: Case 3. **`->performEvaluation(`** calls: the `AssertionOutcomes` they return follows Case 2. **`function assert(`** in a `BaseEvaluator` subclass: Case 4. **`function namespace(` / `function cacheDependencies(`** in an evaluator: Case 5.
- **`isAIJudgeFailure` / `getAIJudgeScore`**: Case 6.
- **`extends` a built-in string assertion**: Case 7. **`function validateThreshold(`** and the other helper names, in a class that extends a Neuron assertion: Case 8.
- **`extends EvaluatorRunner`**: Case 9. **`getError()`** called on an `EvaluatorResult`: Case 10. Other `getError()` calls are unrelated.

Also follow imports with aliases (`use ... as ...`) and subclasses of the application classes you find.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: Result objects built by hand (test fixtures, custom runners)

Before (3.x):

```php
use NeuronAI\Evaluation\AssertionOutcomes;
use NeuronAI\Evaluation\Runner\EvaluatorResult;

$outcomes = new AssertionOutcomes(1, 0, [], [0.85]);

$result = new EvaluatorResult(0, true, ['question' => 'Capital of France?'], 'Paris', 0.1, 1, 0, [], [0.85]);

$failed = new EvaluatorResult(
    index: 1,
    passed: false,
    input: ['question' => 'Capital of Spain?'],
    output: null,
    executionTime: 0.2,
    assertionsPassed: 0,
    assertionsFailed: 0,
    error: 'Timeout',
);
```

After (4.x):

```php
use NeuronAI\Evaluation\AssertionOutcomes;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Score;

$outcomes = new AssertionOutcomes(1, 0, [], [new Score('CorrectnessJudge', 0.85, true)]);

$result = new EvaluatorResult(CapitalCityEvaluator::class, 0, true, ['question' => 'Capital of France?'], 'Paris', 0.1, 1, 0, [], [
    new Score('CorrectnessJudge', 0.85, true),
]);

$failed = new EvaluatorResult(
    evaluatorClass: CapitalCityEvaluator::class,
    index: 1,
    passed: false,
    input: ['question' => 'Capital of Spain?'],
    output: null,
    executionTime: 0.2,
    assertionsPassed: 0,
    assertionsFailed: 0,
    error: 'Timeout',
);
```

1. For `EvaluatorResult`, insert the evaluator's class name as the new first argument. Callers that use named arguments add `evaluatorClass:`. The other parameter names are unchanged. Leave out the new optional `cachedRun:`.
2. Replace each float score (the 4th argument of `AssertionOutcomes`, the 10th of `EvaluatorResult`, `assertionScores:` when named) with `new Score($label, $value, $passed)`:
   - `$label` is the `getName()` of the assertion that produced the score, which is its short class name for Neuron's assertions, or the label the evaluator passes to `assert()`. When a fixture does not say which assertion it stands for, use the short class name of the assertion the test is about. Only code that reads labels (`getScoreRecords()` and the per-label reports of guide 54) sees it.
   - `$passed` is whether that assertion passed.
3. When the code copies an existing result, for example in an `EvaluatorRunner::ensureSerializable()` override, pass `$result->getEvaluatorClass()` first and `$result->getScoreRecords()` as the scores. `getAssertionScores()` returns floats, which the constructor no longer accepts.

### Case 2: Removed score and count readers on EvaluatorResult and AssertionOutcomes

Before (3.x):

```php
$average = $result->getAverageAssertionScore();
$min = $result->getMinAssertionScore();
$max = $result->getMaxAssertionScore();

$total = $outcomes->getTotalCount();
$outcomesAverage = $outcomes->getAverageScore();
```

After (4.x):

```php
use NeuronAI\Evaluation\Score;

$scores = $result->getAssertionScores();
$average = $scores === [] ? 0.0 : array_sum($scores) / count($scores);
$min = $scores === [] ? 0.0 : min($scores);
$max = $scores === [] ? 0.0 : max($scores);

$total = $outcomes->passedCount + $outcomes->failedCount;
$outcomesScores = array_map(fn (Score $score): float => $score->value, $outcomes->scores);
$outcomesAverage = $outcomesScores === [] ? 0.0 : array_sum($outcomesScores) / count($outcomesScores);
```

1. On an `EvaluatorResult`, compute the average, minimum and maximum from `getAssertionScores()`, which still returns `array<float>`. The `=== [] ? 0.0` guard keeps the 3.x result for an item without scores.
2. On an `AssertionOutcomes`, `getTotalCount()` becomes `passedCount + failedCount`. `getAverageScore()`, `getMinScore()` and `getMaxScore()` use the same formulas over the float values of `$outcomes->scores`.
3. Any other code that reads `$outcomes->scores` as floats reads the mapped `array_map(fn (Score $score): float => $score->value, $outcomes->scores)` instead, because the property now holds `Score` objects.

### Case 3: Code that uses RuleExecutor directly, including evaluators that implement EvaluatorInterface

Before (3.x):

```php
use NeuronAI\Evaluation\AssertionOutcomes;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use NeuronAI\Evaluation\Dataset\JsonDataset;
use NeuronAI\Evaluation\RuleExecutor;

class SupportReplyEvaluator implements EvaluatorInterface
{
    protected RuleExecutor $executor;

    public function __construct()
    {
        $this->executor = new RuleExecutor();
    }

    public function setUp(): void
    {
    }

    public function getDataset(): DatasetInterface
    {
        return new JsonDataset(__DIR__ . '/datasets/support.json');
    }

    public function run(array $datasetItem): mixed
    {
        return (new SupportReplyGenerator())->reply($datasetItem['question']);
    }

    public function performEvaluation(mixed $output, array $datasetItem): AssertionOutcomes
    {
        $this->executor->reset();
        $this->executor->execute(new StringContains($datasetItem['keyword']), $output);

        return new AssertionOutcomes(
            $this->executor->getPassedCount(),
            $this->executor->getFailedCount(),
            $this->executor->getFailures(),
            $this->executor->getScores(),
        );
    }
}
```

After (4.x):

```php
use NeuronAI\Evaluation\AssertionOutcomes;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use NeuronAI\Evaluation\Dataset\JsonDataset;
use NeuronAI\Evaluation\RuleExecutor;

class SupportReplyEvaluator implements EvaluatorInterface
{
    protected RuleExecutor $executor;

    public function __construct()
    {
        $this->executor = new RuleExecutor(static::class);
    }

    public function namespace(): ?string
    {
        return null;
    }

    public function setUp(): void
    {
    }

    public function getDataset(): DatasetInterface
    {
        return new JsonDataset(__DIR__ . '/datasets/support.json');
    }

    public function run(array $datasetItem): mixed
    {
        return (new SupportReplyGenerator())->reply($datasetItem['question']);
    }

    public function performEvaluation(mixed $output, array $datasetItem): AssertionOutcomes
    {
        $this->executor->reset();
        $this->executor->execute(new StringContains($datasetItem['keyword']), $output, __LINE__);

        return $this->executor->snapshot();
    }
}
```

1. `new RuleExecutor()` becomes `new RuleExecutor(static::class)`: the evaluator that failures are attributed to.
2. `->execute($rule, $actual)` becomes `->execute($rule, $actual, __LINE__)`. The line is reported with a failure. A label can be passed as the 4th argument.
3. Replace the removed getters with `$outcomes = $executor->snapshot();`:
   - `getPassedCount()`, `getFailedCount()` and `getFailures()` become `$outcomes->passedCount`, `$outcomes->failedCount` and `$outcomes->failures`.
   - `getTotalCount()` becomes `$outcomes->passedCount + $outcomes->failedCount`.
   - `getScores()` becomes `array_map(fn (Score $score): float => $score->value, $outcomes->scores)` (import `NeuronAI\Evaluation\Score`).
   - `getAverageScore()`, `getMinScore()` and `getMaxScore()` use the Case 2 formulas over those floats.
   - A `performEvaluation()` that built `new AssertionOutcomes(...)` from the getters returns `$executor->snapshot()`. If it builds the outcomes some other way, apply Case 1.
4. A class that implements `EvaluatorInterface` without extending `BaseEvaluator` adds `public function namespace(): ?string { return null; }`. The evaluation CLI groups results under a non-null value, so return a string only if the developer asks for that grouping.
5. Inside a `BaseEvaluator` subclass, replace `$this->ruleExecutor->execute($rule, $actual)` with `$this->assert($rule, $actual)`, which passes the line itself. A constructor that re-creates the executor writes `$this->ruleExecutor = new RuleExecutor(static::class);`.
6. In a `RuleExecutor` subclass:
   - An `execute()` override takes `execute(AssertionInterface $rule, mixed $actual, int $line, ?string $label = null): bool` and forwards `$line` and `$label` to `parent::execute()`.
   - A `recordFailure()` override takes `recordFailure(AssertionInterface $rule, AssertionResult $result, int $line): void`. Use `$line` and `$this->evaluatorClass` instead of reading them from `debug_backtrace()`.
   - Overrides of the removed getters are no longer called. Move their logic into a `snapshot(): AssertionOutcomes` override.

### Case 4: BaseEvaluator subclasses that override assert()

Before (3.x):

```php
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\AssertionInterface;

abstract class LoggingEvaluator extends BaseEvaluator
{
    /** @var array<string> */
    protected array $log = [];

    protected function assert(AssertionInterface $rule, mixed $actual): bool
    {
        $passed = parent::assert($rule, $actual);
        $this->log[] = $rule->getName() . ($passed ? ' passed' : ' failed');

        return $passed;
    }
}
```

After (4.x):

```php
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\AssertionInterface;

abstract class LoggingEvaluator extends BaseEvaluator
{
    /** @var array<string> */
    protected array $log = [];

    protected function assert(AssertionInterface $rule, mixed $actual, ?string $label = null): bool
    {
        $passed = parent::assert($rule, $actual, $label);
        $this->log[] = $rule->getName() . ($passed ? ' passed' : ' failed');

        return $passed;
    }
}
```

Add `?string $label = null` to the signature and pass `$label` on to `parent::assert()`. The 3.x two-parameter override is a fatal incompatible declaration in 4.x.

### Case 5: BaseEvaluator subclasses that declare namespace() or cacheDependencies()

`BaseEvaluator` now declares `public function namespace(): ?string` (the evaluation CLI groups results under its value) and `public function cacheDependencies(): array` (class-strings or file paths that invalidate cached `run()` outputs). A 3.x evaluator method with either name was the application's own and meant something else. With an incompatible signature (no return type, non-public, required parameters) the class no longer loads. With a compatible one, Neuron calls it with the 4.x meaning.

Rename the application method and every call to it, for example `namespace()` to `agentNamespace()`:

Before (3.x):

```php
protected function namespace(): string
{
    return 'App\\Agents';
}
```

After (4.x):

```php
protected function agentNamespace(): string
{
    return 'App\\Agents';
}
```

### Case 6: isAIJudgeFailure() and getAIJudgeScore()

Both methods are removed. In 3.x they matched only an assertion named `assertWithAIJudge`, which no Neuron assertion is, so `isAIJudgeFailure()` was always `false` and `getAIJudgeScore()` was always `null`.

Before (3.x):

```php
foreach ($result->getAssertionFailures() as $failure) {
    if ($failure->isAIJudgeFailure()) {
        $lines[] = 'Judge score ' . $failure->getAIJudgeScore()?->score . ': ' . $failure->getMessage();
    } else {
        $lines[] = $failure->getFullDescription();
    }
}
```

After (4.x), with the same behaviour as 3.x:

```php
foreach ($result->getAssertionFailures() as $failure) {
    $lines[] = $failure->getFullDescription();
}
```

1. Delete each `isAIJudgeFailure()` branch and keep the code of the `else` path, because the branch never ran in 3.x. Replace a standalone `getAIJudgeScore()` read with `null`, or remove the code that depends on it.
2. Ask the developer whether they want the judge details the dead branch was written for. If they do:
   - `$failure->getAssertionMethod()` is the assertion's short class name, for example `'CorrectnessJudge'`, which identifies a judge failure.
   - A failed judge's message is `Score <score> below threshold <threshold>. Reasoning: <reasoning>`, read with `$failure->getMessage()`.
   - The judge's score is the `value` of the `Score` in `$result->getScoreRecords()` whose `label` is the judge's short class name, or the label the evaluator passed to `assert()`.

### Case 7: Subclasses of the built-in string assertions

This applies to classes that extend `StringContains`, `StringContainsAll`, `StringContainsAny`, `StringStartsWith`, `StringEndsWith`, `StringLengthBetween`, `StringDistance`, `StringSimilarity`, `MatchesRegex` or `IsValidJson`. Their `evaluate()` is now `final`, so an override is fatal.

Before (3.x):

```php
use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Assertions\StringContains;

class ContainsCaseSensitive extends StringContains
{
    public function evaluate(mixed $actual): AssertionResult
    {
        if (!is_string($actual)) {
            return AssertionResult::fail(0.0, 'Expected actual value to be a string');
        }

        if (str_contains($actual, $this->keyword)) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(0.0, "Expected '{$actual}' to contain '{$this->keyword}' (case-sensitive)");
    }
}
```

After (4.x):

```php
use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Assertions\StringContains;

class ContainsCaseSensitive extends StringContains
{
    protected function evaluateString(string $actual): AssertionResult
    {
        if (str_contains($actual, $this->keyword)) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(0.0, "Expected '{$actual}' to contain '{$this->keyword}' (case-sensitive)");
    }
}
```

1. Replace `public function evaluate(mixed $actual): AssertionResult` with `protected function evaluateString(string $actual): AssertionResult`.
2. Remove the override's own `is_string()` check. `evaluateString()` only ever receives a string.
3. Replace `parent::evaluate($actual)` with `parent::evaluateString($actual)`.
4. If the override converted a non-string value before matching (for example a message object to its text), that value no longer reaches `evaluateString()`. Move the conversion into the evaluator, so that it passes a string to `assert()`.

### Case 8: Assertion subclasses with methods named like the new helpers

Neuron's assertion base classes declare new protected helpers:

| Declared by (and inherited by its subclasses) | Method |
|-----|-----|
| `AbstractAssertion` (every Neuron assertion) | `validateThreshold(float $threshold): void`, `stringList(array $values, string $role): array` |
| `StringAssertion` (the ten string assertions of Case 7) | `foldCase(string $text): string` |
| `AgentJudge` (and `CorrectnessJudge`, `FaithfulnessJudge`, `HelpfulnessJudge`, `RelevanceJudge`) | `renderTranscript(Trajectory $trajectory): string` |
| `MatchesRegex` | `pcreError(): string` |

An application subclass that declares a method with one of these names fails to load when the signature or visibility differs, and otherwise replaces the helper that Neuron's own code calls, for example `AgentJudge::__construct()` calling `validateThreshold()`. Rename the application method and every call to it.

### Case 9: EvaluatorRunner subclasses

Before (3.x):

```php
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;

class LoggingEvaluatorRunner extends EvaluatorRunner
{
    public function __construct(protected string $logFile)
    {
    }

    protected function runItem(EvaluatorInterface $evaluator, int $index, array $item): EvaluatorResult
    {
        $result = parent::runItem($evaluator, $index, $item);
        file_put_contents($this->logFile, "Item {$index}: {$result->getExecutionTime()}s\n", FILE_APPEND);

        return $result;
    }
}
```

After (4.x):

```php
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;

class LoggingEvaluatorRunner extends EvaluatorRunner
{
    public function __construct(protected string $logFile)
    {
        parent::__construct();
    }

    protected function runItem(EvaluatorInterface $evaluator, int $index, array $item, bool $forked = false): EvaluatorResult
    {
        $result = parent::runItem($evaluator, $index, $item, $forked);
        file_put_contents($this->logFile, "Item {$index}: {$result->getExecutionTime()}s\n", FILE_APPEND);

        return $result;
    }
}
```

1. A `runItem()` override adds `bool $forked = false` as the 4th parameter and passes `$forked` on to `parent::runItem()`.
2. A subclass that declares its own `__construct()` calls `parent::__construct()`. Without that call, every dataset item fails with `Typed property NeuronAI\Evaluation\Runner\EvaluatorRunner::$cache must not be accessed before initialization`.
3. `EvaluatorRunner` now declares the properties `$cache`, `$refresh`, `$beforeChild` and `$afterChild`, and the methods `withCache()`, `validateDataset()` and `describeError()`. Rename subclass members with those names, and their uses.
4. Any `EvaluatorResult` the subclass builds follows Case 1, including in an `ensureSerializable()` override.
5. A `run()` override's return type is migrated by guide 54.

### Case 10: Exact comparisons against EvaluatorResult::getError()

The error of a failed item was the exception message. It is now `<ExceptionClass>: <message> (<file>:<line>)`, so exact comparisons no longer match.

Before (3.x):

```php
$this->assertSame('Dataset item has no question', $result->getError());

if ($result->getError() === 'Request timed out') {
    $timeouts++;
}
```

After (4.x):

```php
$this->assertStringContainsString('Dataset item has no question', $result->getError() ?? '');

if (str_contains($result->getError() ?? '', 'Request timed out')) {
    $timeouts++;
}
```

Replace every exact comparison (`===`, `==`, `assertSame()`, `assertEquals()`, `match`) on an `EvaluatorResult::getError()` value with a substring check. `hasError()` is unchanged.

## Checklist

- Every `new EvaluatorResult(` (and `parent::__construct(` of a subclass) starts with an evaluator class-string and passes `Score` objects as the scores.
- Every `new AssertionOutcomes(` passes `Score` objects as its 4th argument.
- No calls remain to `getAverageAssertionScore()`, `getMinAssertionScore()` or `getMaxAssertionScore()` on an `EvaluatorResult`, or to `getTotalCount()`, `getAverageScore()`, `getMinScore()` or `getMaxScore()` on an `AssertionOutcomes`.
- Every `new RuleExecutor(` passes the evaluator class, every `execute(` passes a line, and no `RuleExecutor` getter other than `snapshot()` is called.
- Classes that implement `EvaluatorInterface` directly declare `public function namespace(): ?string`.
- `assert()` overrides accept `?string $label = null` and forward it.
- No evaluator declares its own `namespace()` or `cacheDependencies()`, and no assertion subclass declares `validateThreshold()`, `stringList()`, `foldCase()`, `renderTranscript()` or `pcreError()`, unless it is meant as the 4.x method.
- No `isAIJudgeFailure()` or `getAIJudgeScore()` call remains.
- No subclass of a built-in string assertion overrides `evaluate()`. It overrides `evaluateString(string $actual)` instead.
- `EvaluatorRunner` subclasses forward `$forked` from `runItem()` and call `parent::__construct()` from their own constructor.
- No exact comparison against `EvaluatorResult::getError()` remains.
- Re-run the searches, then run the application's tests and static analysis. If the application has evaluators, run `vendor/bin/neuron evaluation <path>`.
