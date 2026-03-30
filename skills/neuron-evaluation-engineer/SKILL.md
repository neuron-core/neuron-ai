---
name: neuron-evaluation-engineer
description: Create and run AI evaluations with datasets, assertions, and output drivers in Neuron AI. Use this skill whenever the user mentions evaluation, testing AI systems, creating evaluators, dataset-driven testing, assertion-based validation, or wants to measure AI system performance. Also trigger for tasks involving evaluator discovery, output configuration, result analysis, or building custom assertions.
---

# Neuron AI Evaluation Engineer

This skill helps you create and run evaluations for AI systems in Neuron AI. The evaluation system provides dataset-driven testing with flexible assertions, comprehensive result reporting, and extensible output drivers.

## Core Concepts

### The Evaluation System

Evaluations test AI systems using three main components:

1. **Evaluators** - Test classes that define what to run and how to validate
2. **Datasets** - Test data sources (arrays, JSON files)
3. **Assertions** - Validation rules for checking outputs

```
Dataset Items → Evaluator::run() → Output → Evaluator::evaluate() → Assertions → Results
```

### Evaluation Flow

For each dataset item:
1. `setUp()` - Initialize resources (once per evaluator)
2. `run(datasetItem)` - Execute your AI logic
3. `evaluate(output, datasetItem)` - Assert against expected results
4. Repeat for next item

**Note:** Each evaluation starts with a fresh assertion executor - no manual reset needed.

## Creating Custom Evaluators

### Basic Evaluator

```php
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Assertions\StringContains;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use NeuronAI\Agent;
use NeuronAI\Agent\SystemPrompt;

class ContainsEvaluator extends BaseEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new ArrayDataset([
            [
                'text' => 'I love this product!',
                'content' => 'product',
            ],
            [
                'text' => 'This is terrible.',
                'content' => 'positive',
            ],
        ]);
    }

    public function run(array $datasetItem): mixed
    {
        $response = MyAgent::make()->chat(
            new UserMessage($datasetItem['text'])
        )->getMessage();

        return $response->getContent();
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(
            new StringContains($datasetItem['content']),
            $output
        );
    }
}
```

### JSON Dataset

For larger datasets, use JSON files:

```php
use NeuronAI\Evaluation\Dataset\JsonDataset;

public function getDataset(): DatasetInterface
{
    return new JsonDataset(__DIR__ . '/datasets/sentiment.json');
}
```

JSON format (`sentiment.json`):
```json
[
    {"text": "I love this!", "expected": "positive"},
    {"text": "This is bad.", "expected": "negative"}
]
```

## Built-in Assertions

### String Assertions

#### StringContains
Check if the output contains a substring:

```php
$this->assert(new StringContains('positive'), $output);
```

#### StringContainsAll
Check if the output contains all keywords:

```php
$this->assert(new StringContainsAll(['hello', 'world']), $output);
```

#### StringContainsAny
Check if the output contains any of the keywords:

```php
$this->assert(new StringContainsAny(['success', 'completed']), $output);
```

#### StringStartsWith
Check if the output starts with a prefix:

```php
$this->assert(new StringStartsWith('Hello'), $output);
```

#### StringEndsWith
Check if the output ends with a suffix:

```php
$this->assert(new StringEndsWith('!'), $output);
```

#### StringLengthBetween
Check if the string length is within range:

```php
$this->assert(new StringLengthBetween(10, 100), $output);
```

#### StringDistance
Check string similarity using Levenshtein distance:

```php
$this->assert(new StringDistance(
    reference: 'expected text',
    threshold: 0.5,      // Minimum similarity score
    maxDistance: 50          // Maximum allowed edits
), $output);
```

#### StringSimilarity
Check string similarity using embeddings:

```php
use NeuronAI\Evaluation\Assertions\StringSimilarity;
use NeuronAI\RAG\Embeddings\OpenAI\OpenAIEmbeddings;

$this->assert(new StringSimilarity(
    reference: 'The quick brown fox',
    embeddingsProvider: new OpenAIEmbeddings(key: 'YOUR_KEY'),
    threshold: 0.6
), $output);
```

### Pattern Assertions

#### MatchesRegex
Match against regular expression:

```php
$this->assert(new MatchesRegex('/^\d{3}-\d{2}-\d{4}$/'), $output);
```

### Structure Assertions

