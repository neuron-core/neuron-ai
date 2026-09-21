# Classifier

`NeuronAI\Classifier` defines provider-independent probabilistic judgments over closed answer domains. It has no dependency on chat, agents, HTTP, or workflows.

| Definition | Domain | Result |
| --- | --- | --- |
| `Choice` | Mutually exclusive named options | `ChoiceResult`: most probable option and full distribution |
| `Score` | Ordered, described levels | `ScoreResult`: expected level position and full distribution |
| `Boolean` | A true/false proposition | `BooleanResult`: probability of true |

## Calling a classifier

Start with `TypeSafeAI`, the first implementation of `ClassifierInterface`. Pass your API key; the model defaults to `jev-latest`.

```php
use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\Choice;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Classifier\Score;
use NeuronAI\Classifier\TypeSafeAI\TypeSafeAI;

$classifier = new TypeSafeAI(key: getenv('TYPESAFE_API_KEY') ?: '');

$request = new ClassificationRequest(
    input: ['ticket' => 'My package has not arrived. Please refund me.'],
    questions: [
        'department' => new Choice(
            instructions: 'Which department should handle this ticket?',
            options: [
                'billing' => 'Charges and payment problems.',
                'shipping' => 'Delivery and tracking problems.',
                'other' => 'Requests outside these departments.',
            ],
        ),
        'severity' => new Score(
            instructions: 'How severe is the reported problem?',
            levels: ['Cosmetic issue.', 'Workaround available.', 'Completely blocked.'],
        ),
        'refund' => new Boolean('Does the customer request a refund?'),
    ],
);

$result = $classifier->classify($request);
$department = $result->choice('department'); // ChoiceResult
$severity = $result->score('severity');      // ScoreResult
$refund = $result->boolean('refund');        // BooleanResult

$selected = $department->choice;
$probability = $department->distribution->probabilities[$selected];
```

The typed accessors return the corresponding result object and throw `InvalidArgumentException` if the identifier is missing or the answer belongs to another primitive. The public `answers` map remains available for iteration.

Input accepts text or JSON-compatible arrays containing only arrays, scalars, and null. Question and option identifiers must be non-empty strings; PHP converts integer-like array keys to integers, so identifiers such as `"0"` are not supported. Definitions require non-empty instructions and descriptions. Choice and Score require at least two outcomes.

## TypeSafeAI

