# Inbound frontend translation

`AGUIInputTranslator` and `VercelAIInputTranslator` implement
`NeuronAI\Workflow\Interrupt\InputTranslatorInterface`. Its only operation is:

```php
public function translate(array $payload, array $requests): array;
```

The payload is decoded request JSON. Requests are authoritative `InterruptRequest`
objects from a returned workflow state or a persisted run snapshot. The return
value is a list of `ResumeInput` objects. Translation never executes an agent,
changes persistence, or adds messages to chat history.

## Continuing through an application endpoint

The application authenticates the caller, authorizes access to the thread, and
reconstructs the agent with its persistence, history, provider, and available tools.
Then it can use either built-in translator or its own interface implementation:

```php
use NeuronAI\Agent\Frontend\AGUIInputTranslator;

$events = $agent->submitInputs($payload, new AGUIInputTranslator())->events();
```

Use `VercelAIInputTranslator` for Vercel requests, or any implementation of
`InputTranslatorInterface`. For a non-streaming continuation, finish with `run()`.
`submitInputs()` is inherited from Workflow, so custom workflows use the same
translator contract. It reads the persisted requests and stages the translated inputs;
it does not execute nodes or write persistence. Missing runs, unmatched payloads,
and invalid translations fail before execution. Inputs are consumed by the next
terminal call. A second submission, `signal()`, or `resume()` cannot be combined
with an already staged operation. Switching the agent's chat history to a
different thread clears its staged inputs.

Configure the corresponding outbound stream adapter before consuming the generator.
The endpoint owns HTTP headers and error responses, including protocol error frames
if translation fails before streaming begins. Read the generator's returned state
to determine whether the run completed or suspended again.

Workflow retains the inspected run identity and execution attempt internally.
If another request advances the run before execution, the continuation is rejected
as stale. This protects the gap between submission and execution; it does not
identify an original browser request submitted again later. Applications managing
durable retries can retain the requests and run identity returned by a previous
execution and use the addressed `resume($inputs, ...)->run()` API for redelivery. AG-UI's
per-request `runId` is not Neuron's durable run ID.

`run()` and `events()` take no arguments. Without a staged operation they start
or automatically recover a failed execution. Agent's `chat()`, `stream()`, and
`structured()` explicitly start new turns, preserving newly supplied messages.
For durable delivery, use `resume($inputs, expectedRunId: $runId)->events()`.

## Native application inputs

The same method replaces `toolApprovalDecisions()` and `toolResults()`:

```php
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Agent\Interrupt\ToolResultsTranslator;

$state = $agent->submitInputs([
    'call_123' => 'approve',
    'call_456' => ['reject', 'Too expensive'],
], new ApprovalTranslator())->run();

// When the agent subsequently waits for the approved frontend tool's result:
$events = $agent->submitInputs([
    'call_123' => ['result' => ['title' => 'Example']],
], new ToolResultsTranslator())->events();
```

Both native translators address requests by call ID. Approval translation fills
omitted decisions from the persisted request, so successive submissions can carry
only newly decided actions. Explicit changes to an already-decided action in a
still-open batch retain the engine's latest-delivery behavior. Tool results keep
the existing cumulative validation: identical repeated results are accepted while
a batch is pending, conflicting results are rejected. Unknown call IDs fail;
results cannot bypass an approval gate.

## Supported inputs

| Translator | Input | Neuron input |
|---|---|---|
| AG-UI | `messages[]` with `role: "tool"`, `toolCallId`, string `content` | Deferred result keyed by call ID; content remains text |
| AG-UI | Tool message with string `error` | Deferred error result |
| AG-UI | Approval `resume[]`, `status: "resolved"`, `payload: {approved: boolean, reason?: string}` | Approve or reject the matching action |
| AG-UI | Approval `resume[]`, `status: "cancelled"` | Reject the matching action |
| AG-UI | Generic event `resume[]`, `status: "resolved"`, object payload | Deliver that payload to the matching wait |
| AG-UI | Deferred-batch `resume[]`, `status: "cancelled"` | Settle every still-pending call in the batch as an error |
| Vercel | Assistant `parts[]`, `tool-*` or `dynamic-tool`, `state: "output-available"` | Deferred result from `output`, including null, false, and structured values |
| Vercel | Tool part with `state: "output-error"` | Deferred error from `errorText` |
| Vercel | Tool part with `state: "approval-responded"` | Decision from `approval.approved`, correlated through `approval.id` and `toolCallId` |

AG-UI explicit resumes match the current outbound adapter: approval confirmations use action IDs; generic interrupts use the string
representation of Neuron's integer interrupt ID. Ordinary deferred handoffs finish
without a standard interrupt and receive tool messages on the next request. A
mixed custom workflow with both deferred calls and other waits uses explicit
interrupts for the whole batch. For a resolved deferred batch, its payload is Neuron's result map:

```json
{
  "resume": [{
    "interruptId": "2",
    "status": "resolved",
    "payload": {"call_1": {"result": {"title": "Example"}}}
  }]
}
```

An explicit AG-UI resume must cover every published interrupt, rejects expired
requests, and takes precedence over mirrored message history. Cancellation omits
the payload. Arbitrary wait cancellation and approval with edited arguments are
unsupported; extend the translator to define those application-specific meanings.