#### IsValidJson
Check if the output is valid JSON:

```php
$this->assert(new IsValidJson(), $output);
```

### AI Judge Assertions

#### AgentJudge
Use an AI agent to evaluate outputs with custom criteria:

```php
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Agent;

$judge = Agent::make()
    ->setInstructions('You are an expert evaluator for customer support responses.');

// Reference-free evaluation (criteria only)
$this->assert(new AgentJudge(
    judge: $judge,
    criteria: 'Response should be helpful, polite, and address the customer\'s question directly',
    threshold: 0.7
), $output);

// Reference-based evaluation (compare to expected)
$this->assert(new AgentJudge(
    judge: $judge,
    criteria: 'The response should convey the same meaning as the reference',
    threshold: 0.8,
    reference: $datasetItem['expected_answer']
), $output);

// With few-shot examples for calibration
$this->assert(new AgentJudge(
    judge: $judge,
    criteria: 'Rate the factual accuracy of the response',
    threshold: 0.7,
    examples: [
        [
            'input' => 'What is 2+2?',
            'output' => '2+2 equals 4',
            'score' => 1.0,
            'reasoning' => 'Mathematically correct and clear.',
        ],
    ]
), $output);
```

#### Pre-configured Judges

Built-in judges for common evaluation scenarios:

```php
use NeuronAI\Evaluation\Assertions\Judges\{FaithfulnessJudge, CorrectnessJudge, RelevanceJudge, HelpfulnessJudge};

// Faithfulness - check if output is grounded in context (no hallucinations)
$this->assert(new FaithfulnessJudge(
    judge: $judge,
    context: $retrievedDocuments,
    threshold: 0.7
), $output);

// Correctness - compare to expected answer
$this->assert(new CorrectnessJudge(
    judge: $judge,
    expected: $datasetItem['expected_answer'],
    threshold: 0.7
), $output);

// Relevance - check if output addresses the question
$this->assert(new RelevanceJudge(
    judge: $judge,
    question: $datasetItem['question'],
    threshold: 0.7
), $output);

// Helpfulness - evaluate utility and actionability
$this->assert(new HelpfulnessJudge(
    judge: $judge,
    threshold: 0.7
), $output);
```

### Creating Custom Assertions

```php
use NeuronAI\Evaluation\Assertions\AbstractAssertion;
use NeuronAI\Evaluation\AssertionResult;

class GreaterThanAssertion extends AbstractAssertion
{
    public function __construct(
        private readonly float $threshold
    ) {}

    public function evaluate(mixed $actual): AssertionResult
    {
        if (!is_numeric($actual)) {
            return AssertionResult::fail(
                0.0,
                'Expected numeric value, got ' . gettype($actual),
            );
        }

        if ($actual > $this->threshold) {
            return AssertionResult::pass(1.0);
        }

        return AssertionResult::fail(
            0.0,
            "Expected {$actual} to be greater than {$this->threshold}",
        );
    }
}
```

Use it:

```php
$this->assert(new GreaterThanAssertion(0.8), $score);
```

## Running Evaluations

### CLI Command

```bash
# Run all evaluators in a directory
vendor/bin/neuron evaluation /path/to/evaluators

# Verbose output (shows evaluator names)
vendor/bin/neuron evaluation --verbose /path/to/evaluators

# Using --path flag
vendor/bin/neuron evaluation --path=/path/to/evaluators

# Help
vendor/bin/neuron evaluation --help
```

### Programmatic Execution

```php
use NeuronAI\Evaluation\Runner\EvaluatorRunner;

$runner = new EvaluatorRunner();
$evaluator = new MyEvaluator();
$summary = $runner->run($evaluator);

echo "Passed: {$summary->getPassedCount()}\n";
echo "Failed: {$summary->getFailedCount()}\n";
echo "Success Rate: {$summary->getSuccessRate() * 100}%\n";
```

## Output Configuration

### Config File

Create `evaluation.php` in project root:

```php
<?php

use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;

return [
    'output' => [
        // Simple driver (no options)
        ConsoleOutput::class,

        // Driver with options (class as key)
        JsonOutput::class => [
            'path' => 'evaluation-results.json',
        ],
    ],
];
```

**Default behavior**: If no config exists, uses `ConsoleOutput`.

### Built-in Output Drivers

#### ConsoleOutput

