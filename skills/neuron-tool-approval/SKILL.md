---
name: neuron-tool-approval
description: Implement human-in-the-loop tool approval flows with Neuron AI agents — gating risky tools behind approve/deny decisions, rendering approval UIs from chat history, submitting decisions, and building a single endpoint that handles both conversation turns and approval resumes. Use this skill whenever the user mentions tool approval, human in the loop (HITL), approve/deny actions, pending approvals, confirming dangerous tool calls, resuming a suspended agent, or building the UI side of an approval workflow.
---

# Neuron AI Tool Approval

This skill helps you gate agent tool execution behind human approval and build the application around it: the server endpoint, the UI, and the decision round trip.

## The Mental Model

**Chat history is the system of record for tool approval.** Everything the application needs — which tools await a decision, why each one is asking, what was already decided, and the token the framework uses to resume — lives on the **last message of the thread**. You never inspect workflow state, never boot the agent just to render, and never store a workflow id on the side.

While an approval is pending, the annotated `tool_call` message is guaranteed to be the thread's tail: partial decisions update it in place, they never append.

## Enabling Approval

Two requirements: **workflow persistence** (the suspend/resume machinery) and a **durable chat history** (the record itself — `InMemoryChatHistory` keeps the safety property but loses progress across processes).

```php
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Workflow\Persistence\DatabasePersistence;

$agent = MyAgent::make()
    ->setChatHistory(new SQLChatHistory($threadId, $pdo))
    ->setPersistence(new DatabasePersistence($pdo))
    ->addMiddleware(ToolNode::class, new ToolApproval());
```

Attach the middleware to `ToolNode::class` — matching is subclass-aware, so it also covers `ParallelToolNode`.

### Who decides which tools are gated

With no config, **each tool decides for itself** by overriding `requiresApproval()`. Returning a **string counts as `true` and doubles as the approval reason** shown to the approver:

```php
class TransferMoneyTool extends Tool
{
    public function requiresApproval(array $inputs): bool|string
    {
        return ($inputs['amount'] ?? 0) > 100
            ? 'Transfers above $100 require a human sign-off'
            : false;
    }
}
```

Middleware config overrides the tool's declaration in both directions, with the same `bool|string` semantics:

```php
new ToolApproval([
    DeleteFileTool::class,                      // always gated, even if it declares false
    'transfer_money' => fn (ToolInterface $t): bool|string =>
        ($t->getInputs()['amount'] ?? 0) > 100
            ? 'Transfers above $100 require a human sign-off'
            : false,                            // waived below the threshold
]);
```

## The Suspension

When a gated tool is requested, the run pauses **functionally** — no exception reaches your code:

```php
$handler = $agent->chat(new UserMessage('Delete the old logs file'));
$handler->run();

$handler->interrupted();          // true — the run is suspended
$handler->getMessage();           // the annotated ToolCallMessage (see JSON below)
$handler->getInterruptRequest();  // ApprovalRequest — in-process render source
```

On a suspended run, `getMessage()` returns the **annotated `ToolCallMessage`**: approval states, reasons, and the resume token, all stamped. Serialize it straight to your client — it is the same message persisted in chat history.

## The JSON the UI Deals With

A suspended thread's tail message, serialized (two gated tools — one pending, one rejected in an earlier partial submission):

```json
{
    "resume_token": "workflow_6650a1b2c3d4e",
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
            "approval": "rejected",
            "approvalReason": "Outbound email reaches people outside this workspace",
            "rejectReason": "Do not email the whole team"
        }
    ]
}
```

| Field | Use it for |
|---|---|
| `type: "tool_call"` | Discriminator — this message type can carry approvals. |
| `tools[].approval` | `"pending"` / `"approved"` / `"rejected"` — or **absent** for a non-gated tool (no UI, runs automatically). |
| `tools[].callId` | **The key of the whole flow** — render by it, submit decisions by it. |
| `tools[].name`, `tools[].inputs` | What the human is approving: which action, with which arguments. |
| `tools[].approvalReason` | **Outbound** — why approval is being asked (declared by the tool or middleware config). Show it on the approval card. |
| `tools[].rejectReason` | **Inbound** — the approver's feedback, rejections only. The model receives it verbatim. |
| `resume_token` | Framework-internal; the framework reads it back from history on resume. Never used by the UI — just preserve it if you re-serialize. |

