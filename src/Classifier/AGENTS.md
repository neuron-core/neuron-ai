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

Return exactly one answer for each question identifier, with the matching definition and result type, or a `Refusal` when the provider declines that question. Never drop, retry, or replace a refused question; providers that cannot refuse never produce one. Preserve option and level order. Consumers use `choice($id)`, `score($id)`, and `boolean($id)` for concrete return types; missing, mismatched, or refused answers throw `InvalidArgumentException`, and `isRefused($id)` is the guard.

A Choice option description is a non-empty string or `null`, meaning the option is judged by its identifier alone.

## Probability semantics

- Probabilities are required. Never invent them from labels or relabel arbitrary scores as probabilities.
- Choice and Score distributions cover every outcome, including zero-probability outcomes. Values are finite, in `[0, 1]`, and sum to one within `1e-3`, which absorbs single-precision softmax drift; `ProbabilityDistribution` normalizes accepted rounding error.
- Choice ties follow definition order, regardless of response order.
- Score is the probability-weighted mean of equally spaced level indices. Preserve the distribution: different distributions can have the same mean.
- Boolean returns the probability of true without applying a threshold. Multiple Boolean questions can represent multilabel classification; their probabilities need not sum to one.
- Provider confidence statistics are distinct from probabilities. The contract does not guarantee calibration or accuracy.

## Providers

Implement `ClassifierInterface` and translate vendor data into the shared result classes. Reuse their validation and derived values. Fail explicitly when a provider cannot honor a definition.

Use `HasHttpClient` with `CurlHttpClient` by default. Attach authentication and endpoint information to requests so replacing the client preserves provider behavior. Keep retries in the HTTP client.

System One providers share `SystemOne\SystemOne`, which maps `Boolean` to `noul` and checks the protocol limits (255 options, 10 levels) while mapping questions. `TypeSafeAI\TypeSafeAI` and `Cloudflare\WorkersAI` are host subclasses: they set the base URI and override only what their host changes, such as `endpoint()`, `unwrap()` for the Workers AI `result` envelope, `name()` for messages, or `classify()` to check host limits before calling the parent. Self-hosted servers use `SystemOne` directly.

`OpenAI\OpenAIDecisions` implements the Decisions protocol on its own: questions become a list named by their identifiers, `Boolean` maps to `predicate`, Score levels are sent as labels, array input is sent as one `input_json` part and must be an object or a list of objects, and answers are matched by position and echoed name. A `refusal` answer becomes a `Refusal`.

Check documented countable limits (questions, options, levels, identifier rules, input shape) before sending; leave byte, value-count, token, and nesting limits to the service. Ignore vendor `choice`, `score`, `confidence`, `legend`, and `label` fields: Neuron derives its values from the probabilities.

Use `InvalidArgumentException` for invalid caller input, `ProviderException` for malformed provider responses, and preserve `HttpException` for HTTP or network failures. Include question identifiers in response errors where applicable.

## Verification

Keep tests under `tests/Classifier/`. Test provider requests and responses through `GuzzleHttpClient` with `MockHandler`; routine tests must not require credentials or live services. Cover payload mapping, result semantics, malformed responses, provider limits, and state isolation between calls.

Run only the affected checks:

```bash
php vendor/bin/phpunit tests/Classifier
php vendor/bin/phpstan analyse src/Classifier tests/Classifier --memory-limit=1G
```

See [README.md](README.md) for public usage examples. Keep the API small and examples easy to follow; do not add wrappers or interfaces without a concrete responsibility.
