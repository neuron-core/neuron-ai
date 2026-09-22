# Workflow operation idempotency

Pass an optional `idempotencyKey` on an `ExecutionRequest` to identify one logical start or continuation. The caller creates the key once and reuses it for retries; a new operation gets a new key. Keys are scoped to the workflow ID and its stored run. Supply the same explicit or declared workflow ID and persistence backend on every retry.

```php
use NeuronAI\Workflow\Executor\ExecutionRequest;

$state = $workflow->run(ExecutionRequest::start(idempotencyKey: $requestId));
$state = $workflow->run(ExecutionRequest::resume(
    $answer,
    expectedRunId: $runId,
    expectedExecutionAttempt: $attempt,
    idempotencyKey: $answerRequestId,
));
```

`ExecutionRequest::signal($name, $payload, idempotencyKey: $key)` adds an event-name
check. Request factories perform no persistence I/O and do not mutate a workflow.
`submitInputs($payload, $translator, idempotencyKey: $key)` returns a fenced request;
pass it to `run($request)` or `events($request)`. Preserve that request for retries:
translating again inspects the current interruption, not the original one.

Agent `chat()`, `stream()`, and `structured()` accept the same optional named argument:

```php
$state = $agent->chat($messages, idempotencyKey: $requestId);
```

## Behavior while the run remains stored

- A start records the key and ignition together. A continuation records the key and accepted input together. Both use the existing atomic persistence primitives.
- A duplicate with a saved outcome returns that operation's state, even if later operations have advanced the run. Stream chunks are transient and are not replayed.
- A duplicate whose process disappeared recovers the same run, preserving accepted input and committed steps. Existing lease rules apply; configure a lease above the longest silent node operation for concurrent workers.
- A caught execution failure is saved with the operation. Its duplicate restores the failed state and throws `WorkflowException` with the recorded failure message; it does not rerun the failed node. An explicit recovery uses `run(ExecutionRequest::resume(..., idempotencyKey: $newKey))`.
- A different key does not authorize a start to take over an existing run. Continue it explicitly or abandon it according to the existing lifecycle rules. Omitting the key preserves automatic recovery on a plain unkeyed `run()`.
- Empty keys and reuse for a different operation are rejected.

The request fingerprint includes the workflow class and operation kind. Start input includes the optional reserved run ID, start event, and ignition context; continuation input includes the supplied payload, signal name, and original run/attempt fences. Factories and start hooks must reconstruct the same input on retries. Agent fingerprints preserve message content, options, and metadata except the generated `__id`. Workflow compositions with other transient start fields can override the protected `ignitionFingerprint()` hook. Configuration and live dependencies are reconstructed by the application, not compared by this fingerprint.

## Retention boundary

Operation records live in the workflow partition. They are deleted with the run on ordinary completion, explicit completion acknowledgement, or abandonment. There is no permanent key registry, additional table, or persistence-interface change.

For reliable completion reporting, enable `retainCompletionUntilAcknowledged()` before execution, send the outcome to the coordinator, and call `acknowledgeCompletion($runId)` after its durable acknowledgement. This retains both completion and operation receipts through reporting retries.

After cleanup, a duplicate start with the same key can execute again. The coordinator is responsible for stopping completed deliveries; delayed transport or queue duplicates after removal are outside the engine's guarantee. Idempotency of external side effects inside an unfinished node remains the application's responsibility.


## Reserved starts and execution resources

```php
use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\UserMessage;

$agent->setAiProvider($provider)->setChatHistory($history)->setChannel($channel);
$agent->setStreamAdapter(fn (ExecutionContext $context) =>
    new AGUIAdapter($context->workflowId, $context->runId));

$request = ExecutionRequest::start(new AgentStartEvent(
    [new UserMessage('Hello')], new AgentRunOptions(stream: true),
), runId: $reservedRunId, idempotencyKey: $requestId);
$state = $agent->run($request);
```

Reserved IDs join control, ignition and the operation receipt atomically. They use
1–128 ASCII letters, digits, underscores or hyphens, starting with a letter or digit.
A reserved/keyed start cannot replace a stored generation implicitly. Recover with
an explicit resume request. Plain `run()` retains automatic failed-run recovery.

The executor admits an operation before constructing ExecutionContext and the live
WorkflowExecution. Context contains authoritative workflow/run/attempt identity,
`startEvent()` and `domain()` detached input views. Resource factories receive this
context and return a dependency; they do not patch a Workflow. Providers, tools,
adapters and channels stay outside durable records. Construction failure after
ownership is fenced as a failure; a refused delivery cannot fail another owner.

`run($request)` always returns state, including structured output and channel delivery.
`events($request)` always returns a lazy generator; consumption also sends output to
the channel. Request input is captured at creation as serialized data. Its `event()`
and `payload()` accessors return copies. Configuration is selected at consumption;
mutating a definition while its segment is active is refused.

Saved operation outcomes, retained completion and unanswered polls are passive. They
construct no graph/resources, restore no executable tools, and replay no output.
Actual continuations reconstruct resources and restore recorded intent before traversal.

`getWorkflowId()` on a definition means its declared default only. Unbound workflows
return generated identity in state. To resume one without rebinding the definition,
pass `workflowId: $state->getWorkflowId()` on the request; inspection, input translation
and cleanup also accept an address. A request conflicting with a bound default rejects
before persistence. Translation freezes address, run and attempt for later retries.

Cleanup still requires exact generation identity. Use `acknowledgeCompletion($runId,
$workflowId)` after durable reporting, or `abandonRun($runId, $attempt, $workflowId)` for
fenced replacement, subject to lease, completion and unanswered-tool-call protections.
The SDK owns address reservation and report/finalization handshakes, not the engine.

See [the architecture](../WORKFLOW_EXECUTION_ARCHITECTURE.md) for definition/runtime
ownership, prototypes, resource lifetimes and the preserved fluent Agent API.
