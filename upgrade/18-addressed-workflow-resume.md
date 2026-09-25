# Upgrade: Workflow continuation answers one interruption

## What changed

A workflow exposes one current interruption, including workflows with parallel
branches. Callers provide its response as a plain array. Framework input types,
interruption-ID maps and per-input dispositions are no longer public control APIs.

```php
$state = $workflow->run();
$request = $state->getInterruptRequest();
$state = $workflow->resume(['approved' => true])->run();
```

`run()` and `events()` are argument-free execution terminals. `resume()`,
`signal()` and `submitInputs()` stage a continuation consumed by the next terminal.
Only one operation can be staged at a time. Without a staged operation, a terminal
starts a run or recovers a persisted failed execution. Agent's `chat()`, `stream()`
and `structured()` explicitly start a new turn.

## Choose the continuation

```php
// Answer the current request.
$state = $workflow->resume($payload)->run();

// Answer the current request, requiring this event name to match.
$state = $workflow->signal('order.approved', $payload)->run();

// Recover without an answer, evaluate a due deadline, or replay retained completion.
$state = $workflow->resume()->run();
```

`resume([])` supplies an empty answer. `resume()` supplies no answer. An unanswered
request stays suspended until an answer arrives or its deadline becomes due.
A signal targets only the current request and throws on a name mismatch. Signals
are not buffered for future or deferred requests.

These operations also support streaming: replace the final `run()` with `events()`.
The generator returns the final state through `getReturn()`.

## Agent tool responses

Use `submitApprovalDecisions($decisions)` for native approval maps and
`submitToolResults($results)` for native deferred tool outcomes, including those
returned by a custom frontend. Finish with `run()` or `events()`. Replace direct
`resume()`/`signal()` calls and `submitInputs(..., new ApprovalTranslator())` or
`submitInputs(..., new ToolResultsTranslator())` used for these native maps.
The Agent methods hide the event names, request matching and native translators.
Keep protocol translators for raw AG-UI or Vercel envelopes.

## Replace addressed responses and internal input types

Replace `resume([$request->getId() => $payload])` or a batch of `ResumeInput`
objects with `resume($payload)`. Do not manufacture timer or expiration inputs:
use inputless `resume()` and let Workflow validate the persisted deadline.

Replace `getInterruptRequests()` with `getInterruptRequest()`. This returns one
request or null. Remove per-input `getInputResults()` handling; invalid replies
throw before acceptance. A continuation may return the next interruption.

Request IDs remain useful for frontend correlation. They are not response-map
keys. Tool-call IDs inside approval or tool-result payloads remain domain keys. Approval
values are `approve`, `reject`, or `['reject', $reason]`; each result entry contains
exactly one JSON-compatible `result` or string `error`:

```php
$agent->submitApprovalDecisions(['call_123' => 'approve'])->run();
$agent->submitToolResults(['call_123' => ['result' => 'Page title']])->run();
```

## Translators and stream adapters

A translator receives one authoritative persisted request and returns its response:

```php
public function translate(array $payload, InterruptRequest $request): array;
```

Use `$workflow->submitInputs($payload, $translator)->run()` or `events()`.
Submission reads and translates without executing nodes or writing persistence,
then stages the response with the observed run ID and execution attempt. If another
continuation advances the run before execution, the staged response is rejected.
An empty response is valid; translators reject malformed or unmatched transport data.

Custom stream adapters implement `interrupt(InterruptRequest $request): iterable`.
The terminal `InterruptEvent` carries `$request` and is not passed to `transform()`.
One approval request can still contain multiple tool actions; protocol adapters
may encode those actions individually. `RunInFlightException` exposes `$interrupt`.

## Parallel interruption order

The default branch runner stops at the first interruption. `AsyncBranchRunner` stops starting
new nodes and drains nodes already running, including streamed output, until each
returns or interrupts. Their completed steps and memoized work remain durable.
Concurrent requests are stored on their branch steps and exposed in arrival order.
The current request blocks deferred requests, including their deadlines. A deferred
request retains its original deadline and can expire once it becomes current.

Answering the current request can expose another request; handle that result as a
new suspension. No live fibers are retained across invocations. Completed nodes
are replayed from persistence. Operations within a resumed node should use
`memoize()` when their completed work must survive replay.

## Durable delivery and retries

Keep delivery identity outside domain payloads. A worker that may be retried should
retain the observed run ID and execution attempt and pass both fences:

```php
$state = $workflow->resume(
    $payload,
    expectedRunId: $runId,
    expectedExecutionAttempt: $attempt,
)->run();
```

Use the same fences for inputless timer jobs. A stale fence throws before mutation;
do not retry by matching a signal name against a later request. Once accepted,
a response is immutable until its node settles. Identical redelivery may recover
a failed attempt; a conflicting answer is rejected.

A platform owns scheduling, subscriptions, HTTP responses and delivery receipts.
It reconstructs the workflow and its dependencies, configures persistence, and
reconciles the current returned request. The core has no scheduler interface.
Use `retainCompletionUntilAcknowledged()` when completion must survive a lost
response. Replay completion through `resume()->run()` and release its records
with `acknowledge($runId)`.

## What to search for

Search application source and tests, excluding dependencies, for:

- `ResumeInput`, `ResumeType`, `ResumeInputResult`, `ResumeInputStatus`.
- Interruption-ID maps passed to `resume()`.
- `getInterruptRequests()`, `getInputResults()`, `->interrupts`, `->requests`.
- Translator signatures taking request arrays.
- Signal handlers assuming broadcast or allowing a later request to bypass the current one.
- Calls to `run(...)` or `events(...)` with continuation arguments.
