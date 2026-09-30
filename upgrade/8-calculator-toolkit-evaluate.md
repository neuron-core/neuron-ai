# Upgrade: Calculator toolkit built around evaluate

## Summary

The tools in `NeuronAI\Tools\Toolkits\Calculator` changed:

- `SumTool`, `SubtractTool`, `MultiplyTool`, `DivideTool`, `ExponentialTool`, `SquareRootTool` and `NthRootTool` are removed. `EvaluateTool` (tool name `evaluate`) replaces all seven. It takes one string input, `expression`, for example `(3 + 4) * sqrt(2)`.
- `MeanTool`, `MedianTool`, `VarianceTool` and `StandardDeviationTool` have no constructor, so the `precision` and `sample` arguments are gone. PHP silently ignores positional arguments: `new VarianceTool(2, false)` runs and computes a sample variance. Named arguments throw `Error: Unknown named parameter`. `VarianceTool` and `StandardDeviationTool` take a `population` input that the model sets on each call; the default is a sample. Results are no longer rounded.
- `FactorialTool` and seven new integer tools (`CombinationsTool`, `PermutationsTool`, `GcdTool`, `LcmTool`, `ModPowTool`, `IsPrimeTool`, `PrimeFactorsTool`) need ext-bcmath. Constructing any of them without it throws `NeuronAI\Exceptions\ToolException`. `CalculatorToolkit::make()` itself does not throw. An agent that registers `CalculatorToolkit` throws when a run starts, before the first provider request, even with `only()`/`exclude()` filters, because the toolkit builds every tool before it filters them.
- Every tool name changed. `FactorialTool`'s input `number` is now `n`.
- Every result is a string. Failures are `NeuronAI\Tools\ToolOutput` objects with `isError() === true`, not JSON payloads. Read them as guide 4 describes.

| 3.x class (tool name) | 4.x class (tool name) |
|---|---|
| `SumTool` (`sum`), `SubtractTool` (`substract`), `MultiplyTool` (`multiply`), `DivideTool` (`divide`), `ExponentialTool` (`calculate_exponential`), `SquareRootTool` (`calculate_square_root`), `NthRootTool` (`calculate_nth_root`) | `EvaluateTool` (`evaluate`) |
| `FactorialTool` (`calculate_factorial`, input `number`) | `FactorialTool` (`factorial`, input `n`) |
| `MeanTool` (`calculate_mean`) | `MeanTool` (`mean`) |
| `MedianTool` (`calculate_median`) | `MedianTool` (`median`) |
| `ModeTool` (`calculate_mode`) | `ModeTool` (`mode`) |
| `VarianceTool` (`calculate_variance`) | `VarianceTool` (`variance`) |
| `StandardDeviationTool` (`calculate_standard_deviation`) | `StandardDeviationTool` (`standard_deviation`) |
| — | `CombinationsTool` (`combinations`), `PermutationsTool` (`permutations`), `GcdTool` (`gcd`), `LcmTool` (`lcm`), `ModPowTool` (`mod_pow`), `IsPrimeTool` (`is_prime`), `PrimeFactorsTool` (`prime_factors`) |

Stored data: chat histories saved by 3.x keep the old tool names and results in their past turns. 4.x never checks tool calls in past turns against the registered tools, so these entries need no rewrite. The storage format of the history is covered by guides 30 and 34.

## What to Search For

Run from the application root:

