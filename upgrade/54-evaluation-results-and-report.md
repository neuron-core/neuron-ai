# Upgrade: EvaluatorSummary becomes EvaluationResults; output drivers receive an EvaluationReport

## Summary

- `NeuronAI\Evaluation\Runner\EvaluatorSummary` is renamed `NeuronAI\Evaluation\Runner\EvaluationResults`.
  `EvaluatorRunner::run()` returns it. Its constructor takes only the results. Three methods are removed:
  `getTotalExecutionTime()`, `getAssertionFailuresByClass()` and `getAssertionFailuresByLocation()`. Every other method
  keeps its name, signature and meaning.
- Output drivers now receive a `NeuronAI\Evaluation\Runner\EvaluationReport`, which describes the whole run: one
  `EvaluatorReport` per evaluator, plus start and finish times. `$report->getResults()` returns the merged
  `EvaluationResults`.
- The `ConsoleOutput` hooks `printSummary()`, `printFailures()` and `printAssertionFailureSummary()` take new
  arguments.
- The JsonOutput document replaces `total_execution_time` with `duration` and adds keys. The console text changes, and
  the command's own `Error:` lines go to STDERR.

| 3.x | 4.x |
|---|---|
| `EvaluatorSummary` | `EvaluationResults` |
| `new EvaluatorSummary($results, $totalTime)` | `new EvaluationResults($results)` |
| `EvaluatorRunner::run(...): EvaluatorSummary` | `EvaluatorRunner::run(...): EvaluationResults` |
| `$summary->getTotalExecutionTime()` | `$report->getDuration()` in a driver; time the `run()` call yourself elsewhere |
| `getAssertionFailuresByLocation()`, `getAssertionFailuresByClass()` | group `getAllAssertionFailures()` yourself (Case 1) |
| `EvaluationOutputInterface::output(EvaluatorSummary $summary): void` | `output(EvaluationReport $report): void` |
| `ConsoleOutput::printSummary(EvaluatorSummary $summary): void` | `printSummary(EvaluationReport $suite): void` |
| `ConsoleOutput::printFailures(EvaluatorSummary $summary): void` | `printFailures(EvaluationResults $results, array $evaluatorLabels): void` |
| `ConsoleOutput::printAssertionFailureSummary(EvaluatorSummary $summary): void` | `printAssertionFailureSummary(EvaluationResults $results, array $evaluatorLabels): void` |
| JSON key `total_execution_time` | JSON key `duration` |

`EvaluationReport` has `getResults(): EvaluationResults`, `getEvaluatorReports(): array<EvaluatorReport>`,
`getStartedAt()` and `getFinishedAt()` (`DateTimeImmutable`), `getDuration(): float` (seconds) and
`hasFailures(): bool`. `hasFailures()` is also true when an evaluator failed to build or run. `EvaluatorReport` has
`getEvaluatorClass()`, `getShortEvaluatorClass()`, `getResults()`, `getStartedAt()`, `getFinishedAt()`,
`getDuration()`, `getNamespace()`, `getError()`, `hasError()` and `hasFailures()`.

Stored data: Neuron never reads back a JSON report or anything an output driver stored. JSON files written by 3.x keep
their 3.x shape, so code that reads old and new files together must accept both (Case 5). Tables written by the
application's own drivers need no migration, because the driver still decides what it stores.

Guide 53 has already migrated `EvaluatorResult` construction, the score readers and comparisons on
`EvaluatorResult::getError()`. Guide 55 migrates how `EvaluationCommand` is constructed and subclassed.

## What to Search For

Run from the application root:

```bash
# 1. The renamed class and the removed methods
grep -rnE 'EvaluatorSummary|getTotalExecutionTime\(|getAssertionFailuresBy(Class|Location)\(' --include='*.php' --exclude-dir=vendor .

# 2. Output drivers, subclasses of the built-in drivers, and places that build them
grep -rnE 'EvaluationOutputInterface|(extends|new) (ConsoleOutput|JsonOutput|OutputPipeline)|function (printSummary|printFailures|printAssertionFailureSummary)\(' --include='*.php' --exclude-dir=vendor .

# 3. Methods that clash with the new protected methods of ConsoleOutput and JsonOutput
grep -rnE 'function (printEvaluatorErrors|printEvaluatorBreakdown|printTotals|getEvaluatorLabels|escapeControlCharacters|evaluationReportToArray|evaluatorReportToArray|resultsToArray)\(' --include='*.php' --exclude-dir=vendor .

# 4. Code and config that read the JsonOutput document (any language)
grep -rnE 'total_execution_time|average_execution_time|score_statistics|assertion_scores|has_failures' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .

# 5. Scripts, CI jobs and tests that run the evaluation command and read its output
grep -rnE 'neuron evaluation|EvaluationCommand|NeuronCli' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .
```

