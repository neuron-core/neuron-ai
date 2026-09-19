# Classifier

This module evaluates input against closed answer domains and returns probabilities. It is a standalone component that workflow nodes can consume through `ClassifierInterface`.

## Boundaries

- Keep the shared definitions, requests, and results independent of providers, HTTP, Chat, Agent, and Workflow.
- Classification has its own contract. Do not extend `AIProviderInterface` or introduce chat messages, tools, or streaming into it.
- Keep vendor authentication, models, payload mapping, transport, and service limits inside the provider directory.
- Thresholds, abstention, routing, and actions belong in application code or workflow nodes.

## Contract

`ClassifierInterface::classify(ClassificationRequest): ClassificationResult` evaluates named questions against one input. Input is text or JSON-compatible array data. Questions do not depend on other answers; batching does not imply statistical independence or concurrency.

Definitions and results are immutable values. Reuse definitions across calls; do not store request input or questions on a provider instance.

| Definition | Meaning | Result |
| --- | --- | --- |
| `Choice` | Mutually exclusive, unordered options | Full distribution and most probable option |
| `Score` | Ordered levels at positions `0..n-1` | Full distribution and expected position |
| `Boolean` | Whether a proposition is true | Probability of true |

Return exactly one answer for each question identifier, with the matching definition and result type. Preserve option and level order. Consumers use `choice($id)`, `score($id)`, and `boolean($id)` for concrete return types; missing or mismatched answers throw.

## Probability semantics

- Probabilities are required. Never invent them from labels or relabel arbitrary scores as probabilities.
- Choice and Score distributions cover every outcome, including zero-probability outcomes. Values are finite, in `[0, 1]`, and sum to one within `1e-6`; `ProbabilityDistribution` normalizes accepted rounding error.
- Choice ties follow definition order, regardless of response order.
- Score is the probability-weighted mean of equally spaced level indices. Preserve the distribution: different distributions can have the same mean.
- Boolean returns the probability of true without applying a threshold. Multiple Boolean questions can represent multilabel classification; their probabilities need not sum to one.
- Provider confidence statistics are distinct from probabilities. The contract does not guarantee calibration or accuracy.

## Providers

Implement `ClassifierInterface` and translate vendor data into the shared result classes. Reuse their validation and derived values. Fail explicitly when a provider cannot honor a definition.

Use `HasHttpClient` with `CurlHttpClient` by default. Attach authentication and endpoint information to requests so replacing the client preserves provider behavior. Keep retries in the HTTP client.

TypeSafeAI maps `Boolean` to `noul`. Its option limits stay in the adapter. Encode its request body explicitly as JSON: structured input can contain a `contents` field that HTTP clients might otherwise interpret as multipart data.

Use `InvalidArgumentException` for invalid caller input, `ProviderException` for malformed provider responses, and preserve `HttpException` for HTTP or network failures. Include question identifiers in response errors where applicable.

## Verification

Keep tests under `tests/Classifier/`. Test provider requests and responses through `GuzzleHttpClient` with `MockHandler`; routine tests must not require credentials or live services. Cover payload mapping, result semantics, malformed responses, provider limits, and state isolation between calls.

Run only the affected checks:

```bash
php vendor/bin/phpunit tests/Classifier
php vendor/bin/phpstan analyse src/Classifier tests/Classifier --memory-limit=1G
```

See [README.md](README.md) for public usage examples. Keep the API small and examples easy to follow; do not add wrappers or interfaces without a concrete responsibility.