```php
ConsoleOutput::class => ['verbose' => true]
```

- `verbose` - Show detailed input/output for failures

#### JsonOutput

```php
// Write to file
JsonOutput::class => ['path' => 'results.json']

// Write to stdout
JsonOutput::class
```

### Creating Custom Output Drivers

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

Register in config:
```php
DatabaseOutput::class => [
    'pdo' => new \PDO('mysql:host=localhost;dbname=evaluations', 'user', 'pass'),
    'table' => 'evaluations',
]
```

## Project Setup

### Configuring Autoloader

Add evaluators directory to `composer.json`:

```json
{
    "autoload-dev": {
        "psr-4": {
            "App\\Evaluators\\": "evaluators/"
        }
    }
}
```

### Directory Structure

```
project/
├── evaluators/
│   ├── SentimentEvaluator.php
│   ├── SummarizationEvaluator.php
│   └── datasets/
│       ├── sentiment.json
│       └── summarization.json
├── evaluation.php
└── vendor/bin/neuron
```

## Result Analysis

### Accessing Results

```php
$summary = $runner->run($evaluator);

// Basic stats
$summary->getPassedCount();      // int
$summary->getFailedCount();      // int
$summary->getTotalCount();       // int
$summary->getSuccessRate();     // float (0.0 - 1.0)

// Timing
$summary->getTotalExecutionTime();      // float (seconds)
$summary->getAverageExecutionTime();    // float (seconds)

// Assertions
$summary->getTotalAssertions();           // int
$summary->getTotalAssertionsPassed();     // int
$summary->getTotalAssertionsFailed();     // int
$summary->getAssertionSuccessRate();      // float (0.0 - 1.0)

// Detailed results
$summary->getResults();                 // array<EvaluatorResult>
$summary->getFailedResults();           // array<EvaluatorResult>

// Assertion failures grouped by location
$summary->getAssertionFailuresByLocation();  // array<string, AssertionFailure[]>
```

### EvaluatorResult

```php
foreach ($summary->getResults() as $result) {
    $result->getIndex();              // int
    $result->isPassed();             // bool
    $result->getInput();             // array
    $result->getOutput();            // mixed
    $result->getExecutionTime();      // float
    $result->getError();             // ?string
    $result->getAssertionsPassed();   // int
    $result->getAssertionsFailed();   // int
    $result->getAssertionFailures(); // array<AssertionFailure>
}
```

### AssertionFailure

```php
$failure->getEvaluatorClass();        // string
$failure->getShortEvaluatorClass(); // string
$failure->getAssertionMethod();     // string
$failure->getMessage();             // string
$failure->getLineNumber();          // int
$failure->getContext();             // array
$failure->getFullDescription();    // string
```

## Common Patterns

### Evaluating Multiple Metrics

```php
public function evaluate(mixed $output, array $datasetItem): void
{
    $this->assert(new StringContains($datasetItem['topic']), $output);
    $this->assert(new StringLengthBetween(50, 500), $output);
    $this->assert(new IsValidJson(), $output);
}
```

### Using AI Judge for Scoring

Use the built-in `AgentJudge` assertion for AI-powered evaluation:

```php
use NeuronAI\Evaluation\Assertions\AgentJudge;
use NeuronAI\Evaluation\Assertions\Judges\CorrectnessJudge;

public function setUp(): void
{
    $this->judge = Agent::make()
        ->setInstructions('You are an expert evaluator for AI responses.');
}

public function evaluate(mixed $output, array $datasetItem): void
{
    // Simple criteria-based evaluation
    $this->assert(new AgentJudge(
        judge: $this->judge,
        criteria: 'Rate the quality and accuracy of the response',
        threshold: 0.7
    ), $output);

    // Or use pre-configured judges
    $this->assert(new CorrectnessJudge(
        judge: $this->judge,
        expected: $datasetItem['expected'],
        threshold: 0.7
    ), $output);
}
```

### Testing RAG Systems

```php
class RAGEvaluator extends BaseEvaluator
{
    public function setUp(): void
    {
        $this->rag = new MyRAGAgent();
    }

    public function run(array $datasetItem): mixed
    {
        return $this->rag->chat(
            new UserMessage($datasetItem['question'])
        )->getMessage()->getContent();
    }

    public function evaluate(mixed $output, array $datasetItem): void
    {
        $this->assert(new StringContainsAny($datasetItem['key_facts']), $output);
        $this->assert(new StringSimilarity(
            reference: $datasetItem['expected_answer'],
            embeddingsProvider: $this->embeddings,
            threshold: 0.7
        ), $output);
    }
}
```