How to follow the hits:

- Search 1: a hit inside an `output()` method or a `ConsoleOutput` hook belongs to Case 2 or Case 3. A
  `new EvaluatorSummary(...)` whose result goes to `output()` or `printSummary()` is Case 4. Everything else is
  Case 1.
- Search 2: keep only classes that import `NeuronAI\Evaluation\...`. Symfony also has a `ConsoleOutput` class, and its
  hits are unrelated. A class implementing `EvaluationOutputInterface`, or overriding `output()` of `ConsoleOutput`,
  `JsonOutput` or `OutputPipeline`, is Case 2. An override of a `ConsoleOutput` hook is Case 3. Then grep for each
  application driver's class name and follow its `->output(` and `->printSummary(` calls (Case 4). Grep its subclasses
  too.
- Search 3: keep only hits inside subclasses of Neuron's `ConsoleOutput` or `JsonOutput` (Case 3, step 4).
- Search 4: keep only hits that read the document JsonOutput writes: a file, or STDOUT when it has no path. Find the
  file name in the `new JsonOutput('<path>')` entries of `evaluation.php`, grep for it too, and handle every reader
  (Case 5).
- Search 5: ignore hits inside installed agent skills (`*/skills/neuron-*`). Guide 55 handles `new EvaluationCommand(`
  arguments. Here, check only whether the hit parses the command's text output (Case 6).

If searches 1 to 5 find nothing, this guide does not apply.

## How to Refactor

### Case 1: Programmatic runs and other `EvaluatorSummary` references

