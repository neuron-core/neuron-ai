# Evaluation Module

Dataset-driven AI evaluation with flexible assertions and output drivers.

## Running Evaluations

```bash
vendor/bin/neuron evaluation path/to/evaluators
vendor/bin/neuron evaluation --verbose path/to/evaluators
```

## Architecture

**Template Method Pattern**: `BaseEvaluator` workflow:
1. `setUp()` - Initialize resources
2. `getDataset()` - Provide test data (abstract)
3. `run()` - Execute application logic (abstract)
4. `evaluate()` - Assert results (abstract)

## Core Components

| Directory | Purpose |
|-----------|---------|
| `Contracts/` | Interfaces: `EvaluatorInterface`, `DatasetInterface`, `AssertionInterface`, `OutputDriverInterface` |
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

Create `neuron-evaluation.php` in project root:

```php
return [
    'output' => [
        ConsoleOutput::class,
        JsonOutput::class => ['path' => 'results.json'],
    ],
];
```

## Custom Output Drivers

Implement `OutputDriverInterface`:

```php
class DatabaseOutput implements OutputDriverInterface
{
    public function output(EvaluatorSummary $summary): void
    {
        // Store in database
    }
}
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