```bash
# 1. Every reference to the calculator namespace (imports, group imports, FQCNs)
grep -rn 'Toolkits.Calculator.' --include='*.php' --exclude-dir=vendor .

# 2. Removed arithmetic classes
grep -rnwE 'SumTool|SubtractTool|MultiplyTool|DivideTool|ExponentialTool|SquareRootTool|NthRootTool' --include='*.php' --exclude-dir=vendor .

# 3. Statistics tools built with arguments (also matches argument lists that continue on the next line)
grep -rnE '(MeanTool|MedianTool|VarianceTool|StandardDeviationTool)(::make)?[(] *([^) ]|$)' --include='*.php' --exclude-dir=vendor .

# 4. App subclasses of the calculator tools that still exist
grep -rnwE 'extends +[A-Za-z0-9_\\]*(FactorialTool|MeanTool|MedianTool|ModeTool|VarianceTool|StandardDeviationTool)' --include='*.php' --exclude-dir=vendor .

# 5. Old tool names in any file type: code, prompts, evaluation datasets, frontend code
grep -rnwE 'substract|calculate_(exponential|square_root|nth_root|factorial|mean|median|mode|standard_deviation|variance)' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .

# 6. Quoted generic names: keep only the hits that name a calculator tool
grep -rnE "[\"'](sum|multiply|divide)[\"']" --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .

# 7. Code that reads tool results: keep the hits that handle calculator results
grep -rnE 'getResult[(]' --include='*.php' --exclude-dir=vendor .

# 8. The factorial input `number`, in files that mention the factorial tool
grep -rlE 'FactorialTool|calculate_factorial' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git . | xargs grep -nw 'number' /dev/null

# 9. The app's own tools that already use one of the new calculator names
grep -rnE "(name *=|setName[(]) *[\"'](evaluate|factorial|combinations|permutations|gcd|lcm|mod_pow|is_prime|prime_factors|mean|median|mode|variance|standard_deviation)[\"']" --include='*.php' --exclude-dir=vendor .

# 10. bcmath: is it declared, and is it loaded locally?
grep -n 'ext-bcmath' composer.json; php -m | grep -i bcmath
```

How to follow the hits:

- Pattern 2 matches class names only. Check that each hit's `use` import points to `NeuronAI\Tools\Toolkits\Calculator` and not to an app class with the same name.
- Pattern 1 lists every file that uses the calculator. Open each one to find `CalculatorToolkit` registrations with their `only()`/`exclude()`/`with()` chains, and app toolkits whose `provide()` lists calculator tools.
- System prompts can mention the tools in plain prose that patterns 5 and 6 miss ("add the numbers with sum"). Read the `instructions()`/`setInstructions()` text and the prompt files of every agent that registers calculator tools.
- Leave `calculate_age` alone: it is the unchanged Calendar tool.

If pattern 1 finds nothing, this guide does not apply.

## How to Refactor

### Case 1: Arithmetic tools registered directly

This covers `tools()` arrays, `addTool()`/`setTools()` calls and the `provide()` method of the app's own toolkits. Replace all the arithmetic tools with a single `EvaluateTool`, and move any chained configuration onto it. If the removed tools had different settings (for example different `setMaxRuns()` values), ask the developer which settings `evaluate` should get.

**Before (3.x):**

```php
use NeuronAI\Tools\Toolkits\Calculator\DivideTool;
use NeuronAI\Tools\Toolkits\Calculator\MultiplyTool;
use NeuronAI\Tools\Toolkits\Calculator\SquareRootTool;
use NeuronAI\Tools\Toolkits\Calculator\SubtractTool;
use NeuronAI\Tools\Toolkits\Calculator\SumTool;

protected function tools(): array
{
    return [
        SumTool::make(),
        SubtractTool::make(),
        MultiplyTool::make(),
        DivideTool::make()->setMaxRuns(5),
        SquareRootTool::make(),
    ];
}
```

**After (4.x):**

```php
use NeuronAI\Tools\Toolkits\Calculator\EvaluateTool;

protected function tools(): array
{
    return [
        EvaluateTool::make()->setMaxRuns(5),
    ];
}
```

Remove the `use` imports of the removed classes.

### Case 2: `CalculatorToolkit` filters that name the removed classes

1. `only([...])`: replace every arithmetic class with a single `EvaluateTool::class` entry.
2. `exclude([...])`: if the list names all seven arithmetic classes, replace them with a single `EvaluateTool::class`. If it names only some, delete those entries, because `evaluate` performs every operation, and report this to the developer.
3. `with(SumTool::class, $callback)` becomes `with(EvaluateTool::class, $callback)`. `with()` keeps one callback per class, and the last call wins. If several arithmetic classes had callbacks that differ, ask the developer which one `evaluate` should get.
4. Entries for `FactorialTool`, `MeanTool`, `MedianTool`, `ModeTool`, `VarianceTool` and `StandardDeviationTool` keep their class names.
5. If `exclude()` names `FactorialTool`, ask the developer whether it should also exclude the seven new integer tools (`CombinationsTool`, `PermutationsTool`, `GcdTool`, `LcmTool`, `ModPowTool`, `IsPrimeTool`, `PrimeFactorsTool`). The toolkit now offers them by default.