1. Replace `use NeuronAI\Evaluation\Runner\EvaluatorSummary;` with
   `use NeuronAI\Evaluation\Runner\EvaluationResults;`. Rename every other reference too: parameter, return and
   property types (including an `EvaluatorRunner` subclass's `run()` return type), docblocks, `instanceof`, `extends`
   and `::class`. Variable names can stay.
2. `new EvaluatorSummary($results, $totalTime)` becomes `new EvaluationResults($results)`. Drop the second argument in
   `parent::__construct()` calls too.
3. `getTotalExecutionTime()` on a value returned by `EvaluatorRunner::run()`: time the call yourself.
4. `getAssertionFailuresByLocation()` becomes the loop below. `getAssertionFailuresByClass()` is the same loop keyed by
   `$failure->getEvaluatorClass()`.

Before (3.x):

```php
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;

class EvaluationReporter
{
    public function report(EvaluatorSummary $summary): void
    {
        echo "Success rate: {$summary->getSuccessRate()}\n";
    }
}

$runner = new EvaluatorRunner();
$summary = $runner->run(new SentimentEvaluator());

echo "Passed: {$summary->getPassedCount()} in {$summary->getTotalExecutionTime()}s\n";

foreach ($summary->getAssertionFailuresByLocation() as $location => $failures) {
    echo "{$location}: " . count($failures) . " failure(s)\n";
}

$failuresByClass = $summary->getAssertionFailuresByClass();
```

After (4.x):

```php
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;

class EvaluationReporter
{
    public function report(EvaluationResults $summary): void
    {
        echo "Success rate: {$summary->getSuccessRate()}\n";
    }
}

$runner = new EvaluatorRunner();
$startedAt = microtime(true);
$summary = $runner->run(new SentimentEvaluator());
$totalTime = microtime(true) - $startedAt;

echo "Passed: {$summary->getPassedCount()} in {$totalTime}s\n";

$failuresByLocation = [];
foreach ($summary->getAllAssertionFailures() as $failure) {
    $failuresByLocation[$failure->getShortEvaluatorClass() . ':' . $failure->getLineNumber()][] = $failure;
}

foreach ($failuresByLocation as $location => $failures) {
    echo "{$location}: " . count($failures) . " failure(s)\n";
}

$failuresByClass = [];
foreach ($summary->getAllAssertionFailures() as $failure) {
    $failuresByClass[$failure->getEvaluatorClass()][] = $failure;
}
```

### Case 2: Custom output drivers

This case covers classes that implement `EvaluationOutputInterface`, and subclasses of `ConsoleOutput`, `JsonOutput` or
`OutputPipeline` that override `output()`.

1. Change the signature to `public function output(EvaluationReport $report): void`. Import
   `NeuronAI\Evaluation\Runner\EvaluationReport` and remove the `EvaluatorSummary` import.
2. Make `$summary = $report->getResults();` the first statement, so the rest of the body keeps working unchanged. In an
   override, pass `$report` to `parent::output()`.
3. Replace `$summary->getTotalExecutionTime()` with `$report->getDuration()`. It is the wall-clock time of the whole run,
   including evaluator setup and dataset loading, so values are slightly higher than in 3.x.
4. Replace the removed grouping methods with the Case 1 loops.
5. `$summary->hasFailures()` keeps its 3.x meaning: some dataset item failed. An evaluator that failed to build or run
   never reached a 3.x driver. If the driver reports an overall outcome (a status check, a notification, a gate), ask
   the developer: "Should `<Driver>` also report a failure when an evaluator cannot be built or run?" If yes, use
   `$report->hasFailures()`, which matches the command's exit code.

Registration in `evaluation.php` does not change here.

Before (3.x):

```php
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;

class DatabaseOutput implements EvaluationOutputInterface
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $table = 'evaluations'
    ) {}

    public function output(EvaluatorSummary $summary): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
            (passed, failed, success_rate, total_time, created_at)
            VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $summary->getPassedCount(),
            $summary->getFailedCount(),
            $summary->getSuccessRate(),
            $summary->getTotalExecutionTime(),
        ]);
    }
}
```

After (4.x):

```php
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Runner\EvaluationReport;

class DatabaseOutput implements EvaluationOutputInterface
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $table = 'evaluations'
    ) {}

    public function output(EvaluationReport $report): void
    {
        $summary = $report->getResults();

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
            (passed, failed, success_rate, total_time, created_at)
            VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $summary->getPassedCount(),
            $summary->getFailedCount(),
            $summary->getSuccessRate(),
            $report->getDuration(),
        ]);
    }
}
```

### Case 3: `ConsoleOutput` and `JsonOutput` subclasses that override hooks

1. `public function printSummary(EvaluatorSummary $summary)` becomes
   `public function printSummary(EvaluationReport $suite)`. Inside, read statistics from `$suite->getResults()` and the
   time from `$suite->getDuration()`.
2. `printFailures()` and `printAssertionFailureSummary()` become
   `protected function ...(EvaluationResults $results, array $evaluatorLabels): void`. `$evaluatorLabels` maps each
   evaluator class name to the label the console shows: the short class name, or the full name when two evaluators
   share a short name. Forward both arguments in `parent::` calls. Code that calls these hooks itself passes
   `$suite->getResults(), $this->getEvaluatorLabels($suite)`.
3. Replace `getAssertionFailuresByLocation()` with the Case 1 loop. Inside the hooks, label each location with
   `$evaluatorLabels[$failure->getEvaluatorClass()]`.
4. `ConsoleOutput` now has the protected methods `printEvaluatorErrors()`, `printEvaluatorBreakdown()`, `printTotals()`,
   `getEvaluatorLabels()` and `escapeControlCharacters()`. `JsonOutput` now has `evaluationReportToArray()`,
   `evaluatorReportToArray()` and `resultsToArray()`. Rename any subclass method with one of these names, together with
   its calls. Otherwise it is a fatal incompatible declaration, or it silently replaces the parent's method.

Before (3.x):

```php
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;

class TeamConsoleOutput extends ConsoleOutput
{
    public function printSummary(EvaluatorSummary $summary): void
    {
        parent::printSummary($summary);
        $this->printTotals($summary);
    }

    protected function printFailures(EvaluatorSummary $summary): void
    {
        echo "--- failed items ---\n";
        parent::printFailures($summary);
    }

    protected function printAssertionFailureSummary(EvaluatorSummary $summary): void
    {
        foreach ($summary->getAssertionFailuresByLocation() as $location => $failures) {
            echo "{$location}: " . count($failures) . " failure(s)\n";
        }
    }

    protected function printTotals(EvaluatorSummary $summary): void
    {
        echo sprintf("Wall time: %.2fs\n", $summary->getTotalExecutionTime());
    }
}
```

After (4.x):

```php
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluationResults;

class TeamConsoleOutput extends ConsoleOutput
{
    public function printSummary(EvaluationReport $suite): void
    {
        parent::printSummary($suite);
        $this->printWallTime($suite);
    }

    /** @param array<string, string> $evaluatorLabels */
    protected function printFailures(EvaluationResults $results, array $evaluatorLabels): void
    {
        echo "--- failed items ---\n";
        parent::printFailures($results, $evaluatorLabels);
    }

    /** @param array<string, string> $evaluatorLabels */
    protected function printAssertionFailureSummary(EvaluationResults $results, array $evaluatorLabels): void
    {
        $failuresByLocation = [];
        foreach ($results->getAllAssertionFailures() as $failure) {
            $failuresByLocation[$evaluatorLabels[$failure->getEvaluatorClass()] . ':' . $failure->getLineNumber()][] = $failure;
        }

        foreach ($failuresByLocation as $location => $failures) {
            echo "{$location}: " . count($failures) . " failure(s)\n";
        }
    }

    protected function printWallTime(EvaluationReport $suite): void
    {
        echo sprintf("Wall time: %.2fs\n", $suite->getDuration());
    }
}
```

### Case 4: Code that builds a summary and passes it to a driver

This covers the application's own runner loops or commands, and tests that call `->output()` or `->printSummary()`.
Build an `EvaluationReport` from one `EvaluatorReport` per evaluator:
`new EvaluatorReport(string $evaluatorClass, EvaluationResults $results, DateTimeImmutable $startedAt, DateTimeImmutable $finishedAt)`
and `new EvaluationReport(array $evaluatorReports, DateTimeImmutable $startedAt, DateTimeImmutable $finishedAt)`.
The evaluator class must be the one the results carry (`EvaluatorResult::getEvaluatorClass()`), because `ConsoleOutput`
looks up each result's label by it. Results from several evaluators need one `EvaluatorReport` each.

Before (3.x):

```php
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Evaluation\Output\OutputPipeline;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;

$runner = new EvaluatorRunner();
$results = [];
$totalTime = 0.0;

foreach ([SentimentEvaluator::class, SummarizationEvaluator::class] as $evaluatorClass) {
    $summary = $runner->run(new $evaluatorClass());
    $results = array_merge($results, $summary->getResults());
    $totalTime += $summary->getTotalExecutionTime();
}

(new OutputPipeline([new ConsoleOutput(), new JsonOutput('evaluation-results.json')]))
    ->output(new EvaluatorSummary($results, $totalTime));
```

After (4.x):

```php
use DateTimeImmutable;
use DateTimeZone;
use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Evaluation\Output\OutputPipeline;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluatorReport;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;

$runner = new EvaluatorRunner();
$utc = new DateTimeZone('UTC');
$startedAt = new DateTimeImmutable('now', $utc);
$evaluatorReports = [];

foreach ([SentimentEvaluator::class, SummarizationEvaluator::class] as $evaluatorClass) {
    $evaluatorStartedAt = new DateTimeImmutable('now', $utc);
    $summary = $runner->run(new $evaluatorClass());
    $evaluatorReports[] = new EvaluatorReport($evaluatorClass, $summary, $evaluatorStartedAt, new DateTimeImmutable('now', $utc));
}

(new OutputPipeline([new ConsoleOutput(), new JsonOutput('evaluation-results.json')]))
    ->output(new EvaluationReport($evaluatorReports, $startedAt, new DateTimeImmutable('now', $utc)));
```

In tests, fixed timestamps reproduce a known duration.

Before (3.x):

```php
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Evaluation\Runner\EvaluatorSummary;

public function test_writes_the_json_report(): void
{
    $path = sys_get_temp_dir() . '/evaluation.json';

    (new JsonOutput($path))->output(new EvaluatorSummary($this->results(), 1.5));

    $data = json_decode((string) file_get_contents($path), true);
    $this->assertSame(1.5, $data['total_execution_time']);
}
```

After (4.x):

```php
use DateTimeImmutable;
use DateTimeZone;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Evaluation\Runner\EvaluationReport;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorReport;

public function test_writes_the_json_report(): void
{
    $path = sys_get_temp_dir() . '/evaluation.json';
    $startedAt = new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC'));
    $finishedAt = new DateTimeImmutable('2026-01-01 10:00:01.5', new DateTimeZone('UTC'));
    $evaluatorReport = new EvaluatorReport(SentimentEvaluator::class, new EvaluationResults($this->results()), $startedAt, $finishedAt);

    (new JsonOutput($path))->output(new EvaluationReport([$evaluatorReport], $startedAt, $finishedAt));

    $data = json_decode((string) file_get_contents($path), true);
    $this->assertSame(1.5, $data['duration']);
}
```

### Case 5: Readers of the JsonOutput document

1. Replace reads of the top-level `total_execution_time` with `duration`, for example `jq '.duration'` or
   `$data['duration']`. Where 3.x reports are read too (archives, trend dashboards), accept both:
   `$data['duration'] ?? $data['total_execution_time']`.
2. Strict schema validators must allow the new keys:
   - top level: `started_at` and `finished_at` (ISO-8601 with microseconds and UTC offset), `duration`, `cached_runs`,
     `metrics` (per-label `{average, min, max, count}`, or `null` when there are no scores) and `evaluators`;
   - each `evaluators[]` entry: `evaluator_class`, `namespace`, `started_at`, `finished_at`, `duration`, the same
     statistics as the top level without `results`, `error` and `has_failures`;
   - each `results[]` entry: `evaluator_class`, `cached_run` and `scores` (a list of `{label, value, passed}`).
3. `results[].error` is now `<ExceptionClass>: <message> (<file>:<line>)`. Change exact comparisons to substring
   matches on the message.
4. The top-level `has_failures` is now also true when an evaluator failed to build or run (see `evaluators[].error`),
   which matches the command's exit code. For each gate that reads it, ask the developer whether it should keep the
   3.x meaning (failed items only). If yes, read `failed > 0` instead.

All other keys keep their names and meaning: `total`, `passed`, `failed`, `success_rate`, `average_execution_time`,
`total_assertions`, `assertions_passed`, `assertions_failed`, `assertion_success_rate`, `score_statistics`, and on each
result `index`, `passed`, `input`, `output`, `execution_time`, `assertions_passed`, `assertions_failed` and
`assertion_scores`.

### Case 6: Scripts and tests that read the console output of `neuron evaluation`

The exit code is unchanged: 1 when any item fails or an evaluator errors. Prefer it, or JsonOutput, over parsing text.
Where text is parsed:

1. The command's own `Error: ...` lines now go to STDERR: invalid arguments, no evaluators found,
   `Failed to run <class>: ...` and uncaught errors. Capture them with `2>&1`, for example
   `vendor/bin/neuron evaluation evals 2>&1 | tee report.txt`. The indented `   Error: ...` lines inside the failure
   list stay on STDOUT.
2. `Time: X seconds, Average: Y seconds per test` becomes three lines: `Started: <ISO-8601>`, `Finished: <ISO-8601>`
   and `Duration: X seconds, Average: Y seconds per test`.
3. Failure headers `N) Test #i` become `N) <EvaluatorShortName> #i`, or the full class name when two evaluators share a
   short name.
4. Error text is `<ExceptionClass>: <message> (<file>:<line>)`.
5. New blocks can appear: `There were N evaluator error(s):`, per-label lines under `Score Stats:`, and `By evaluator:`
   when more than one evaluator ran.

A PHP test that captures the command's output with `ob_start()` no longer sees `Error:` lines. Give the existing
`EvaluationCommand` or `NeuronCli` instance a stream with `setErrorStream()` and read it:

Before (3.x):

```php
ob_start();
$exitCode = $command->run(['evaluation', 'tests/Evaluators/Empty']);
$output = ob_get_clean();

$this->assertStringContainsString('Error: No evaluator classes found', $output);
```

After (4.x):

```php
$errors = fopen('php://memory', 'w+');
$command->setErrorStream($errors);

ob_start();
$exitCode = $command->run(['evaluation', 'tests/Evaluators/Empty']);
ob_end_clean();
rewind($errors);

$this->assertStringContainsString('Error: No evaluator classes found', stream_get_contents($errors));
```

## Checklist

- Search 1 finds nothing.
- Every application class that implements `EvaluationOutputInterface`, or overrides `output()` of `ConsoleOutput`,
  `JsonOutput` or `OutputPipeline`, declares `output(EvaluationReport $report): void`.
- `ConsoleOutput` overrides use the 4.x signatures, and their `parent::` calls forward both arguments.
- No subclass of `ConsoleOutput` or `JsonOutput` declares a method named in Case 3, step 4.
- Every `->output()` and `->printSummary()` call on a Neuron driver passes an `EvaluationReport`. Its
  `EvaluatorReport` class names match the evaluator classes of the results.
- Every developer question from Cases 2 and 5 is answered, and the answer is applied.
- No reader of the JSON document uses `total_execution_time` alone, and strict schemas allow the new keys.
- Scripts that need the command's error lines capture STDERR, and no parser relies on `Time:` or `Test #`.
- Static analysis reports no error about `EvaluatorSummary`, `EvaluationOutputInterface`, `EvaluationReport`,
  `EvaluationResults`, `ConsoleOutput`, `JsonOutput` or `OutputPipeline`. Errors about `EvaluationCommand`
  construction are guide 55's.
- Evaluations can call paid AI providers. Ask the developer before running `vendor/bin/neuron evaluation <path>` once to
  check the configured drivers.
