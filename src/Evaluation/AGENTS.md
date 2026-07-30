# Evaluation Module

Dataset-driven AI evaluation with flexible assertions and output drivers.

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
| `Assertions/` | Built-in: string, JSON, similarity, distance |
| `Runner/` | `EvaluatorRunner`, `EvaluatorResult`, `EvaluatorSummary` |
| `Output/` | `ConsoleOutput`, `JsonOutput`, `OutputPipeline` |
| `Config/` | Config loading and driver resolution |
| `Discovery/` | Auto-discover evaluator classes |

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

## Output Configuration

Create `neuron-evaluation.php` in project root. Each `output` entry is either a
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

    public function output(EvaluatorSummary $summary): void
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