**Before (3.x):**

```php
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calculator\DivideTool;
use NeuronAI\Tools\Toolkits\Calculator\MeanTool;
use NeuronAI\Tools\Toolkits\Calculator\MultiplyTool;
use NeuronAI\Tools\Toolkits\Calculator\SumTool;

CalculatorToolkit::make()
    ->only([SumTool::class, MultiplyTool::class, DivideTool::class, MeanTool::class])
    ->with(SumTool::class, fn (ToolInterface $tool): ToolInterface => $tool->setMaxRuns(10));
```

**After (4.x):**

```php
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calculator\EvaluateTool;
use NeuronAI\Tools\Toolkits\Calculator\MeanTool;

CalculatorToolkit::make()
    ->only([EvaluateTool::class, MeanTool::class])
    ->with(EvaluateTool::class, fn (ToolInterface $tool): ToolInterface => $tool->setMaxRuns(10));
```

A filtered toolkit still needs ext-bcmath (Case 8).

### Case 3: Statistics tools built with arguments

Edit every hit of pattern 3, even the ones that still run: positional arguments are now silently ignored. In 3.x, `MeanTool` and `MedianTool` took `(int $precision = 2)`. `VarianceTool` and `StandardDeviationTool` took `(int $precision = 2, bool $sample = true)`.

1. If the 3.x call forced population statistics (`sample: false`, or `false` as the second positional argument), build a population subclass instead. Create it once in the app's tool namespace, as in the After below.
2. Otherwise, remove every argument: `MeanTool::make(4)` becomes `MeanTool::make()`. The model now picks sample or population through the `population` input, and the default is a sample.
3. Fix `with()` callbacks that only rebuilt the tool with arguments. When the rebuilt tool now takes no arguments, delete the `with()` entry. When the rebuild forced population, make it return the population subclass.
4. Report the removed rounding to the developer (Case 5).

**Before (3.x):**

```php
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calculator\MeanTool;
use NeuronAI\Tools\Toolkits\Calculator\MedianTool;
use NeuronAI\Tools\Toolkits\Calculator\StandardDeviationTool;
use NeuronAI\Tools\Toolkits\Calculator\VarianceTool;

$toolkit = CalculatorToolkit::make()
    ->with(StandardDeviationTool::class, fn (ToolInterface $tool): ToolInterface => new StandardDeviationTool(4, false))
    ->with(MeanTool::class, fn (ToolInterface $tool): ToolInterface => MeanTool::make(precision: 4));

$tools = [
    new VarianceTool(precision: 3, sample: false),
    MedianTool::make(4),
];
```

**After (4.x):**

```php
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calculator\MedianTool;
use NeuronAI\Tools\Toolkits\Calculator\StandardDeviationTool;
use NeuronAI\Tools\Toolkits\Calculator\VarianceTool;

class PopulationStandardDeviationTool extends StandardDeviationTool
{
    public function __invoke(array $numbers, ?bool $population = null): string|ToolOutput
    {
        return parent::__invoke($numbers, true);
    }
}

class PopulationVarianceTool extends VarianceTool
{
    public function __invoke(array $numbers, ?bool $population = null): string|ToolOutput
    {
        return parent::__invoke($numbers, true);
    }
}

$toolkit = CalculatorToolkit::make()
    ->with(StandardDeviationTool::class, fn (ToolInterface $tool): ToolInterface => new PopulationStandardDeviationTool());

$tools = [
    new PopulationVarianceTool(),
    MedianTool::make(),
];
```

The subclasses keep the tool names `standard_deviation` and `variance`.

### Case 4: App subclasses of calculator tools

Guide 3 left these classes untouched for this step. Apply these rules:

