# Upgrade: Calculator toolkit built around `evaluate`

## Summary

The Calculator toolkit no longer exposes one tool per arithmetic operator. The
model was forced to decompose every formula into a chain of `sum`, `multiply`,
`divide` calls and to hand-copy intermediate floats between them, which is
exactly where transcription errors crept in. The toolkit is now organised in
three groups:

1. **One `EvaluateTool` (`evaluate`) replaces seven tools.** `SumTool`,
   `SubtractTool`, `MultiplyTool`, `DivideTool`, `ExponentialTool`,
   `SquareRootTool` and `NthRootTool` are removed. `evaluate` takes a whole
   expression (`(-b + sqrt(b^2 - 4*a*c)) / (2*a)`) with operators, parentheses,
   the constants `pi` and `e`, and a fixed function table covering roots,
   logarithms, trigonometry, hyperbolics and rounding. It is a hand-written
   parser: no `eval()`, no new dependency.

2. **Exact integer tools on bcmath.** `FactorialTool` now returns the exact
   digits as a string for any `n` up to 1000 (it used to return a float above
   20), and is joined by `CombinationsTool`, `PermutationsTool`, `GcdTool`,
   `LcmTool`, `ModPowTool`, `IsPrimeTool` and `PrimeFactorsTool`. These tools
   throw a `ToolException` in their constructor when the `bcmath` extension is
   missing, so `CalculatorToolkit::make()` fails at agent boot rather than
   mid-conversation.

3. **Statistics tools lost their constructor arguments.** `MeanTool`,
   `MedianTool`, `StandardDeviationTool` and `VarianceTool` no longer take
   `precision` (results are no longer rounded: every tool prints up to 15
   significant digits, independent of the `precision` ini setting) nor `sample`.
   Whether a dataset is a sample or the whole population is now a `population`
   boolean property the **model** sets per call, defaulting to sample.

Two cross-cutting changes come with the redesign:

- **Every tool name changed.** Names are what the model reads and what is
  recorded in chat history, run-key tracking, approval policies and evaluation
  trajectories:

  | 3.x name | 4.x name |
  |----------|----------|
  | `sum`, `substract`, `multiply`, `divide`, `calculate_exponential`, `calculate_square_root`, `calculate_nth_root` | `evaluate` |
  | `calculate_factorial` (property `number`) | `factorial` (property `n`) |
  | `calculate_mean` | `mean` |
  | `calculate_median` | `median` |
  | `calculate_mode` | `mode` |
  | `calculate_standard_deviation` | `standard_deviation` |
  | `calculate_variance` | `variance` |
  | — | `combinations`, `permutations`, `gcd`, `lcm`, `mod_pow`, `is_prime`, `prime_factors` |

- **Failures are `ToolOutput::error()` results**, following the convention of
  guide 12. The ad hoc JSON payloads (`{"operation":"divide","error":...}`,
  `{"error":...}`) and the bare `NAN` / `INF` strings are gone; a division by
  zero now reads `Division by zero at position 3`.

## What to Search For

```
grep -rn "Toolkits\\\\Calculator\\\\" --include="*.php" .
grep -rn "SumTool\|SubtractTool\|MultiplyTool\|DivideTool\|ExponentialTool\|SquareRootTool\|NthRootTool" --include="*.php" .
grep -rn "MeanTool(\|MedianTool(\|StandardDeviationTool(\|VarianceTool(" --include="*.php" .
grep -rn "'substract'\|'calculate_[a-z_]*'" --include="*.php" .
grep -rn "'sum'\|'multiply'\|'divide'" --include="*.php" .
```

The last pattern matches generic words: review each hit and keep only the ones
that name a calculator tool (evaluation assertions, `getRunKey()` overrides,
`withApprovalPolicy()` callbacks, `toolErrorHandler` branches, system prompts
that mention the tools by name). Also check `composer.json` and the deployment
images for the `bcmath` extension.

## How to Refactor

### Case 1: Toolkit filters naming the removed classes

**Before:**

```php
CalculatorToolkit::make()
    ->only([SumTool::class, MultiplyTool::class, DivideTool::class])
    ->with(SumTool::class, fn (ToolInterface $tool): ToolInterface => $tool->setMaxRuns(10));
```

**After:**