### Comparing Multiple Agents

```php
public function setUp(): void
{
    $this->agentA = new AgentOne();
    $this->agentB = new AgentTwo();
}

public function run(array $datasetItem): mixed
{
    return [
        'agent_a' => $this->agentA->chat(...)->getContent(),
        'agent_b' => $this->agentB->chat(...)->getContent(),
    ];
}

public function evaluate(mixed $output, array $datasetItem): void
{
    $similarity = $this->calculateSimilarity(
        $output['agent_a'],
        $output['agent_b']
    );
    $this->assert(new GreaterThanAssertion(0.8), $similarity);
}
```

## Best Practices

### Evaluator Design

1. **Keep evaluators focused** - One evaluator per use case
2. **Use descriptive dataset items** - Include expected values, metadata
3. **Leverage `setUp()`** - Initialize expensive resources once
4. **Test in isolation** - Make `run()` and `evaluate()` pure functions

### Assertion Usage

1. **Use specific assertions** - Prefer `StringContains` over generic checks
2. **Set appropriate thresholds** - Balance sensitivity vs. false positives
3. **Combine multiple assertions** - Check different aspects of output
4. **Use embeddings for semantic similarity** - Don't rely only on string matching

### Dataset Management

1. **Separate test data** - Keep evaluators in dedicated directory
2. **Use JSON for large datasets** - Easier to maintain than arrays
3. **Include diverse cases** - Edge cases, typical cases, boundary values
4. **Version control datasets** - Track changes to test cases

### Output Configuration

1. **Configure multiple drivers** - Console for quick checks, JSON for CI/CD
2. **Use verbose mode** during development for detailed failure info
3. **Custom drivers** for integration with existing systems (databases, APIs)

## CLI Generation

```bash
# (Note: Neuron CLI doesn't have make:evaluator yet)
# Create evaluator manually in evaluators directory
```

## Testing Evaluators

```php
use PHPUnit\Framework\TestCase;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;

class MyEvaluatorTest extends TestCase
{
    public function testEvaluatorRuns(): void
    {
        $runner = new EvaluatorRunner();
        $evaluator = new MyEvaluator();
        $summary = $runner->run($evaluator);

        $this->assertGreaterThan(0, $summary->getTotalCount());
    }

    public function testEvaluatorHasNoFailures(): void
    {
        $runner = new EvaluatorRunner();
        $evaluator = new MyEvaluator();
        $summary = $runner->run($evaluator);

        $this->assertEquals(0, $summary->getFailedCount());
    }
}
```

## Integration with CI/CD

### GitHub Actions

```yaml
name: Evaluation Tests

on: [push, pull_request]

jobs:
    evaluate:
        runs-on: ubuntu-latest
        steps:
            - uses: actions/checkout@v3
            - name: Setup PHP
              uses: shivammathur/setup-php@v2
              with:
                  php-version: '8.2'
            - name: Install dependencies
              run: composer install
            - name: Run evaluations
              run: vendor/bin/neuron evaluation evaluators --verbose
              env:
                  ANTHROPIC_API_KEY: ${{ secrets.ANTHROPIC_API_KEY }}
```

### Failing on Thresholds

```bash
# Run and exit with 1 if any failures
vendor/bin/neuron evaluation evaluators || exit 1
```

## Key Decision Points

When helping users with evaluations:

1. **Dataset format** depends on:
    - Small datasets → `ArrayDataset` (in code)
    - Large/external datasets → `JsonDataset` (files)

2. **Assertion choice** depends on:
    - Exact matching → `StringContains`, `StringStartsWith`
    - Pattern matching → `MatchesRegex`
    - Semantic similarity → `StringSimilarity` (embeddings)
    - Fuzzy matching → `StringDistance`

3. **Output configuration** based on:
    - Development → `ConsoleOutput` with verbose mode
    - CI/CD → `JsonOutput` to file
    - Analytics → Custom driver to database/API

4. **Evaluation granularity**:
    - Unit tests → Single assertion per evaluator
    - Integration tests → Multiple assertions
    - System tests → Multiple evaluators covering different scenarios