The provider sends all questions in one authenticated call to [TypeSafe's evaluation endpoint](https://docs.typesafe.ai/api). Neuron's `Boolean` maps to the API's `noul` primitive; application code continues to use the same typed result accessors. Text, associative arrays, and lists are sent as JSON state.

Set a model explicitly when you want to select a particular version:

```php
$classifier = new TypeSafeAI(key: $apiKey, model: 'jev-latest');
```

HTTP uses Neuron's default `CurlHttpClient` and needs no vendor SDK. Inject another `HttpClientInterface` through the constructor or `setHttpClient()` to configure timeouts, test requests, or use an existing retry policy:

```php
use NeuronAI\HttpClient\Curl\CurlHttpClient;

$classifier = new TypeSafeAI(
    key: $apiKey,
    httpClient: (new CurlHttpClient())->withTimeout(30),
);
```

The provider checks TypeSafe's limits of 255 Choice options and 10 Score levels before sending. These limits belong to this provider; the shared definitions remain unrestricted by vendor maxima. Empty credentials, empty model names, and unsupported question sizes raise `InvalidArgumentException` locally. Malformed responses raise `NeuronAI\Exceptions\ProviderException` with question context where available. Network failures and HTTP errors raise `NeuronAI\Exceptions\HttpException`, retaining the request and any response, including rate-limit headers. Retry policy belongs in the HTTP client; the classifier does not automatically repeat requests.

Choice winners and expected scores are derived from the returned probabilities using Neuron's shared semantics, including deterministic tie-breaking. The API's confidence statistic is not substituted for probability.

## Testing applications

Use `NeuronAI\Testing\FakeClassifier` wherever your application expects `ClassifierInterface`. Supply a list of answer maps, one per call. Choice and Score answers are complete probability distributions; Boolean answers are numeric probabilities.

```php
use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Testing\FakeClassifier;

$classifier = new FakeClassifier([
    ['refund' => 0.95],
    ['refund' => 0.15],
]);

$request = new ClassificationRequest(
    input: 'Please refund my order.',
    questions: ['refund' => new Boolean('Is a refund requested?')],
);

$result = $classifier->classify($request);
$result->boolean('refund')->probability; // 0.95; the next call returns 0.15.

$classifier->assertCallCount(1);
$classifier->assertSent(
    fn (ClassificationRequest $sent): bool => $sent->input === 'Please refund my order.',
);
```

For mixed questions, an answer map can contain `['department' => ['billing' => 0.8, 'shipping' => 0.2], 'severity' => [0.1, 0.3, 0.6], 'refund' => 0.95]`. Each identifier and distribution must match the request. The fake constructs the real result objects and applies their validation, tie-breaking, and score calculation.

`getRecorded()` returns requests in call order; `getCallCount()` includes failed attempts. `assertNothingSent()` verifies an unused fake. Invalid queued answers and queue exhaustion throw `ProviderException`. Each attempted call consumes one available response, even if that response is invalid. The fake never repeats the final response automatically.

## Evaluation judge

Use `ClassifierJudge` in an evaluator's `evaluate()` method to judge output with a classifier:

```php
use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\Score;
use NeuronAI\Evaluation\Assertions\ClassifierJudge;

$this->assert(new ClassifierJudge(
    classifier: $classifier,
    criteria: new Score(
        instructions: 'How correctly does actual answer the question in reference?',
        levels: [
            'Incorrect or unrelated answer.',
            'Partially correct, with significant omissions or errors.',
            'Fully correct and complete answer.',
        ],
    ),
    threshold: 0.8,
    reference: $item['question'],
), $output, 'correctness');

$this->assert(new ClassifierJudge(
    classifier: $classifier,
    criteria: new Boolean('Does actual politely decline the request?'),
    threshold: 0.9,
), $output, 'polite_refusal');
```

`ClassifierJudge` accepts a string or an evaluation `Trajectory`, rendered with `toTranscript()`. Each evaluation sends one question named `judgment`, with input containing `actual` and an optional `reference`. Use the reference for an expected answer, the original question, or supporting evidence, and describe its role in the criterion.

Boolean assertions record the probability of true. Score assertions record the expected level position divided by `count(levels) - 1`, so higher levels must mean better performance. Both pass when the value meets or exceeds the threshold (default `0.7`). These are different metrics: a Boolean probability of `0.8` does not mean 80% completion. Choose thresholds for your rubric and dataset.

The assertion context retains the criterion, reference, threshold, result type, probabilities, and Score level descriptions. Messages report the value and threshold, without generated reasoning. The existing runner retains context for failed assertions; passing score records contain only the metric label, value, and verdict. Invalid input and provider failures are reported as evaluation errors, not failed judgments.

## Implementing the contract

Implement `ClassifierInterface::classify(ClassificationRequest): ClassificationResult`. Evaluate each question against the same input without depending on another answer. Providers may batch questions or execute them separately; the interface promises neither concurrency nor statistical independence.

Construct each answer with its question definition and probabilities:

```php
use NeuronAI\Classifier\ChoiceResult;

$answer = new ChoiceResult(
    definition: $request->questions['department'],
    probabilities: ['billing' => 0.1, 'shipping' => 0.8, 'other' => 0.1],
);
```

Return `new ClassificationResult($request, $answers)` with exactly one correctly typed answer per question identifier. Each answer must retain an equivalent definition, including option or level order. Definition objects can be reused across requests. Provider credentials, model selection, transport, and service limits belong in the implementation. An implementation that cannot honor a definition must fail explicitly rather than change its meaning.

## Probability semantics

- Choice and Score distributions contain every defined outcome, including zero-probability outcomes. All values must be finite numbers in `[0, 1]` and sum to one within `1e-6`. Accepted rounding error is normalized away. Invalid definitions, input, or results throw `InvalidArgumentException`.
- Choice selects the most probable option. Ties follow the definition's option order, irrespective of response order.
- Score assigns equally spaced positions `0` through `count(levels) - 1`. Its value is the probability-weighted mean of those positions. This numeric convention does not assert that the underlying real-world categories have equal distances. Different distributions can have the same mean, so results retain the full distribution.
- Boolean returns the probability of true; the probability of false is its complement. No threshold is applied.

These contracts do not guarantee calibration or predictive accuracy. They do not equate a provider's confidence statistic with probability. Thresholds, abstention, routing, and actions belong in application code. An explicit `other` choice is a category, not an abstention signal.

For multilabel classification, ask one Boolean question per label. Membership probabilities across labels need not sum to one. Workflow nodes can consume `ClassifierInterface` directly and translate results into domain events.