```php
CalculatorToolkit::make()
    ->only([EvaluateTool::class])
    ->with(EvaluateTool::class, fn (ToolInterface $tool): ToolInterface => $tool->setMaxRuns(10));
```

`exclude()` / `only()` entries for `FactorialTool`, `MeanTool`, `MedianTool`,
`ModeTool`, `StandardDeviationTool` and `VarianceTool` keep working unchanged.

### Case 2: Arithmetic tools instantiated directly

**Before:**

```php
protected function tools(): array
{
    return [
        SumTool::make(),
        SubtractTool::make(),
        MultiplyTool::make(),
        DivideTool::make(),
        SquareRootTool::make(),
    ];
}
```

**After:**

```php
protected function tools(): array
{
    return [
        EvaluateTool::make(),
    ];
}
```

### Case 3: Statistics tools constructed with arguments

**Before:**

```php
new MeanTool(precision: 4),
new StandardDeviationTool(precision: 4, sample: false),
```

**After:**

```php
MeanTool::make(),
StandardDeviationTool::make(),
```

Rounding belongs to the presentation of the answer: instruct the model, or
round in the application, but the tool itself no longer truncates precision.
The model chooses sample or population per call through the `population`
property. If your application must force population statistics regardless of
what the model sends, subclass the tool:

```php
class PopulationStandardDeviationTool extends StandardDeviationTool
{
    public function __invoke(array $numbers, ?bool $population = null): string|ToolOutput
    {
        return parent::__invoke($numbers, true);
    }
}
```

### Case 4: Code reading calculator failures out of the result

**Before:**

```php
$payload = json_decode($call->getResult(), true);

if (isset($payload['error'])) {
    // handle the failure
}
```

**After:**

```php
$result = $call->getResult();

if ($result instanceof ToolOutput && $result->isError()) {
    $feedback = $result->getText();
}
```

Read the result only when `$call->hasResult()` is true, as in guide 12.

### Case 5: Tool names in assertions, policies and prompts

**Before:**

```php
$this->assert(new ToolWasCalled('multiply'), $trajectory);
$this->assert(new ToolWasCalled('calculate_standard_deviation'), $trajectory);
```

**After:**

```php
$this->assert(new ToolWasCalled('evaluate'), $trajectory);
$this->assert(new ToolWasCalled('standard_deviation'), $trajectory);
```

Apply the same rename to `withApprovalPolicy()` callbacks, `getRunKey()`
overrides and `toolErrorHandler` branches that compare `getName()` against a
calculator tool, and to system prompts that told the model to compute
"step by step" with `sum` or `multiply`: the toolkit guidelines now tell it to
pass the whole formula to `evaluate`, and prompts saying otherwise work against
it.

Evaluation datasets that assert exact output strings of the old tools need new
expectations too: `10 / 3` used to serialize as `3.3333333333333` (the ini
`precision` of 14) and now prints `3.33333333333333`.

### Case 6: The `bcmath` extension

`ext-bcmath` is listed under `suggest` in the framework's `composer.json`. The
integer tools refuse to be constructed without it, and since
`CalculatorToolkit::provide()` instantiates every tool, the toolkit as a whole
needs the extension. Either enable it in every environment that boots the
agent (`docker-php-ext-install bcmath` on the official images, the
`php8.x-bcmath` package on Debian and Ubuntu), or, if you only need real-valued
math, compose the tools yourself without the integer group:

```php
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

### In-flight runs

A run suspended while waiting for approval of a call to one of the old tool
names cannot resume after the upgrade: `ToolNode` resolves the call by name
against the live registry and raises a `ToolException` for a name it does not
know. Let such runs finish, or abandon them, before deploying.

## Verification Checklist

- [ ] No reference to `SumTool`, `SubtractTool`, `MultiplyTool`, `DivideTool`, `ExponentialTool`, `SquareRootTool` or `NthRootTool` remains
- [ ] Statistics tools are constructed without arguments
- [ ] Code reading calculator results checks `ToolOutput::isError()` instead of parsing a JSON error payload
- [ ] Evaluation assertions, approval policies, run keys and system prompts use the 4.x tool names
- [ ] `ext-bcmath` is enabled wherever `CalculatorToolkit` boots, or the toolkit is composed without the integer tools
- [ ] No suspended run is waiting on a tool call with an old name
- [ ] The application's test suite and static analysis pass