The two reason fields are a matched pair with opposite authors: `approvalReason` is the *tool talking to the human*; `rejectReason` is the *human talking back to the model*.

### Detecting a pending approval

```js
const last = thread.messages.at(-1);
const pendingTools = last?.type === 'tool_call'
    ? last.tools.filter(t => t.approval === 'pending')
    : [];
const isSuspended = pendingTools.length > 0;
```

If suspended: render one card per `tools[]` entry that **has** an `approval` field (name, `inputs`, `approvalReason`, Approve/Deny actions — Deny with an optional free-text reason), and **lock the message input**. Decide from the tail only — older `tool_call` messages are settled record.

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

### The merge rules

1. **Incremental** — send only what the user just decided; never restate earlier decisions.
2. **Revisable until complete** — resubmitting a `callId` overwrites its decision (last-write-wins) while any tool is still pending.
3. **Completeness is the point of no return** — the moment every gated tool has a decision, the workflow proceeds immediately.
4. **Silence is never consent** — unmentioned tools stay pending; the run stays suspended. A tool executes only on explicit `"approve"`.
5. **Unknown ids and malformed values are ignored** — they don't error, they simply don't land.

### UI submission patterns — both natively supported

- **Submit-per-click**: each Approve/Deny fires one decision; the response re-renders remaining cards.
- **Batch with confirmation**: collect decisions locally, submit one map on "Confirm". For a review step, **withhold one decision until confirmed** — an incomplete set is your draft state. This is the intended way to build a confirm stage; there is deliberately no built-in one.

### Pitfalls

- **A typo'd `callId` fails silently** — the thread just stays suspended. After every submission, check the response: if still `awaiting_approval`, diff which entries remain `"pending"`.
- **`["approve", "note"]` doesn't exist** — it is malformed and silently ignored; the tool stays pending. Only rejections carry text.
- **Don't resend cached decisions** — harmless when identical, but last-write-wins makes an accidental flip real.

## One Endpoint for the Whole Conversation

A normal turn and an approval resume are the same operation: build the agent from the thread id, feed it what the client sent, return the thread's new state. With a `payload`, the resume token is adopted from the chat history tail automatically; with a message, a fresh turn starts.

```php
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;

/**
 * POST /threads/{threadId}/chat — body is ONE of:
 *   { "message": "Delete the old logs file" }
 *   { "decisions": { "toolu_01...": "approve", "toolu_08...": ["reject", "why"] } }
 */
function chatEndpoint(string $threadId, array $body): array
{
    $agent = makeAgentForThread($threadId);   // history + persistence + tools + ToolApproval

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
2. **Same outcomes** — a fresh turn can end suspended (model called a gated tool) and a resume can end suspended (partial decisions, or the model called another gated tool). One response contract covers both: `awaiting_approval | completed`.
3. **Same failure containment** — a user message on a suspended thread is rejected by the history's message-alternation rule before anything reaches the provider or the durable store; map it to HTTP 409.

Treat `message` and `decisions` as mutually exclusive in the request body (400 if both). Serialize with `->jsonSerialize()` explicitly — framework serializers (e.g. Symfony's) would otherwise reflect over the object and produce a different shape than documented. For streaming, swap `chat()` for `stream()`, emit `$handler->events()`, and check `interrupted()` after the stream drains.

## A Complete Decision Round Trip

```json
POST /threads/th_42/chat
{ "decisions": { "toolu_01A2B3C4D5E6F7": "approve" } }
```

Set incomplete → still `awaiting_approval`; same message, updated states — re-render:

```json
{ "status": "awaiting_approval",
  "message": { "type": "tool_call", "tools": [
      { "callId": "toolu_01A2B3C4D5E6F7", "approval": "approved", "...": "..." },
      { "callId": "toolu_08G9H0I1J2K3L4", "approval": "pending",  "...": "..." } ] } }
```

```json
POST /threads/th_42/chat
{ "decisions": { "toolu_08G9H0I1J2K3L4": ["reject", "Do not email the whole team"] } }
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
