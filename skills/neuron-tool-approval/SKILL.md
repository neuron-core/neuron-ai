---
name: neuron-tool-approval
description: Implement human-in-the-loop tool approval flows with Neuron AI agents — gating risky tools behind approve/deny decisions, rendering approval UIs from chat history, submitting decisions, and building a single endpoint that handles both conversation turns and approval resumes. Use this skill whenever the user mentions tool approval, human in the loop (HITL), approve/deny actions, pending approvals, confirming dangerous tool calls, resuming a suspended agent, or building the UI side of an approval workflow.
---

# Neuron AI Tool Approval

This skill helps you gate agent tool execution behind human approval and build the application around it: the server endpoint, the UI, and the decision round trip.

## The Mental Model

**Approval is owned by `ToolNode` and configured on the tools themselves** (ADR 0009). There is no middleware to attach: each tool declares whether it needs approval, you override that per instance when you attach it to the agent, and the node suspends the run before executing anything undecided.

**Chat history is what the application reads.** Which tools await a decision, why each one is asking, and the runId the framework uses to resume — all live on the **last message of the thread**, written once at suspend time. You never inspect workflow state, never boot the agent just to render, and never store a runId on the side.

Two facts shape the UI (ADR 0006):

- **History is append-only.** The suspended `tool_call` message keeps its *pending snapshot* forever; the final outcomes (approved/rejected + feedback + results) are recorded on the `tool_call_result` message that follows it. "Is approval pending?" = the thread tail is a `tool_call` with pending tools.
- **Partial decisions are not persisted anywhere.** The resume payload is **cumulative** — every resume restates the entire decision set, and accumulation lives with your application (client- or server-side) until the set is complete.

## Enabling Approval

Two requirements for cross-process flows: **workflow persistence** (the suspend/resume machinery) and a **durable chat history** (the record itself — `InMemoryChatHistory` keeps the safety property but loses the thread across processes).

```php
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Workflow\Persistence\DatabasePersistence;

$agent = MyAgent::make()
    ->setChatHistory(new SQLChatHistory($threadId, $pdo))
    ->setPersistence(new DatabasePersistence($pdo));
```

That's all the agent-side setup — the gate itself is always active and asks each tool.

### Who decides which tools are gated

**The tool declares its intrinsic risk** by overriding the protected `approvalPolicy()` hook. Returning a **string counts as `true` and doubles as the approval reason** shown to the approver:

```php
class TransferMoneyTool extends Tool
{
    protected function approvalPolicy(array $inputs): bool|string
    {
        return ($inputs['amount'] ?? 0) > 100
            ? 'Transfers above $100 require a human sign-off'
            : false;
    }
}
```

**The agent developer overrides the declaration per tool, at attach time** — deployment policy beats tool default, in both directions:

```php
protected function tools(): array
{
    return [
        DeleteFileTool::make()->requireApproval(),        // force the gate, even if it declares false
        RiskyThirdPartyTool::make()->suppressApproval(),  // waive a tool that declares true
        TransferMoneyTool::make()->withApprovalPolicy(    // replace the policy entirely
            fn (ToolInterface $t): bool|string => ($t->getInputs()['amount'] ?? 0) > 500
                ? 'Transfers above $500 require a human sign-off'
                : false
        ),
    ];
}
```

The last configured override wins (`suppressApproval()` clears an earlier callback and vice versa). A tool with no override falls back to its own `approvalPolicy()` (default: no approval).

## The Suspension

When a gated tool is requested, the run pauses **functionally** — no exception reaches your code:

```php
$handler = $agent->chat(new UserMessage('Delete the old logs file'));
$handler->run();

$handler->interrupted();          // true — the run is suspended
$handler->getMessage();           // the annotated ToolCallMessage (see JSON below)
$handler->getInterruptRequest();  // ApprovalRequest — in-process render source
```

On a suspended run, `getMessage()` returns the **annotated `ToolCallMessage`**: approval states, reasons, and the runId, all stamped. Serialize it straight to your client — it is the same message persisted in chat history.

## The JSON the UI Deals With

A suspended thread's tail message, serialized (two gated tools awaiting decisions):