1. Parent `MeanTool`, `MedianTool`, `ModeTool`, `VarianceTool` or `StandardDeviationTool`: delete `parent::__construct(...)`, because in 4.x it throws `Error: Cannot call constructor`. Delete the constructor if it is left empty. Handle the arguments it passed as in Case 3: a forced population becomes an `__invoke()` override that passes `true` as `$population`, like the Case 3 subclasses.
2. Parent `FactorialTool`: keep `parent::__construct()` without arguments. It now checks for ext-bcmath.
3. A name or description set in the constructor becomes a property default (`protected string $name = '...';`, `protected ?string $description = '...';`), or stays a `setName()`/`setDescription()` call.
4. An `__invoke()` override takes the 4.x parent's parameters and returns `string|ToolOutput`. The parameters are `array $numbers` for Mean, Median and Mode; `array $numbers, ?bool $population = null` for Variance and StandardDeviation; `int $n` for Factorial. The tool passes its inputs by name, so an old `$number` parameter on a factorial override fails. Return failures as `ToolOutput::error('...')` instead of `['error' => ...]` arrays.
5. `$this->precision` and `$this->sample` no longer exist. `sample` becomes the `$population` argument. Remove the precision rounding and report it (Case 5).
6. A subclass of one of the seven removed arithmetic classes has no parent in 4.x. Ask the developer whether to keep the tool or replace it with `EvaluateTool`. To keep it, rewrite it as a direct `NeuronAI\Tools\Tool` subclass as guide 3 describes, and copy in the 3.x name, `properties()` and `__invoke()` it inherited.
7. Guide 3 skipped these classes, so apply its Cases 6, 7, 8 and 10 to them now: `setCallable()`/`$this->callback`, `setMaxTries()` becomes `setMaxRuns()`, `HasRunKey` leaves the `implements` list, and members reserved by `Tool` are renamed. Also rename any member the subclass declares under a name its 4.x parent now uses, and update its call sites: `mean()`, `variance()`, `result()`, `invalidDataset()` and `invalidSample()` on every statistics parent; `median()` on `MedianTool`; `modes()` on `ModeTool`; `gcd()`, `isPrime()`, `invalidIntegers()` and the constants `MAX_INPUT` and `MILLER_RABIN_WITNESSES` on `FactorialTool`. A clash either stops the class from loading or silently replaces the parent's logic.

**Before (3.x):**

```php
use NeuronAI\Tools\Toolkits\Calculator\FactorialTool;
use NeuronAI\Tools\Toolkits\Calculator\StandardDeviationTool;

class ScoreSpreadTool extends StandardDeviationTool
{
    public function __construct()
    {
        parent::__construct(precision: 1, sample: false);
        $this->name = 'score_spread';
    }

    public function __invoke(array $numbers): float|array
    {
        return parent::__invoke(array_values(array_filter($numbers, fn (int|float $score): bool => $score >= 0)));
    }
}

class LimitedFactorialTool extends FactorialTool
{
    public function __construct()
    {
        parent::__construct();
        $this->setMaxRuns(3);
    }

    public function __invoke(int $number): int|float|array
    {
        if ($number > 50) {
            return ['error' => 'Only factorials up to 50 are allowed.'];
        }

        return parent::__invoke($number);
    }
}
```

**After (4.x):**

```php
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\Toolkits\Calculator\FactorialTool;
use NeuronAI\Tools\Toolkits\Calculator\StandardDeviationTool;

class ScoreSpreadTool extends StandardDeviationTool
{
    protected string $name = 'score_spread';

    public function __invoke(array $numbers, ?bool $population = null): string|ToolOutput
    {
        return parent::__invoke(array_values(array_filter($numbers, fn (int|float $score): bool => $score >= 0)), true);
    }
}

class LimitedFactorialTool extends FactorialTool
{
    public function __construct()
    {
        parent::__construct();
        $this->setMaxRuns(3);
    }

    public function __invoke(int $n): string|ToolOutput
    {
        if ($n > 50) {
            return ToolOutput::error('Only factorials up to 50 are allowed.');
        }

        return parent::__invoke($n);
    }
}
```

### Case 5: Calculator results: reading them, direct calls, rounding and expected values

Failures used to be JSON strings. They are now `ToolOutput` errors. Read a result only when `hasResult()` is true.

**Before (after guide 4):**

```php
foreach ($message->getToolCalls() as $call) {
    $payload = json_decode($call->getResult(), true);

    if (is_array($payload) && isset($payload['error'])) {
        $logger->warning($payload['error']);
    }
}
```

**After (4.x):**

```php
use NeuronAI\Tools\ToolOutput;

foreach ($message->getToolCalls() as $call) {
    if (!$call->hasResult()) {
        continue;
    }

    $result = $call->getResult();

    if ($result instanceof ToolOutput && $result->isError()) {
        $logger->warning($result->getText());
    }
}
```