Full histories may include earlier tool cycles; only IDs belonging to the active
requests or their accepted results are considered. Conflicting duplicate answers
and changed accepted results fail. Partial tool results use the existing engine
accumulation. Partial Vercel approvals preserve decisions from the current request
snapshot, producing the cumulative payload expected by ToolNode. Explicit updates
to that still-open approval batch retain the engine's latest-delivery behavior.

An approval response is never an execution result. A frontend confirmation tool
returning `"approved"` remains a tool result and cannot approve a pending backend
execution. Vercel `output-denied` is a presentation state, not an approval response;
submit the decision through `approval-responded`.

## Frontend tool definitions

`AGUIInputTranslator::tools($payload)` converts `RunAgentInput.tools` into
`DeferredTool[]`, reusing the existing JSON-schema property factory. It validates
the catalog and rejects duplicate names without attaching tools to an agent.
The application selects allowed definitions and rejects collisions with its stable
tool list before calling `addTool()`. Reconstruct a fresh agent for each request
and supply the current catalog before resuming approval or performing inference.
Vercel UI-message requests do not define a standard frontend tool catalog, so this
method is separate from the shared continuation interface.

Client tool schemas and results are untrusted model input; thread authorization
and tool policy remain application responsibilities.

## Scope

These are continuation translators, not HTTP servers or complete chat-request
importers. Initial user messages and application-specific request fields still
belong to endpoint setup. Custom integrations can implement the interface directly;
subclassing the built-in translators is optional.

The PHP tests exercise both adapters and translators through agent approval,
committed dispatch, results, and continuation. Tests using actual frontend SDKs
remain a separate integration suite.

## Outbound continuation context

AG-UI needs the current protocol messages and shared state to produce complete
snapshots before an explicit interruption. Supply the frontend conversation,
including the latest user message; do not reconstruct this from backend history
with different message IDs:

```php
$adapter = new AGUIAdapter(
    $authorizedThreadId,
    $payload['runId'],
    $payload['messages'],
    $payload['state'] ?? [],
);
$agent->setStreamAdapter($adapter);
```

The adapter retains this snapshot and adds the text, reasoning, activities, calls,
and results it emits. Approval proposals are `confirmation` interrupts with action
metadata and a response schema. They are not executable calls in message history.
AG-UI argument fragments are buffered until a local result or the persisted
frontend handoff can publish the call safely. Only deferred waits finish as an
ordinary handoff; mixed waits require explicit `resume[]`.

For Vercel, reuse the latest assistant message and its tool parts on a continuation:

```php
$last = $payload['messages'][array_key_last($payload['messages'])] ?? null;
$continuingAssistant = ($last['role'] ?? null) === 'assistant';
$agent->setStreamAdapter(new VercelAIAdapter(
    messageId: $continuingAssistant ? $last['id'] : null,
    parts: $continuingAssistant ? $last['parts'] : [],
));
```

This preserves the assistant message identity and avoids echoing frontend-owned
results back as backend-normalized strings. Already-dispatched pending parts are
not dispatched a second time during a partial continuation. Both adapters suppress
results already present in the supplied frontend context.

Vercel argument previews use `tool-input-start` / `tool-input-delta`. Approvals use
`tool-approval-request` without `tool-input-available`; the latter invokes the
frontend handler and is emitted only at the persisted deferred handoff. Local
results and rejections settle preview parts without invoking that handler. A new
inference after tool results starts a new UI step, so a final text response does
not automatically resubmit the previous completed tool batch.

### Vercel automatic continuation with mixed batches

Configure automatic continuation for both approval responses and frontend results.
The SDK's individual helpers do not cover mixed batches with denied tools or
non-gated previews waiting behind an approval gate. An equivalent combined
predicate for this integration is:

```ts
import { isToolUIPart } from "ai";

// Option passed to useChat alongside the normal transport and onToolCall handler.
sendAutomaticallyWhen: ({ messages }) => {
  const last = messages.at(-1);
  if (last?.role !== "assistant") return false;
  const step = last.parts.reduce(
    (index, part, i) => part.type === "step-start" ? i : index, -1,
  );
  const tools = last.parts.slice(step + 1).filter(isToolUIPart);
  if (!tools.length || tools.some(p => p.state === "approval-requested")) return false;
  if (tools.some(p => p.state === "approval-responded")) return true;
  return tools.every(p =>
    ["output-available", "output-error", "output-denied"].includes(p.state),
  );
}
```

Approval UI uses `addToolApprovalResponse`; frontend handlers use `addToolOutput`.
Cancellation of the HTTP request is not a tool result. Submit an explicit error
output to settle a cancelled frontend operation. Reloading an in-progress browser
operation requires application recovery logic; replaying stream frames cannot
provide exactly-once browser side effects.

References: [AG-UI tools](https://docs.ag-ui.com/concepts/tools),
[AG-UI interrupts](https://docs.ag-ui.com/concepts/interrupts),
[Vercel tool usage](https://ai-sdk.dev/docs/ai-sdk-ui/chatbot-tool-usage).
