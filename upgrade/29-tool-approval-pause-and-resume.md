# Upgrade: Agent approval pauses and resumes

## Summary

When a tool call needs approval, an Agent (or RAG) no longer throws. `chat()`, `stream()` and `structured()` return normally and the pause is on the result. The caller answers with an array of decisions keyed by tool call ID instead of modifying the request and passing it back.

| 3.x | 4.x |
|---|---|
| `chat()`/`stream()`/`structured()` throw `WorkflowInterrupt` | `chat()` returns an `AgentState` with `isInterrupted()` true; `stream()` yields a last `InterruptEvent` and its `getReturn()` state is interrupted; `structured()` returns `null` |
| `$interrupt->getRequest()`, `getState()`, `getWorkflowId()`/`getResumeToken()` | `$state->getInterruptRequest()`, `$state`, `$agent->getThreadId()` |
| `$request->getPendingActions()` | `$agent->pendingApprovals()`: a list of `Action` |
| `$action->approve()`, `reject($feedback)`, `edit()`, `decision()`, `feedback()`, property writes | `$decisions[$action->id] = 'approve'`, `'reject'` or `['reject', $feedback]` |
| `chat(interrupt: $request)`, `stream(interrupt: $request)`, `structured(..., interrupt: $request)` | `$agent->submitApprovalDecisions($decisions)->run()` or `->events()` |
| Pending or missing actions are rejected on resume | They pause the run again |
| A new turn while an approval is pending starts a new run | It throws `RunInFlightException` |
| `NeuronAI\Workflow\Interrupt\{ApprovalRequest, Action, ActionDecision}` | `NeuronAI\Agent\Interrupt\{ApprovalRequest, Action, ActionDecision}`, read-only, no `ActionDecision::Edit` |
| Approval JSON `{"message", "actions": "<JSON string>"}` | `{"interruptId", "type", "eventName", "expiresAt", "message", "actions": [...]}` |

Not in this guide:
- Which tools require approval: guide 28 (already applied).
- `interrupt()` calls inside workflow nodes and resuming a plain Workflow: guide 15 (already applied).
- Stream adapters and the frames they send for a pause: guides 35 to 37.

## Stored data

- Runs paused under 3.x cannot be resumed by 4.x. Guide 14 covers draining them before the deploy.
- The thread ID bound in guide 13 addresses the paused run. Resume tokens are no longer used.
- Approval requests or interrupts the application stored itself (a `json_encode`d approval in a table, `serialize($interrupt)` in a cache or session) cannot be turned back into 4.x objects: `fromArray()` is gone and the serialized 3.x classes no longer exist. Stop writing them (Case 2). Do not delete the stored ones or drop their columns yourself: tell the developer they can be removed once no 3.x pause is pending (guide 14). 4.x persistence keeps the pending request, and `$agent->pendingApprovals()` reads it.
- 3.x carried the conversation inside the persisted interrupt. 4.x reads it from the agent's chat history. If the resume runs in another request, job or process and the agent keeps an in-memory history (no chat history configured, or `InMemoryChatHistory`), the resume throws `ChatHistoryException`. Report this to the developer and ask which durable store the conversation should use: guide 31 configures it.

## What to Search For

```bash
# 1. Pauses and resumes around agent calls
grep -rnE --include='*.php' --exclude-dir=vendor -e 'WorkflowInterrupt' -e 'interrupt:' .

# 2. The moved approval classes in any file type: plain, escaped and grouped names
grep -rnE --exclude-dir=vendor --exclude-dir=node_modules -e 'Workflow\\+Interrupt\\+(\{|ApprovalRequest|Action)' -e 'use +NeuronAI\\+Workflow\\+Interrupt *;' .

# 3. Removed members of ApprovalRequest and Action
grep -rnE --include='*.php' --exclude-dir=vendor -e '->(getPendingActions|getApprovedActions|getRejectedActions|getAction|addAction|setActions|isEdited)\(' -e '->(approve|reject|edit|decision|feedback)\(' -e '->(decision|feedback) *=[^=>]' -e 'ActionDecision::Edit' -e '(ApprovalRequest|Action)::fromArray\(' .

# 4. Clients and tests that read the 3.x approval JSON, resume token or rejection text
grep -rnE --exclude-dir=vendor --exclude-dir=node_modules -e 'resumeToken' -e '(JSON\.parse|json_decode)\([^)]*actions' -e 'TOOL NOT EXECUTED' -e 'No human approval was provided' .
```