Code that invokes a tool directly now gets `string|ToolOutput` instead of `int|float|array`. Rename the factorial input `number` to `n` in named arguments, in `setInputs()` and in test fixtures.

**Before (3.x):**

```php
use NeuronAI\Tools\Toolkits\Calculator\DivideTool;
use NeuronAI\Tools\Toolkits\Calculator\FactorialTool;

$quotient = DivideTool::make()(10, 4);

$factorial = (new FactorialTool())(number: 25);

$tool = FactorialTool::make()->setInputs(['number' => 25]);
$tool->execute();
```

**After (4.x):**

```php
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\Toolkits\Calculator\EvaluateTool;
use NeuronAI\Tools\Toolkits\Calculator\FactorialTool;
use RuntimeException;

$quotient = EvaluateTool::make()('10 / 4'); // '2.5'

if ($quotient instanceof ToolOutput) {
    throw new RuntimeException($quotient->getText());
}

$factorial = (new FactorialTool())(n: 25);

$tool = FactorialTool::make()->setInputs(['n' => 25]);
$tool->execute();
```

Statistics results are no longer rounded: 3.x rounded them to `precision`, 2 decimals by default, and 4.x prints up to 15 significant digits. If the app uses any statistics tool, directly or through `CalculatorToolkit`, report this to the developer.

Update the tests and evaluation datasets that assert exact results:

| Call | 3.x result | 4.x result |
|---|---|---|
| `divide` 10, 3 → `evaluate` `10 / 3` | `3.3333333333333` | `3.33333333333333` |
| `calculate_mean` [1, 2, 2] → `mean` | `1.67` | `1.66666666666667` |
| `calculate_mode` [1, 2, 2, 1] → `mode` | `[1,2]` | `1, 2` |
| `calculate_factorial` 25 → `factorial` | `1.5511210043331E+25` | `15511210043330985984000000` |
| `divide` 10, 0 → `evaluate` `10/0` | `{"operation":"divide","error":"Division by zero is not allowed."}` | `ToolOutput` error `Division by zero at position 3` (the position depends on the expression) |
| `calculate_mean` [] → `mean` | `{"error":"Data array cannot be empty"}` | `ToolOutput` error `The dataset cannot be empty.` |
| `calculate_square_root` -1 → `evaluate` `sqrt(-1)` | `NAN` | `ToolOutput` error `sqrt() is undefined at position 1` |

### Case 6: Tool names in code, configuration, prompts and data

Rename every hit of patterns 5 and 6 using the table in the Summary. Several old names become `evaluate`, so merge any duplicates this creates. Check these places:

- `toolErrorHandler()` callbacks and `resolveToolErrorHandler()` overrides that compare `$call->getName()`.
- Code that inspects `ToolCall` entries of `ToolCallMessage`/`ToolResultMessage` by name.
- `getRunKey()` overrides that branch on the tool name.
- `ToolApproval` middleware configuration (guide 28 migrates the middleware itself; here, rename only the calculator entries):
  - rename name entries and class-string entries (`DivideTool::class` becomes `EvaluateTool::class`);
  - factorial callbacks read `$args['n']` instead of `$args['number']`;
  - if the configuration gated only some of the seven arithmetic tools, ask the developer whether every `evaluate` call should need approval (keep one `evaluate` entry) or none should (delete the entries).
- Code that reads the inputs of an arithmetic call (`number1`, `number2`, `number`, `exponent`, `root_degree`), in any of the places above. There is no equivalent, because `evaluate` receives one `expression` string: ask the developer how to rewrite it.
- Evaluation datasets, fixtures and assertions that expect a tool name, and frontend code that renders tool calls or approval actions by name.
- System prompts, `instructions()` and prompt files. Also delete any instruction to compute step by step with `sum`/`multiply`: the 4.x toolkit guidelines tell the model to pass the whole expression to `evaluate`.

**Before (after guide 4):**

```php
use NeuronAI\Tools\ToolCall;
use Throwable;

$agent->toolErrorHandler(
    fn (Throwable $e, ToolCall $call): ?string => $call->getName() === 'divide'
        ? 'The division failed: check the operands.'
        : null
);
```

**After (4.x):**