```json
{
    "run_id": "workflow_6650a1b2c3d4e",
    "role": "assistant",
    "content": [],
    "type": "tool_call",
    "tools": [
        {
            "callId": "toolu_01A2B3C4D5E6F7",
            "name": "delete_file",
            "description": "Delete a file from the filesystem",
            "inputs": { "path": "C:/old_logs.txt" },
            "approval": "pending",
            "approvalReason": "Deleting a file is irreversible",
            "rejectReason": null
        },
        {
            "callId": "toolu_08G9H0I1J2K3L4",
            "name": "send_email",
            "description": "Send an email to a recipient",
            "inputs": { "to": "team@example.com", "subject": "Logs cleanup" },
            "approval": "pending",
            "approvalReason": "Outbound email reaches people outside this workspace",
            "rejectReason": null
        }
    ]
}
```

| Field | Use it for |
|---|---|
| `type: "tool_call"` | Discriminator — this message type can carry approvals. |
| `tools[].approval` | `"pending"` — or **absent** for a non-gated tool (no UI, runs automatically). This message keeps its pending snapshot forever; final outcomes land on the following `tool_call_result`. |
| `tools[].callId` | **The key of the whole flow** — render by it, submit decisions by it. |
| `tools[].name`, `tools[].inputs` | What the human is approving: which action, with which arguments. |
| `tools[].approvalReason` | **Outbound** — why approval is being asked (declared by the tool or its attach-time policy). Show it on the approval card. |
| `tools[].rejectReason` | **Inbound** — the approver's feedback, rejections only. The model receives it verbatim. |
| `run_id` | Framework-internal; the framework reads it back from history on resume. Never used by the UI — just preserve it if you re-serialize. |

The two reason fields are a matched pair with opposite authors: `approvalReason` is the *tool talking to the human*; `rejectReason` is the *human talking back to the model*.

### Detecting a pending approval

```js
const last = thread.messages.at(-1);
const pendingTools = last?.type === 'tool_call'
    ? last.tools.filter(t => t.approval === 'pending')
    : [];
const isSuspended = pendingTools.length > 0;
```

If suspended: render one card per `tools[]` entry that **has** an `approval` field (name, `inputs`, `approvalReason`, Approve/Deny actions — Deny with an optional free-text reason), and **lock the message input**. Decide from the tail only — older `tool_call` messages are settled record (read their outcomes from the `tool_call_result` that follows them).

## Submitting Decisions

Decisions travel as a plain map keyed by `callId`. Three value forms — anything else is silently ignored:

| You send | Meaning | What the model eventually sees |
|---|---|---|
| `"approve"` | Run the tool | The tool's real output. Approvals are bare — no comment channel. |
| `"reject"` | Skip the tool | The rejection template with "No specific instruction provided." |
| `["reject", "your reason"]` | Skip, with feedback | The rejection template with your reason verbatim. |

The rejection template delivered as the tool result:

> TOOL NOT EXECUTED. The user rejected this action. User instruction: *{reason}*. Do not attempt this tool again. Follow the user's instruction or reconsider your plan.

A good reject reason ("too expensive, find a cheaper option") steers the model's next step; a bare reject only stops this one.

### The contract (ADR 0002/0006)

1. **Cumulative** — the payload is the *entire decision set*, restated on every resume. A decision that is not restated is not remembered: an omitted `callId` reverts to pending.
2. **Accumulation lives with your app** — collect decisions client- or server-side; the framework deliberately persists no partial progress (a process death loses undelivered partials; the caller re-sends).
3. **Revisable until complete** — the latest delivered payload wins, so a resubmitted `callId` overwrites its earlier decision while any tool is still pending.
4. **Completeness is the point of no return** — the moment every gated tool has a decision, the workflow proceeds immediately.
5. **Silence is never consent** — an incomplete set re-suspends; a tool executes only on explicit `"approve"`.
6. **Unknown ids and malformed values are ignored** — they don't error, they simply don't land.

### UI submission patterns

- **Batch with confirmation (the natural fit)**: collect decisions locally, submit one complete map on "Confirm". For a review step, **withhold one decision until confirmed** — an incomplete set is your draft state. This is the intended way to build a confirm stage; there is deliberately no built-in one.
- **Submit-per-click**: keep the accumulated map in your app (client state or your own store) and send the *whole map so far* on every click. Each incomplete submission re-suspends; the run proceeds when the last decision lands. Never send only the newest decision — the earlier ones would revert to pending.

### Pitfalls