Follow the hits:
- Search 1: every `catch (WorkflowInterrupt ...)` around `chat()`, `stream()` or `structured()` of an Agent or RAG (Cases 2 to 4), and every `interrupt:` argument (Case 5). `WorkflowInterrupt` in unit tests that invoke a workflow node alone belongs to guide 15: leave it.
- Follow each request taken from a `catch` to where it is modified, stored, sent to a client or passed back. It can be passed back positionally: `chat($messages, $request)`, `stream([], $request)`, `structured($messages, $class, $retries, $request)`.
- Search 3 also matches unrelated APIs (for example Laravel's `Collection::reject()`): change only calls on approval requests and actions.
- Also list the agents that have approval-gated tools (guide 28). Each of their `chat()`, `stream()` and `structured()` calls needs the pause check of Cases 2 to 4, even where 3.x had no `catch`.
- Search 4 covers PHP, JavaScript, TypeScript, templates and tests (Cases 8 and 9).

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: References to the moved classes

| 3.x | 4.x |
|---|---|
| `NeuronAI\Workflow\Interrupt\ApprovalRequest` | `NeuronAI\Agent\Interrupt\ApprovalRequest` |
| `NeuronAI\Workflow\Interrupt\Action` | `NeuronAI\Agent\Interrupt\Action` |
| `NeuronAI\Workflow\Interrupt\ActionDecision` | `NeuronAI\Agent\Interrupt\ActionDecision` |

1. Rewrite `use` statements, fully qualified names, `::class`, docblocks, and class names in strings and config (container definitions, `phpstan.neon` `ignoreErrors`). Keep `as` aliases and each file's escaping: a `\\` separator stays `\\`.
2. Split group imports: `InterruptRequest` keeps its namespace, and `WorkflowInterrupt` goes away in Case 2.

   Before (3.x):
   ```php
   use NeuronAI\Workflow\Interrupt\{ApprovalRequest, Action, InterruptRequest};
   ```

   After (4.x):
   ```php
   use NeuronAI\Agent\Interrupt\{ApprovalRequest, Action};
   use NeuronAI\Workflow\Interrupt\InterruptRequest;
   ```
3. A namespace import (`use NeuronAI\Workflow\Interrupt;` with `Interrupt\ApprovalRequest`): import the classes from `NeuronAI\Agent\Interrupt` and use their short names.
4. There is no alias. `new`, static calls and enum cases on the old names fail with "Class not found", and a parameter typed with an old name throws a `TypeError`, but an `instanceof` check against an old name silently returns `false`. Rely on static analysis, not on the tests.

### Case 2: A `catch (WorkflowInterrupt ...)` around `chat()`

Before (3.x):
```php
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

try {
    $message = $agent->chat(new UserMessage($text))->getMessage();

    return ['status' => 'completed', 'answer' => $message->getContent()];
} catch (WorkflowInterrupt $interrupt) {
    /** @var ApprovalRequest $request */
    $request = $interrupt->getRequest();

    return [
        'status' => 'approval_required',
        'resumeToken' => $interrupt->getWorkflowId(),
        'approval' => $request,
    ];
}
```

After (4.x):
```php
use NeuronAI\Chat\Messages\UserMessage;

$state = $agent->chat(new UserMessage($text));

if ($state->isInterrupted()) {
    return [
        'status' => 'approval_required',
        'resumeToken' => $agent->getThreadId(),
        'approval' => $state->getInterruptRequest(),
    ];
}

return ['status' => 'completed', 'answer' => $state->getMessage()?->getContent()];
```

1. Move the `catch` body after the call, under `if ($state->isInterrupted())`. Code that 3.x ran after `chat()` only on completion goes after that check. On a paused state, `getMessage()` returns the model's `ToolCallMessage`, not an answer.
2. Replace the getters:

   | 3.x `WorkflowInterrupt` | 4.x |
   |---|---|
   | `getRequest()` | `$state->getInterruptRequest()`, an `ApprovalRequest` for a tool approval (check it with `instanceof` before calling `getActions()`) |
   | `getState()` | `$state` |
   | `getWorkflowId()`, `getResumeToken()` | `$agent->getThreadId()` |
   | `getMessage()` | `$state->getInterruptRequest()?->getMessage()` |
   | `json_encode($interrupt)`, `jsonSerialize()`, `serialize($interrupt)` | Send `['threadId' => $agent->getThreadId(), 'approval' => $state->getInterruptRequest()]`. Do not store the request: `$agent->pendingApprovals()` reads it later |
   | `getNode()`, `getEvent()`, `getBranchId()`, `getParallelEvent()`, `getCompletedBranchResults()`, `isParallelInterrupt()` | No replacement: delete that code |

3. The resume endpoint finds the run by the thread ID. Where the client echoed the token back, keep the key it reads and send the thread ID in it, as above. If the client already sends its thread ID, drop the key.
4. Delete `catch (WorkflowInterrupt $e) { throw $e; }` blocks and `instanceof WorkflowInterrupt` branches in generic `catch` blocks. Remove `@throws WorkflowInterrupt` tags and the `use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;` import.

### Case 3: Streams

Before (guide 23 already turned the stream into a generator):
```php
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

try {
    $stream = $agent->stream(new UserMessage($text));
    foreach ($stream as $chunk) {
        if ($chunk instanceof TextChunk) {
            echo $chunk->content;
        }
    }
} catch (WorkflowInterrupt $interrupt) {
    $this->askApproval($interrupt->getRequest());
}
```

After (4.x):
```php
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Events\InterruptEvent;

$stream = $agent->stream(new UserMessage($text));
foreach ($stream as $chunk) {
    if ($chunk instanceof InterruptEvent) {
        continue; // the pause: handled after the loop
    }
    if ($chunk instanceof TextChunk) {
        echo $chunk->content;
    }
}

$state = $stream->getReturn();
if ($state->isInterrupted()) {
    $this->askApproval($state->getInterruptRequest());
}
```

- The last item of a paused stream is a `NeuronAI\Workflow\Events\InterruptEvent` (`$chunk->request` is the request). Keep it away from code that expects chunks.
- A stream through an adapter (`->events($adapter)` or `->streamEvents($adapter)`, left in place by guide 23): remove the `try`/`catch` and move the whole catch body, including any pause frame it wrote, after the loop under `if ($agent->pendingApprovals() !== [])`. Leave the frame code as it is: if the adapter is an app class, guide 35 moves the frame into its `interrupt()` method; for the built-in `VercelAIAdapter`/`AGUIAdapter`, guide 36 deletes it because those adapters send their own (guide 37). Guide 36 converts the adapter call itself.

### Case 4: `structured()`

Before (3.x):
```php
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

try {
    $report = $agent->structured(new UserMessage($text), Report::class);
} catch (WorkflowInterrupt $interrupt) {
    return $this->askApproval($interrupt->getRequest());
}
```

After (4.x):
```php
$report = $agent->structured(new UserMessage($text), Report::class);

if ($report === null) { // paused: a tool call awaits approval
    return $this->askApproval($agent->pendingApprovals());
}
```

`structured()` returns no state: read the pending actions with `$agent->pendingApprovals()`, or the whole request with `$agent->inspect()?->interrupt`. Adapt `askApproval()` to what it now receives.

### Case 5: Answering and continuing

Before (3.x, answered in the same process):
```php
try {
    $message = $agent->chat(new UserMessage($text))->getMessage();
} catch (WorkflowInterrupt $interrupt) {
    /** @var ApprovalRequest $request */
    $request = $interrupt->getRequest();
    foreach ($request->getPendingActions() as $action) {
        if ($this->confirm($action)) {
            $action->approve();
        } else {
            $action->reject('User denied operation');
        }
    }

    $message = $agent->chat(interrupt: $request)->getMessage();
}
```

After (4.x; `confirm()` now receives a `NeuronAI\Agent\Interrupt\Action`):
```php
$state = $agent->chat(new UserMessage($text));

while ($state->isInterrupted()) {
    $decisions = [];
    foreach ($agent->pendingApprovals() as $action) {
        $decisions[$action->id] = $this->confirm($action) ? 'approve' : ['reject', 'User denied operation'];
    }

    $state = $agent->submitApprovalDecisions($decisions)->run();
}

$message = $state->getMessage();
```

Before (3.x, a resume endpoint receiving the request back from the client):
```php
use NeuronAI\Workflow\Interrupt\ApprovalRequest;

$request = ApprovalRequest::fromArray($input['approval']);
$message = $agent->chat(interrupt: $request)->getMessage();
```

After (4.x):
```php
$actions = $input['approval']['actions'];
$posted = array_column(is_string($actions) ? json_decode($actions, true) : $actions, null, 'id');

$decisions = [];
foreach ($agent->pendingApprovals() as $action) {
    $choice = $posted[$action->id] ?? [];
    $feedback = $choice['feedback'] ?? null;
    $decisions[$action->id] = ($choice['decision'] ?? null) === 'approved'
        ? 'approve'
        : ($feedback === null ? 'reject' : ['reject', $feedback]);
}

$state = $agent->submitApprovalDecisions($decisions)->run();

if ($state->isInterrupted()) {
    // paused again by another tool call: answer as in Case 2
}
```

1. `$agent` is bound to the paused thread with the same persistence as the run that paused (guide 13), and registers the same tools with the same approval configuration (guide 28).
2. Turn every modification of an action into a decision keyed by `$action->id`, the tool call ID, and delete the modification:

   | 3.x | Decision |
   |---|---|
   | `approve()`, `approve($feedback)`, `decision(ActionDecision::Approved)`, `$action->decision = ActionDecision::Approved` | `'approve'` (approval feedback has no slot: drop it) |
   | `reject($feedback)`, `decision(ActionDecision::Rejected)` with `feedback($feedback)`, the same property writes | `'reject'`, or `['reject', $feedback]` with a string `$feedback` |
   | `edit($feedback)`, `ActionDecision::Edit` | `'reject'` or `['reject', $feedback]`: 3.x rejected edited actions |
   | An action left pending, or missing from the request | `'reject'`. 3.x rejected it; left out, it pauses the run again |

   Loop over `$agent->pendingApprovals()` as above so that every pending action gets a decision.
3. Replace the continuation call:

   | 3.x | 4.x |
   |---|---|
   | `->chat(interrupt: $request)`, `->chat($messages, $request)`, followed by `->getMessage()` or `->run()` | `->submitApprovalDecisions($decisions)->run()`, then `->getMessage()` on the state |
   | `->stream(interrupt: $request)`, iterated directly or through `->events()` | Iterate `->submitApprovalDecisions($decisions)->events()` as in Case 3; `getReturn()` is the state |
   | `->stream(interrupt: $request)->events($adapter)` | `->submitApprovalDecisions($decisions)->events($adapter)`. Leave the adapter argument: guide 36 moves it |
   | `->stream(interrupt: $request)->run()` or `->getMessage()`, not iterated | `->submitApprovalDecisions($decisions)->run()` |
   | `->structured($messages, $class, $retries, $request)`, `->structured(interrupt: $request)` | `->submitApprovalDecisions($decisions)->run()->get('structured_output')` |

   - Drop messages passed together with the request: 3.x ignored them. `structured()` continues with the output class and retries of the turn that paused.
   - A continuation streams provider tokens only if the turn started with `stream()` or `chat($messages, stream: true)`. Where 3.x started with `chat()` and continued with `stream(interrupt:)`, change that `chat()` to `chat($messages, stream: true)`.
   - An `InterruptRequest` left as the second argument of `chat()` is a `TypeError`. As the second argument of `stream()` or the fourth of `structured()` it is ignored, and the call starts a new turn (Case 6).
4. A continuation can pause again, for a later tool call or for actions without a decision. Handle the returned state as in Cases 2 to 4.
5. `submitApprovalDecisions()` throws `NeuronAI\Exceptions\InputTranslationException` when the thread has nothing paused (never paused, already answered or completed), when a key is not a tool call ID of the request, when a value is not one of the shapes above, or when the array is empty. 3.x threw a `WorkflowException` when nothing was saved; `InputTranslationException` is not a `WorkflowException`, so update the `catch` blocks that handled a stale approval.
6. A pause raised by an app node added to the agent, not a tool approval: continue with `$agent->submitInputs($answer)->run()`, where `$answer` is the array that node reads (guide 15, Cases 3 and 4).

### Case 6: A new turn while an approval is pending

In 3.x a new `chat()` on the thread started an unrelated run. In 4.x, `chat()` and `structured()` throw `NeuronAI\Exceptions\RunInFlightException`, and `stream()` throws when iteration starts. `$e->status` is `NeuronAI\Workflow\WorkflowStatus::Suspended` and `$e->interrupt` is the pending `ApprovalRequest`.

Ask the developer what a new message should do while an approval is pending:
- Refuse it and show the pending approval again: check `$agent->pendingApprovals() !== []` before the turn, or catch the exception.
  ```php
  use NeuronAI\Exceptions\RunInFlightException;

  try {
      $state = $agent->chat(new UserMessage($text));
  } catch (RunInFlightException $e) {
      return ['status' => 'approval_required', 'approval' => $e->interrupt];
  }
  ```
- Reject the pending calls and let the model answer the rejection: `$state = $agent->submitApprovalDecisions(array_fill_keys(array_map(fn (Action $a): string => $a->id, $agent->pendingApprovals()), 'reject'))->run();`. Start the new turn only if `$state->isInterrupted()` is false; otherwise handle the new pause as in Case 2.
- Discard the conversation with `$agent->resetConversation()`, which also deletes its history. `$agent->abandon()` throws `AgentException` while a tool call is unanswered.

### Case 7: Code that reads or builds approval requests

`ApprovalRequest` keeps `getMessage()` and `getActions()`. `Action` keeps the properties `id`, `name`, `description`, `decision` and `feedback` (`decision` and `feedback` are now `readonly` too) and `isPending()`, `isApproved()`, `isRejected()`. It adds `reason` (why the tool asks for approval) and `inputs` (the call's arguments).

| 3.x | 4.x |
|---|---|
| `$request->getPendingActions()` | `$agent->pendingApprovals()`, or `array_filter($request->getActions(), fn (Action $a): bool => $a->isPending())` |
| `getApprovedActions()`, `getRejectedActions()` | The same filter with `isApproved()` / `isRejected()` |
| `$request->getAction($id)` | Search `getActions()` for `$action->id === $id` |
| Results keyed by action ID (`get*Actions()`) | `getActions()` and `pendingApprovals()` return lists: key them by `$action->id` yourself |
| `$action->decision()`, `$action->feedback()` with no argument | `$action->decision`, `$action->feedback` |
| `addAction()`, `setActions()` | Pass every action to `new ApprovalRequest($message, $actions)`. A duplicate action ID throws `NeuronAI\Exceptions\WorkflowException` (3.x kept the last one) |
| `approve()`, `reject()`, `edit()`, `decision($decision)`, `feedback($feedback)`, `$action->decision = ...`, `$action->feedback = ...` | Decisions (Case 5) |
| `isEdited()`, `ActionDecision::Edit` | Removed: treat an edit as a rejection (Case 5) |
| `ApprovalRequest::fromArray()`, `Action::fromArray()` | Build decisions from the posted data (Case 5) |

The constructors accept their 3.x arguments: `new ApprovalRequest($message, $actions)` and `new Action($id, $name, $description, $decision, $feedback)`. A class that `extends ApprovalRequest` was migrated by guide 15 (Case 6).

### Case 8: Clients of the approval JSON

- 3.x `json_encode($request)`: `{"message": "...", "actions": "[{\"id\": ...}]"}`, where `actions` is a JSON string of `{id, name, description, decision, feedback}` objects.
- 4.x: `{"interruptId": 1, "type": "wait_for_event", "eventName": "approval", "expiresAt": null, "message": "...", "actions": [{"id", "name", "description", "decision", "feedback", "reason", "inputs"}]}`. `actions` is an array, and `inputs` is an object (`{}` without arguments).

Remove the second `JSON.parse` / `json_decode` of `actions` in client code in the repository. Where the client reads `resumeToken`, it now receives the thread ID (Case 2). `decision` values are `pending`, `approved` and `rejected`; `edit` no longer exists. If the client lives outside the repository, report the new shape to the developer.

### Case 9: Tests

Before (3.x):
```php
try {
    $agent->chat(new UserMessage('Delete /tmp/report.txt'));
    $this->fail('Expected an approval pause');
} catch (WorkflowInterrupt $interrupt) {
    /** @var ApprovalRequest $request */
    $request = $interrupt->getRequest();
}

$request->getAction('call_1')->approve();
$message = $agent->chat(interrupt: $request)->getMessage();
```

After (4.x):
```php
use NeuronAI\Agent\Interrupt\Action;

$state = $agent->chat(new UserMessage('Delete /tmp/report.txt'));
$this->assertTrue($state->isInterrupted());
$this->assertSame(['call_1'], array_map(fn (Action $action): string => $action->id, $agent->pendingApprovals()));

$state = $agent->submitApprovalDecisions(['call_1' => 'approve'])->run();
$this->assertFalse($state->isInterrupted());
$message = $state->getMessage();
```

- Replace `expectException(WorkflowInterrupt::class)` around agent calls with the `isInterrupted()` assertion.
- The rejection result now ends with `Follow the user's instruction or reconsider your plan.` (3.x: `Follow the user's instruction.`). `No human approval was provided for this action.` is no longer written: a missing decision pauses the run again. Update assertions on these texts.
- A test that resumes on a second agent instance must give it the same persistence and chat history objects as the first.

## Checklist

- No `catch (WorkflowInterrupt`, `instanceof WorkflowInterrupt` or `@throws WorkflowInterrupt` remains around Agent or RAG calls.
- Every `chat()`, `stream()` and `structured()` of an agent with approval-gated tools is followed by a pause check: `isInterrupted()`, `getReturn()->isInterrupted()`, or a `null` result with `pendingApprovals()`. Code meant for completed turns comes after it.
- No `interrupt:` argument remains, and no `InterruptRequest` is passed positionally to `chat()`, `stream()` or `structured()`. Every continuation is `submitApprovalDecisions($decisions)->run()` or `->events()`, and every pending action receives a decision or the new pause is handled.
- No reference to `NeuronAI\Workflow\Interrupt\ApprovalRequest`, `Action` or `ActionDecision` remains in any file type, and static analysis reports no unknown class under that namespace.
- No call to `getPendingActions(`, `getApprovedActions(`, `getRejectedActions(`, `getAction(`, `addAction(`, `setActions(`, `approve(`, `reject(`, `edit(`, `decision(`, `feedback(`, `isEdited(` or `fromArray(` on approval objects, no write to `->decision` or `->feedback`, and no `ActionDecision::Edit`.
- Resume endpoints address the run by the thread ID; no resume token or approval object is stored by the application.
- A new turn while an approval is pending follows what the developer decided (Case 6), and `catch` blocks for stale approvals catch `InputTranslationException`.
- Client code reads `actions` as an array.
- Tests cover a pause, a partial or rejected decision, and completion.
