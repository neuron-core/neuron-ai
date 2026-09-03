---
name: neuron-evaluation
description: Create and run AI evaluations with datasets, assertions, and output drivers in Neuron AI. Use this skill whenever the user mentions evaluation, testing AI systems, creating evaluators, dataset-driven testing, assertion-based validation, or wants to measure AI system performance. Also trigger for tasks involving evaluator discovery, output configuration, result analysis, building custom assertions, multi-turn conversation evaluation, agent trajectory testing, tool-call assertions, human-in-the-loop (approval flow) testing, simulated user conversations, or connecting an evaluation suite to the Neuron Cloud platform.
---

# Neuron AI Evaluation

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
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;

$this->assert(new StringSimilarity(
    reference: 'The quick brown fox',
    embeddingsProvider: new OpenAIEmbeddingsProvider(key: 'YOUR_KEY', model: 'text-embedding-3-small'),
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
Use an AI agent to evaluate outputs with custom criteria. Judges accept a `string` **or a
`Trajectory`** (see Multi-Turn Conversation Evaluation below) — a Trajectory is rendered
into the judge prompt as the full conversation transcript:

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

// Task completion - did the assistant accomplish the user's goal over a whole
// conversation? Feed it a Trajectory (see Multi-Turn Conversation Evaluation).
use NeuronAI\Evaluation\Assertions\Judges\TaskCompletionJudge;

$this->assert(new TaskCompletionJudge(
    judge: $judge,
    goal: $datasetItem['goal'],
    threshold: 0.7
), $trajectory);
```

### Creating Custom Assertions

The type contract: `evaluate(mixed $actual)` stays `mixed` (the interface supports different
input types per assertion family), but a wrong input type is a **coding error in the
evaluator** — throw `InvalidArgumentException` (the runner records it as an item error).
An assertion *failure* is reserved for facts about the agent's output. For string-based
assertions, extend `StringAssertion` (implement `evaluateString(string $actual)`) and the
type is enforced for you; for trajectory-based ones extend `TrajectoryAssertion`.

```php
use NeuronAI\Evaluation\Assertions\AbstractAssertion;
use NeuronAI\Evaluation\AssertionResult;

class GreaterThanAssertion extends AbstractAssertion
{
    public function __construct(
        protected float $threshold
    ) {}

    public function evaluate(mixed $actual): AssertionResult
    {
        if (!is_numeric($actual)) {
            throw new \InvalidArgumentException(
                static::class . ' evaluates a number, got ' . get_debug_type($actual)
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

## Multi-Turn Conversation Evaluation

For agentic systems the unit under test is not a single response but a whole conversation:
tool calls, human approval decisions, and the final outcome. One sentence anchors the model:
**you run a Conversation; you evaluate its Trajectory.**

### Conversation — driving the agent

`Conversation` drives an agent through a multi-turn exchange inside `run()` and returns a
`Trajectory` to assert against:

```php
use NeuronAI\Evaluation\Conversation\Conversation;
use NeuronAI\Evaluation\Trajectory\Trajectory;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

public function run(array $datasetItem): mixed
{
    return Conversation::make($this->makeAgent())
        ->withTurns($datasetItem['turns'])        // list of strings (or UserMessage objects)
        ->run();                                  // : Trajectory
}
```

Turns are delivered in order; each one is sent only after the previous turn fully completed.

### Human-in-the-loop: the approval policy

If the agent under test gates tools behind approval (tools declaring an `approvalPolicy()`,
or attach-time `requireApproval()` overrides), `chat()` suspends mid-turn and a human must
decide. In an evaluation there is no human — `withApprovals()` scripts the
approver. The callable is invoked whenever the agent suspends, at any point in the
conversation (that's why it is not an entry in the turns script — you can't know in advance
*when* the model will call the gated tool):

```php
use NeuronAI\Workflow\Interrupt\ApprovalRequest;

Conversation::make($agent)
    ->withTurns(['I want a refund for order #123', 'Yes, do it.'])
    ->withApprovals(function (ApprovalRequest $request, Trajectory $soFar): array {
        $payload = [];
        foreach ($request->getActions() as $action) {
            if ($action->isPending()) {
                // Argument-dependent decisions: read the args from the trajectory
                // tail at policy time (read values — the entries are live objects).
                $args = $soFar->lastToolCall($action->name)?->getInputs();

                $payload[$action->id] = ($args['amount'] ?? 0) > 100
                    ? ['reject', 'above the auto-approve threshold']
                    : 'approve';
            }
        }
        return $payload;   // complete decision set, keyed by callId
    })
    ->run();
```

Fail-loud rules (each throws `EvaluationException`, recorded as an item error):
- a suspension occurs and no policy is configured — silence is never consent;
- the returned payload misses a pending action id (an incomplete set would re-suspend and
  loop the runner).

The parameter can be typed as the generic `InterruptRequest` to handle custom suspensions,
narrowing with `instanceof`.

### Simulated users

Instead of a script, let an agent play the user — persona + goal, generating each next
message from the conversation so far and deciding itself when to stop (goal satisfied or
giving up):

```php
use NeuronAI\Evaluation\Conversation\UserSimulator;

$simulator = UserSimulator::make()
    ->withPersona('An impatient customer who gives short answers')
    ->withGoal('Get a refund for order 123');
$simulator->setAiProvider($provider);   // set the provider on a separate statement

$trajectory = Conversation::make($agent)
    ->withUser($simulator, maxTurns: 10)   // hard cap required — no infinite default
    ->withApprovals($policy)               // approvals STAY with the policy: the user
    ->run();                               // and the approver are different humans
```

`withTurns()` and `withUser()` are mutually exclusive. Hitting `maxTurns` ends the
conversation normally — whether an unfinished conversation is a failure is the assertions'
judgment (use `TaskCompletionJudge`), not the runner's.

### Trajectory — the record you assert against

A read-only view over the conversation's messages. Key accessors:

```php
$trajectory->toolCalls('refund_order');  // ToolCall[] — one entry per call, with
                                         // final results and approval state merged in
$trajectory->lastToolCall();             // ?ToolCall
$trajectory->finalAnswer();              // string — last assistant message ('' if none)
$trajectory->userMessages();             // string[]
$trajectory->usage();                    // Usage — aggregate token usage (cost checks)
$trajectory->toTranscript();             // human-readable transcript (what judges see)
$trajectory->messages();                 // the raw Message[] — full fidelity
```

You don't need the Conversation runner to get one — any hand-rolled multi-turn loop can
project its chat history: `Trajectory::fromChatHistory($agent->getChatHistory())`.

### Trajectory assertions

```php
use NeuronAI\Evaluation\Assertions\Trajectory\{
    Mode, ToolWasCalled, ToolWasNotCalled, TrajectoryMatches, ToolWasApproved, ToolWasRejected
};

public function evaluate(mixed $trajectory, array $datasetItem): void
{
    // The workhorse — optional argument constraint (subset match or callable)
    $this->assert(new ToolWasCalled('search_orders', ['customer' => $datasetItem['customer']]), $trajectory);
    $this->assert(new ToolWasCalled('refund_order', fn (array $args): bool => $args['amount'] <= 100), $trajectory);

    // Guardrails
    $this->assert(new ToolWasNotCalled('delete_account'), $trajectory);

    // Sequence matching (names only), four modes:
    //   Strict    — exact sequence, nothing else
    //   Unordered — same calls, any order
    //   Subset    — expected appears in order, extras allowed
    //   Superset  — no call outside the expected set
    $this->assert(new TrajectoryMatches($datasetItem['expected_tools'], Mode::Subset), $trajectory);

    // HITL outcomes (approval state recorded in chat history)
    $this->assert(new ToolWasRejected('refund_order'), $trajectory);

    // Final answer: reuse the string catalog and judges on finalAnswer()
    $this->assert(new StringContains('cannot process'), $trajectory->finalAnswer());

    // Conversation-level judge: goal + whole transcript
    $this->assert(new TaskCompletionJudge($this->judge, goal: $datasetItem['goal']), $trajectory);
}
```

### Complete example: refund conversation with rejection

```php
class RefundConversationEvaluator extends BaseEvaluator
{
    public function getDataset(): DatasetInterface
    {
        return new JsonDataset(__DIR__ . '/datasets/refunds.json');
    }

    public function run(array $datasetItem): mixed
    {
        return Conversation::make($this->makeAgent())
            ->withTurns($datasetItem['turns'])
            ->withApprovals(fn (ApprovalRequest $request, Trajectory $soFar): array =>
                array_reduce($request->getActions(), function (array $payload, $action) use ($datasetItem) {
                    $payload[$action->id] = $datasetItem['decisions'][$action->name] ?? 'approve';
                    return $payload;
                }, [])
            )
            ->run();
    }

    public function evaluate(mixed $trajectory, array $datasetItem): void
    {
        $this->assert(new TrajectoryMatches($datasetItem['expected_tools'], Mode::Subset), $trajectory);
        $this->assert(new ToolWasRejected('refund_order'), $trajectory);
        $this->assert(new StringContains('cannot'), $trajectory->finalAnswer());
        $this->assert(new TaskCompletionJudge($this->judge, goal: $datasetItem['goal']), $trajectory);
    }
}
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

# Run dataset items in parallel processes
vendor/bin/neuron evaluation /path/to/evaluators --concurrency=4

# Serve unchanged run() outputs from the evaluation cache (assertions always re-run)
vendor/bin/neuron evaluation /path/to/evaluators --cache

# Re-run everything and overwrite the evaluation cache
vendor/bin/neuron evaluation /path/to/evaluators --fresh

# Load a custom bootstrap file before the default Composer autoloader
vendor/bin/neuron evaluation /path/to/evaluators --autoload-file=bootstrap.php

# Help
vendor/bin/neuron evaluation --help
```

**`--concurrency=N`** runs each evaluator's dataset items in N parallel processes.
Requires the `pcntl` extension and `spatie/fork`; if unavailable, the runner prints a
notice and falls back to sequential execution. The same option exists
programmatically: `$runner->run($evaluator, concurrency: 4)`.

**`--autoload-file=<path>`** (also `--autoload-file <path>`) loads a custom bootstrap
file *before* (in addition to) the default Composer autoloader. It's a global
`vendor/bin/neuron` option, so it works with any command. Use it when evaluators need
framework bootstrapping (e.g. a Laravel/Symfony bootstrap that sets up the DI
container) or an autoloader not covered by the project's `composer.json`.

### Run Output Caching (`--cache`)

The cache stores the **output of `run()`, never the verdict** — `evaluate()` always
executes fresh. An unchanged dataset item skips the expensive agent run (LLM calls,
tool execution, whole conversations), while assertions, thresholds, and judges still
run against the recorded output. That makes it both a cost saver in CI and a
development workflow: iterate on `evaluate()` against frozen outputs without
re-running agents.

A cached run is skipped because re-running unchanged inputs yields no new information
about *your change* — it only samples the same distribution again. It tells you nothing
about provider drift either, so pair `--cache` in CI with a periodic `--fresh` run.

**What invalidates an entry** (the key is a content fingerprint):

- editing the evaluator's `run()` method — and only `run()`: changing `evaluate()`
  keeps cached runs valid on purpose;
- any content change in the declared cache dependencies (see below);
- the dataset item itself (content-addressed: reordering a dataset invalidates
  nothing; appended items simply miss);
- upgrading the framework.

**Declare what `run()` depends on.** The fingerprint can't see through `run()` into
your agent class or prompt files — declare them by overriding `cacheDependencies()`:

```php
class RefundEvaluator extends BaseEvaluator
{
    public function cacheDependencies(): array
    {
        return [
            RefundAgent::class,               // class-string → its source file is hashed
            __DIR__ . '/prompts/refund.txt',  // or a file path
        ];
    }

    // ...
}
```

Undeclared dependencies (e.g. a prompt loaded from a database) are invisible to
invalidation — run `--fresh` after changing them.

Entries live in `.neuron/cache/evaluation/` (gitignore it; override the path via the
config file — see Output Configuration). Cached items are visible everywhere: per
result (`EvaluatorResult::isCachedRun()`, `cached_run` in JSON output) and in the
console summary (`Cached runs: N of M (assertions re-evaluated)`), so a skip is never
confusable with a fresh run. A non-serializable `run()` output (same contract as
`--concurrency`'s fork boundary) is silently not cached.

Programmatically, pass the cache to the runner:

```php
use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;

$runner = new EvaluatorRunner(new FileEvaluationCache('.neuron/cache/evaluation'));
$summary = $runner->run(new MyEvaluator());

$summary->getCachedRunCount();   // how many items skipped run()

// refresh: true bypasses cache reads but still records outputs (--fresh)
$runner = new EvaluatorRunner(new FileEvaluationCache($path), refresh: true);
```

`EvaluationCacheInterface` (`has`/`get`/`set`) is the storage seam —
implement it to back the cache with Redis, a database, or a shared team store.
To wipe the cache entirely, delete the cache directory.

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

Create `evaluation.php` in project root. Each `output` entry is either:

- a **class string** of a zero-argument driver (resolved as `new $class()`), or
- a **fully-constructed driver instance** (required for any driver that needs constructor arguments).

Drivers that need dependencies (a DB connection, an HTTP client, options like a
file path) **must** be supplied as concrete instances. This is what lets a host
framework (Laravel, Symfony) build them through its DI container — pass the
resolved instance into the config.

```php
<?php

use NeuronAI\Evaluation\Output\ConsoleOutput;
use NeuronAI\Evaluation\Output\JsonOutput;

return [
    'output' => [
        // Zero-argument driver
        ConsoleOutput::class,

        // Constructed instance (e.g. to set the output path)
        new JsonOutput('evaluation-results.json'),
    ],

    // Optional: where --cache stores run() outputs (default: .neuron/cache/evaluation)
    'cache' => [
        'path' => '.neuron/cache/evaluation',
    ],
];
```

**Default behavior**: If no config exists, uses `ConsoleOutput`.

### Built-in Output Drivers

#### ConsoleOutput

```php
// Zero-argument (no verbose detail)
ConsoleOutput::class

// With verbose mode — pass an instance
new ConsoleOutput(true)
```

- `verbose` (bool, default `false`) - Show detailed input/output for failures

#### JsonOutput

```php
// Write to file — pass an instance with the path
new JsonOutput('results.json')

// Write to stdout (no path)
JsonOutput::class
```

### Creating Custom Output Drivers

```php
use NeuronAI\Evaluation\Contracts\EvaluationOutputInterface;
use NeuronAI\Evaluation\Runner\EvaluationSuiteSummary;

class DatabaseOutput implements EvaluationOutputInterface
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $table = 'evaluations'
    ) {}

    public function output(EvaluationSuiteSummary $suite): void
    {
        $summary = $suite->getAggregateSummary();

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

Register the constructed instance (resolve dependencies from your DI container):
```php
return [
    'output' => [
        new DatabaseOutput($container->get(\PDO::class), 'evaluations'),
    ],
];
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
$summary->getResultsByEvaluatorClass(); // array<string, EvaluatorResult[]> grouped by the evaluator that produced them
$summary->getCachedRunCount();          // int — items whose run() came from the cache

// Assertion failures grouped by location
$summary->getAssertionFailuresByLocation();  // array<string, AssertionFailure[]>
```

### EvaluatorResult

```php
foreach ($summary->getResults() as $result) {
    $result->getEvaluatorClass();      // string, the evaluator that produced this result
    $result->getShortEvaluatorClass(); // string
    $result->getIndex();              // int
    $result->isPassed();             // bool
    $result->getInput();             // array
    $result->getOutput();            // mixed
    $result->getExecutionTime();      // float
    $result->isCachedRun();          // bool — run() was served from the evaluation cache
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
vendor/bin/neuron make:evaluators MyEvaluator
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

### Caching in CI

Persist `.neuron/cache/evaluation/` across CI runs (e.g. `actions/cache`) and run with
`--cache`: only evaluators whose `run()`, declared dependencies, or dataset items
changed re-execute against the provider. Schedule a periodic `--fresh` run (e.g.
nightly) — cache hits carry no information about provider drift, so drift detection
should be time-based, not commit-based.

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
    - Tool calls / HITL / call sequences → trajectory assertions (`ToolWasCalled`,
      `TrajectoryMatches`, `ToolWasApproved`/`ToolWasRejected`) on a `Trajectory`
    - Whole-conversation quality → `TaskCompletionJudge` (and other judges) on a `Trajectory`

3. **Output configuration** based on:
    - Development → `ConsoleOutput` with verbose mode
    - CI/CD → `JsonOutput` to file
    - Analytics → Custom driver to database/API

4. **Evaluation granularity**:
    - Unit tests → Single assertion per evaluator
    - Integration tests → Multiple assertions
    - System tests → Multiple evaluators covering different scenarios

## Connecting to Neuron Cloud (Optional)

**This integration is completely optional.** Everything documented above runs
entirely locally — datasets from arrays or JSON files, results to console or
JSON. Only suggest Neuron Cloud when the user explicitly asks to connect their
evaluation suite to the platform (hosted datasets, cross-run aggregation,
sharing results with a team). Never treat it as a required setup step.

Neuron Cloud adds two things to an evaluation suite:

1. **Hosted datasets** — dataset items managed on the platform, consumed by
   evaluators like any other `DatasetInterface`.
2. **Hosted results** — evaluation runs (summary, per-item results, labeled
   scores, failures, multi-turn trajectories) shipped to the platform for
   cross-run aggregation and comparison over time.

### Installation

The SDK is available on Packagist:

```bash
composer require neuron-core/cloud-sdk
```

Laravel and Symfony applications can use `neuron-core/neuron-cloud-laravel` or
`neuron-core/neuron-cloud-symfony` instead, which register the configured SDK
root in the framework container — see the **neuron-monitoring** skill for the
per-framework setup details.

### Setup

Everything hangs off one configured root:

```php
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Http\GuzzleTransport;

$cloud = new NeuronCloud(
    transport: GuzzleTransport::discover(),
    platformUrl: 'https://cloud.neuron-ai.dev',
    apiKey: $_ENV['NEURON_CLOUD_API_KEY'],
    signingKey: $_ENV['NEURON_CLOUD_SIGNING_KEY'],
);
```

### Using a Platform Dataset

`$cloud->dataset(slug)` returns a regular `DatasetInterface` — return it from
`getDataset()` in place of an `ArrayDataset` or `JsonDataset`:

```php
public function getDataset(): DatasetInterface
{
    return $cloud->dataset('customer-support-eval');
}
```

### Shipping Results to the Platform

`$cloud->evaluationOutput()` builds an output driver for the platform's
evaluation endpoint. Register it as a **constructed instance** in
`evaluation.php` (it needs the platform credentials, so it cannot be listed as
a class string):

```php
return [
    'output' => [
        ConsoleOutput::class,
        $cloud->evaluationOutput(
            'SupportEvaluator',                 // run name shown on the platform
            dataset: 'customer-support-eval',   // optional: link to a platform dataset
            environment: 'ci',                  // optional: label the environment
        ),
    ],
];
```

It composes with the local drivers — keep `ConsoleOutput` alongside it. A
failed upload is logged and never crashes the evaluation run, and `Trajectory`
outputs are serialized as transcripts with tool calls and token usage.