- **Sending only the newest decision loses the earlier ones** — the payload is cumulative; an omitted decision reverts that tool to pending. Always restate the full set.
- **A typo'd `callId` fails silently** — the thread just stays suspended. After every submission, check the response: if still `awaiting_approval`, diff your accumulated map against the pending cards.
- **`["approve", "note"]` doesn't exist** — it is malformed and silently ignored; the tool stays pending. Only rejections carry text.
- **The tail message won't show partial progress** — it keeps its pending snapshot (append-only history). Render interim progress from your own accumulated map, not from the thread.

## One Endpoint for the Whole Conversation

A normal turn and an approval resume are the same operation: build the agent from the thread id, feed it what the client sent, return the thread's new state. With a `payload`, the runId is adopted from the chat history tail automatically; with a message, a fresh turn starts.

```php
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;

/**
 * POST /threads/{threadId}/chat — body is ONE of:
 *   { "message": "Delete the old logs file" }
 *   { "decisions": { "toolu_01...": "approve", "toolu_08...": ["reject", "why"] } }
 * `decisions` is the FULL accumulated set (cumulative contract).
 */
function chatEndpoint(string $threadId, array $body): array
{
    $agent = makeAgentForThread($threadId);   // history + persistence + tools (with approval overrides)

    $handler = isset($body['decisions'])
        ? $agent->chat(payload: $body['decisions'])
        : $agent->chat(new UserMessage($body['message']));

    try {
        $handler->run();
    } catch (ChatHistoryException $e) {
        // A user message arrived while the thread has a pending tool call
        // (the UI failed to lock the input). Nothing was executed or persisted.
        return ['status' => 'conflict', 'error' => $e->getMessage()];   // HTTP 409
    }

    // Both branches converge: on a suspended run getMessage() IS the annotated
    // ToolCallMessage; otherwise it is the assistant's reply.
    return [
        'status' => $handler->interrupted() ? 'awaiting_approval' : 'completed',
        'message' => $handler->getMessage()->jsonSerialize(),
    ];
}
```

Why this works as one endpoint:

1. **Same construction** — both paths build the identical agent from the thread id; the suspended run's token is in the history tail, so the decisions branch needs nothing extra.
2. **Same outcomes** — a fresh turn can end suspended (model called a gated tool) and a resume can end suspended (incomplete decision set, or the model called another gated tool). One response contract covers both: `awaiting_approval | completed`.
3. **Same failure containment** — a user message on a suspended thread is rejected by the history's message-alternation rule before anything reaches the provider or the durable store; map it to HTTP 409.

Treat `message` and `decisions` as mutually exclusive in the request body (400 if both). Serialize with `->jsonSerialize()` explicitly — framework serializers (e.g. Symfony's) would otherwise reflect over the object and produce a different shape than documented. For streaming, swap `chat()` for `stream()`, emit `$handler->events()`, and check `interrupted()` after the stream drains.

## A Complete Decision Round Trip

The client accumulates; every submission carries the whole set so far.

```json
POST /threads/th_42/chat
{ "decisions": { "toolu_01A2B3C4D5E6F7": "approve" } }
```

Set incomplete → still `awaiting_approval`. The tail keeps its pending snapshot — the client renders progress from its own accumulated map and keeps collecting:

```json
{ "status": "awaiting_approval",
  "message": { "type": "tool_call", "tools": [
      { "callId": "toolu_01A2B3C4D5E6F7", "approval": "pending", "...": "..." },
      { "callId": "toolu_08G9H0I1J2K3L4", "approval": "pending", "...": "..." } ] } }
```

```json
POST /threads/th_42/chat
{ "decisions": {
    "toolu_01A2B3C4D5E6F7": "approve",
    "toolu_08G9H0I1J2K3L4": ["reject", "Do not email the whole team"] } }
```

Set complete → approved tool runs, rejected tool's template becomes its result, model replies:

```json
{ "status": "completed",
  "message": { "role": "assistant", "content": [ { "type": "text",
      "text": "I deleted the file. I didn't send the email — let me know if you'd like to notify someone individually." } ] } }
```

## Related

- **Stale suspensions / deadlines**: an `expiresAt` on the outbound request plus a `$timedOut` resume — see the **neuron-workflow-architect** skill (suspend & resume beyond approval).
- **Declaring tool risk** when creating tools: the **neuron-tool-creator** skill.
- **Agent setup** (providers, history backends, persistence): the **neuron-agent-builder** skill.