```php
use NeuronAI\Tools\ToolCall;
use Throwable;

$agent->toolErrorHandler(
    fn (Throwable $e, ToolCall $call): ?string => $call->getName() === 'evaluate'
        ? 'The division failed: check the operands.'
        : null
);
```

### Case 7: The new names collide with the app's own tools

Look at the hits of pattern 9. If one of those tools is registered on an agent that also registers `CalculatorToolkit` or the matching calculator tool, the run throws `ToolException` ("Tool names must be unique"). The same happens when one agent now gets `evaluate` twice, for example from `CalculatorToolkit` and from a standalone `EvaluateTool` that replaced an arithmetic tool in Case 1. Do not rename anything here: list these agents in your report, and guide 11 resolves the duplicates.

### Case 8: The bcmath extension

1. Check whether the app still uses `CalculatorToolkit`, `FactorialTool` or any of the new integer tools. If it does not, skip the rest of this case.
2. Tell the developer that every environment that runs these agents needs ext-bcmath: servers, queue workers, CI and containers. Without it, the run throws `ToolException: ...FactorialTool requires the bcmath PHP extension (ext-bcmath).` Include the result of pattern 10.
3. Propose adding `"ext-bcmath": "*"` to the `require` section of the app's `composer.json`, and add it only if the developer agrees. Do not edit Dockerfiles, CI configuration or server packages.
4. If the developer says bcmath will not be available, replace `CalculatorToolkit` with an explicit list of the tools that do not need it. Keep only the tools the toolkit's `only()`/`exclude()` filters left, and apply its `with()` callbacks to those instances directly. Ask the developer whether to drop any `FactorialTool` the app uses directly, since it has no replacement without bcmath.

**Before (3.x):**

```php
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;

protected function tools(): array
{
    return [
        CalculatorToolkit::make(),
    ];
}
```

**After (4.x, without bcmath):**

```php
use NeuronAI\Tools\Toolkits\Calculator\EvaluateTool;
use NeuronAI\Tools\Toolkits\Calculator\MeanTool;
use NeuronAI\Tools\Toolkits\Calculator\MedianTool;
use NeuronAI\Tools\Toolkits\Calculator\ModeTool;
use NeuronAI\Tools\Toolkits\Calculator\StandardDeviationTool;
use NeuronAI\Tools\Toolkits\Calculator\VarianceTool;

protected function tools(): array
{
    return [
        EvaluateTool::make(),
        MeanTool::make(),
        MedianTool::make(),
        ModeTool::make(),
        VarianceTool::make(),
        StandardDeviationTool::make(),
    ];
}
```

## Checklist

- [ ] Pattern 2 finds nothing: no reference to `SumTool`, `SubtractTool`, `MultiplyTool`, `DivideTool`, `ExponentialTool`, `SquareRootTool` or `NthRootTool` is left.
- [ ] Pattern 3 finds nothing. PHPStan reports `new` calls with arguments ("does not have a constructor and must be instantiated without any parameters"), but it does not report arguments passed to `::make()`.
- [ ] Forced population statistics use a population subclass, and every other statistics tool is built without arguments.
- [ ] Pattern 5 finds nothing, and every remaining hit of pattern 6 is unrelated to the calculator.
- [ ] `only()`, `exclude()` and `with()` name `EvaluateTool::class` instead of the removed classes.
- [ ] Calculator subclasses no longer call `parent::__construct()` on a statistics parent, their `__invoke()` signatures match the 4.x parents and return `string|ToolOutput`, no code uses `$this->precision` or `$this->sample`, and no calculator subclass calls `setMaxTries()`, implements `HasRunKey` or declares a member named like a member of its 4.x parent.
- [ ] No code parses calculator results as JSON. Result reads check `hasResult()` and `ToolOutput::isError()`.
- [ ] The factorial input is `n` everywhere: named arguments, `setInputs()`, `ToolApproval` callbacks and fixtures.
- [ ] Expected outputs in tests and evaluation datasets match the 4.x result strings.
- [ ] Reported to the developer: the ext-bcmath requirement and their decision on `composer.json`, the removed rounding, `exclude()` entries that were deleted, and the name collisions left for guide 11. Where those situations occurred, the developer was asked about differing arithmetic settings or `with()` callbacks, partial approval entries, code reading arithmetic inputs and subclasses of removed classes.
