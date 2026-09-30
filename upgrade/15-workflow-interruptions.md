# Upgrade: Workflow interruptions: pauses return state, resumes deliver answers

## Summary

A paused workflow no longer throws. `run()` returns the suspended state, and `events()` ends with an `InterruptEvent`. The caller answers with a JSON-compatible array instead of passing a modified request object back, and inside the node `interrupt()` and `interruptIf()` return that array.

| 3.x | 4.x |
|---|---|
| `run()`/`events()` throw `WorkflowInterrupt`; the `catch` reads `getRequest()`, `getWorkflowId()`, `getState()` | `run()` returns the state: `isInterrupted()`, `getInterruptRequest()`, `getWorkflowId()`. `events()` yields a last `InterruptEvent` and returns the state. `WorkflowInterrupt` never reaches the caller |
| Answer by modifying the request (`$action->approve()`, public fields) and passing it to `init($request)`, `start($request)` or `resume($request)` | `$workflow->submitInputs($answer)->run()` or `->events()`, where `$answer` is a JSON-compatible array |
| `interrupt()`/`interruptIf()` return the request the caller passed back | They return the answer array (`?array`) |
| `$this->resumeRequest`, `consumeResumeRequest()`, `getResumeRequest()`; `isResuming()` public on `NodeInterface` | Removed; `isResuming()` is protected on `Node` |
| On a resume, the first wait the node reaches takes the answer | Each wait keeps its own answer; waits already passed return their recorded answer |
| `ApprovalRequest`, `Action`, `ActionDecision` in `NeuronAI\Workflow\Interrupt`, with `addAction()`, `getAction()`, `approve()`, `reject()`, `edit()`, `fromArray()` | In `NeuronAI\Agent\Interrupt`, outbound only: actions go to the constructor, decisions travel in the answer array |
| Custom request: `extends InterruptRequest`, `parent::__construct($message)`, `jsonSerialize()` | `extends WaitForEventRequest`, `parent::__construct('<event name>')`, extra JSON in `metadata()` |

Not in this guide:
- Pauses and resumes of an Agent or RAG (a `try` around `$agent->chat()`, `stream()` or `structured()`, `chat(interrupt: ...)`, approval requests an agent produced): guide 29.
- Stream adapters and the frames they send for a pause: guides 35 to 37.
- Custom executors (classes extending `WorkflowExecutor` or `AsyncExecutor`, or implementing `WorkflowExecutorInterface`) and `BranchInterrupt`: guide 17. Leave their `WorkflowInterrupt` code in place.
- The observability event `WorkflowInterrupted` and observers that read `$data->interrupt`: guide 46. (Search 1 matches it too.)

## Stored data

- 4.x cannot resume runs paused under 3.x. Guide 14 covers draining them before the deploy.
- `WorkflowInterrupt` or `InterruptRequest` objects that the application serialized into its own storage (cache, session, its own table) cannot be read by 4.x. Stop writing and reading them (Case 1). Do not delete the stored entries yourself: tell the developer they can be purged once no 3.x pause is pending.

## What to Search For

```bash
# Caller side: pause handling and resumes
grep -rnE --include='*.php' --exclude-dir=vendor -e 'WorkflowInterrupt' -e '->(init|start|resume)\(' .
# Node side
grep -rnE --include='*.php' --exclude-dir=vendor -e '->(interrupt|interruptIf|checkpoint|consumeResumeRequest|getResumeRequest|isResuming)\(' -e '->(resumeRequest|checkpoints)\b' -e 'function (isResuming|getResumeRequest|interrupt|interruptIf)\(' -e 'setWorkflowContext\(' .
# Approval request API and custom requests
grep -rnE --include='*.php' --exclude-dir=vendor -e 'Workflow\\Interrupt\\' -e '->(addAction|getAction|setActions|getPendingActions|getApprovedActions|getRejectedActions|approve|reject|edit|isEdited)\(' -e 'ActionDecision::Edit' -e '(ApprovalRequest|Action)::fromArray\(' -e '->(decision|feedback)\b' -e 'extends (InterruptRequest|ApprovalRequest)\b' .
```

Follow the hits:
- `->init(`, `->start(`, `->resume(`: keep the calls on a Workflow. Guide 12 left two kinds. Calls that carry a request are resumes: migrate them here, and trace each request back to where it was obtained and modified. An argument-less `init()`/`start()` whose handler streams through an adapter (`->events($adapter)`, `->streamEvents($adapter)`) stays for guide 36; only its `catch` block is handled here (Case 2).
- `interrupt(` / `interruptIf(`: open every Node subclass that calls them, and every helper or service that receives their return value.
- Hits that belong to an Agent or RAG (reached from `$agent->chat()`, `stream()`, `structured()`, or an approval request an agent produced): leave them for guide 29.
- `setWorkflowContext(`: only calls with a third argument (a test simulating a resume) belong here. Guide 16 handles the rest.
- Imports and type hints of `NeuronAI\Workflow\Interrupt\InterruptRequest` stay valid, and so does `WaitForEventRequest` once written; only 3.x subclasses change (Case 6).
- Client code in the repository (JavaScript, TypeScript, templates) that reads `resumeToken` or JSON-decodes `actions`: Case 1, step 5.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: A `catch (WorkflowInterrupt ...)` around a workflow run

Before (3.x, after guide 12):
```php
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;

try {
    $state = $workflow->run();
    return ['status' => 'completed', 'result' => $state->get('result')];
} catch (WorkflowInterrupt $interrupt) {
    return [
        'status' => 'paused',
        'workflowId' => $interrupt->getWorkflowId(),
        'request' => $interrupt->getRequest(),
    ];
}
```

After:
```php
$state = $workflow->run();

if ($state->isInterrupted()) {
    return [
        'status' => 'paused',
        'workflowId' => $state->getWorkflowId(),
        'request' => $state->getInterruptRequest(),
    ];
}

return ['status' => 'completed', 'result' => $state->get('result')];
```

1. Move the `catch` body after the call, under `if ($state->isInterrupted())`. A pause now returns normally, so code that 3.x ran after `run()` only on completion must come after that check, or it also runs on a paused workflow.
2. Replace the getters:

   | 3.x `WorkflowInterrupt` | 4.x |
   |---|---|
   | `getRequest()` | `$state->getInterruptRequest()` |
   | `getState()` | `$state` |
   | `getWorkflowId()`, `getResumeToken()` | `$state->getWorkflowId()`, the ID the application bound (guide 13) |
   | `getMessage()` | `$state->getInterruptRequest()?->getMessage()` |
   | `getNode()`, `getEvent()`, `getBranchId()`, `getParallelEvent()`, `getCompletedBranchResults()`, `isParallelInterrupt()` | No replacement: delete that code. If the caller must tell requests apart, put that information in the request (Case 6) |
   | `json_encode($interrupt)`, `jsonSerialize()`, `serialize($interrupt)` | Send `['workflowId' => $state->getWorkflowId(), 'request' => $state->getInterruptRequest()]`. Do not store the request yourself: persistence keeps it, and `$workflow->inspect()?->interrupt` reads it later |

3. Delete `instanceof WorkflowInterrupt` branches in `catch (WorkflowException $e)` or `catch (Throwable $e)` blocks, and `catch (WorkflowInterrupt $e) { throw $e; }` blocks that kept pauses away from a generic handler. Put the `isInterrupted()` check after the call instead.
4. Remove `@throws WorkflowInterrupt` tags and the `use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;` import.
5. The request JSON sent to clients changed:
   - 3.x: whatever the request's `jsonSerialize()` returned; for `ApprovalRequest`, `{"message": ..., "actions": "<JSON string>"}`.
   - 4.x `ApprovalRequest`: `{"interruptId": 1, "type": "wait_for_event", "eventName": "approval", "expiresAt": null, "message": ..., "actions": [{"id", "name", "description", "decision", "feedback", "reason", "inputs"}]}`.
   - 4.x custom requests: `interruptId`, `type`, `eventName`, `expiresAt`, then the `metadata()` fields (Case 6).

   Update client code in the repository that reads `resumeToken` or the PHP-serialized `request` of `json_encode($interrupt)`, or that JSON-decodes `actions`. If the client lives outside the repository, report the new shape to the developer.
6. Parallel branches pause one at a time. After an answer (Case 3) the returned state can be interrupted again by another branch: present each new request the same way until `isInterrupted()` is false.

### Case 2: Streaming loops

Before (3.x, after guide 12):
```php
try {
    foreach ($workflow->events() as $event) {
        $renderer->render($event);
    }
} catch (WorkflowInterrupt $interrupt) {
    $renderer->askApproval($interrupt->getWorkflowId(), $interrupt->getRequest());
}
```

After:
```php
use NeuronAI\Workflow\Events\InterruptEvent;

$stream = $workflow->events();
foreach ($stream as $event) {
    if ($event instanceof InterruptEvent) {
        continue; // the pause: handled after the loop
    }
    $renderer->render($event);
}

$state = $stream->getReturn();
if ($state->isInterrupted()) {
    $renderer->askApproval($state->getWorkflowId(), $state->getInterruptRequest());
}
```

- The last item of a paused stream is an `InterruptEvent` (`$event->request` is the request). Keep it away from code that expects node output.
- A stream through an adapter (`->events($adapter)` or `->streamEvents($adapter)`, left in place by guide 12): delete the `catch` block and move what it did after the loop under `if ($stream->getReturn()->isInterrupted())`, with `$stream` assigned to the generator the loop iterates. Delete pause frames written for a built-in adapter (`VercelAIAdapter`, `AGUIAdapter`): 4.x adapters send their own (guide 37). Keep a pause frame written for an application adapter under that check: guide 35 moves it into the adapter's `interrupt()`. Guide 36 converts the adapter call itself.

### Case 3: Resuming with `init($request)`, `start($request)` or `resume($request)`

Before (3.x):
```php
// $request is the ApprovalRequest the pause carried
$request->getAction('publish')->approve();
// or: $request->getAction('publish')->reject('Too long');

$state = $workflow->init($request)->run();
```

After:
```php
$state = $workflow->submitInputs(['publish' => 'approve'])->run();
// or: $workflow->submitInputs(['publish' => ['reject', 'Too long']])->run();

if ($state->isInterrupted()) {
    // paused again: handle it as in Case 1
}
```

1. `$workflow` must be bound to the paused run's workflow ID, with the same persistence, nodes and middleware as the run that paused (guide 13; a new process rebuilds it the same way).
2. Turn what the caller wrote on the request into an answer array, and delete the modifications (`approve()`, `reject()`, `edit()`, `decision()`, `feedback()`, property writes):
   - `ApprovalRequest`: use the shape the class documents, keyed by action ID: `'approve'`, `'reject'` or `['reject', $feedback]`. Feedback given with an approval, and a 3.x `edit()`, have no slot in that shape: send `['approve', $feedback]` or `['edit', $feedback]` and read them the same way in the node (Case 4).
   - Custom requests: the fields the caller set become keys (`$request->code = $code` becomes `['code' => $code]`, Case 6).
   - The keys the caller sends are exactly the keys the node reads.
3. Send only arrays, scalars and null. 4.x does not reject objects that `json_encode()` can encode: the node would receive them as sent. Convert DTOs and request objects to arrays before calling `submitInputs()`.
4. Replace the call:
   - `->init($request)->run()`, `->start($request)->run()` → `->submitInputs($answer)->run()`.
   - Iterating `->init($request)->events()` or `->resume($request)` → iterate `->submitInputs($answer)->events()`, as in Case 2.
   - With an adapter, `->init($request)->events($adapter)` → `->submitInputs($answer)->events($adapter)`. Leave the adapter argument in place: guide 36 moves it.
   - `init($interrupt->getRequest())` with the request unchanged (the node ignored its content) → `submitInputs([])`.
5. A client that posted the request back with decisions filled in, rebuilt with `ApprovalRequest::fromArray()`: build the answer from the posted actions instead. `approved` becomes `'approve'`, `rejected` becomes `'reject'`, `edit` becomes `'edit'`, each as `[<decision>, $feedback]` when feedback is set, and `pending` actions are left out (the node reads a missing key as pending). Then update the client for the 4.x JSON (Case 1, step 5).
6. Do not continue with a plain `run()`: on a paused workflow ID it throws `RunInFlightException`. `$workflow->run(ExecutionRequest::resume($answer))` (`NeuronAI\Workflow\Executor\ExecutionRequest`) delivers the same answer, but throws `WorkflowException` instead of `InputTranslationException` when nothing is waiting (step 7). `ExecutionRequest::resume()` without an answer does not answer the wait.
7. `submitInputs()` throws `InputTranslationException` when nothing is waiting under the workflow ID (never paused, already completed, or abandoned). Handle it where the 3.x code handled a missing interrupt.

### Case 4: The node reading the answer

Before (3.x):
```php
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;

class PublishNode extends Node
{
    public function __invoke(DraftReady $event, WorkflowState $state): Published|Discarded
    {
        $request = $this->interrupt(new ApprovalRequest('Publish the draft?', [
            new Action('publish', 'Publish', 'Publish the reviewed draft'),
        ]));

        $action = $request->getAction('publish');
        if ($action->isApproved()) {
            return new Published();
        }

        $state->set('feedback', $action->feedback);
        return new Discarded();
    }
}
```

After:
```php
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ApprovalRequest;

class PublishNode extends Node
{
    public function __invoke(DraftReady $event, WorkflowState $state): Published|Discarded
    {
        $answer = $this->interrupt(new ApprovalRequest('Publish the draft?', [
            new Action('publish', 'Publish', 'Publish the reviewed draft'),
        ]));

        [$decision, $feedback] = array_pad((array) ($answer['publish'] ?? null), 2, null);
        if ($decision === 'approve') {
            return new Published();
        }

        $state->set('feedback', $feedback);
        return new Discarded();
    }
}
```

1. `interrupt()` and `interruptIf()` return the answer array from Case 3: `[]` for an empty answer, `null` only when `interruptIf()`'s condition was false. Replace every method call or property read on the returned value with key reads. Retype `@var` tags and parameters that held the returned request (in the node, its helpers, and services it passes the value to) to `array<string, mixed>|null`.
2. Map decision reads to the answer. Read an action's entry with `[$decision, $feedback] = array_pad((array) ($answer[$id] ?? null), 2, null);`, which accepts both `'approve'` and `['reject', $feedback]`, then:
   - `getAction($id)->isApproved()`, `$action->decision === ActionDecision::Approved` → `$decision === 'approve'`; `isRejected()` → `$decision === 'reject'`; `isPending()` → `$decision === null`.
   - `$action->feedback` → `$feedback`.
   - `isEdited()`, `ActionDecision::Edit` → `$decision === 'edit'`, if the caller sends it (Case 3, step 2).
   - Loops over `getActions()`, `getApprovedActions()` or `getRejectedActions()` of the returned request: keep the request in a variable and loop over the actions the node declared: `$request = new ApprovalRequest(...); $answer = $this->interrupt($request); foreach ($request->getActions() as $action) { [$decision, $feedback] = array_pad((array) ($answer[$action->id] ?? null), 2, null); ... }`. Do not loop over `$answer`: its keys come from the caller.
3. Rewrite the imports of `ApprovalRequest`, `Action` and `ActionDecision` from `NeuronAI\Workflow\Interrupt` to `NeuronAI\Agent\Interrupt`, in nodes and in the code that runs and resumes these workflows. Remove imports left unused.
4. Pass every action to the `ApprovalRequest` constructor: `addAction()` and `setActions()` are gone. Action IDs must be unique within a request: 4.x throws `WorkflowException` on a duplicate, where 3.x kept the last action with that ID.
5. Code that renders the pending request keeps `getMessage()` and `getActions()`. Replace `getAction($id)` with a search over `getActions()`, and `getPendingActions()` with `getActions()`: every action of a pending request is pending. `getActions()` returns a list, while `getPendingActions()` was keyed by action ID: where a loop uses the key (`as $id => $action`), read `$action->id` instead.
6. `$this->consumeResumeRequest()`, `$this->resumeRequest` and `$this->getResumeRequest()` → the value returned by `interrupt()`/`interruptIf()`. `$this->isResuming()` inside a `Node` subclass stays as it is.
7. An application base node that overrides `interrupt()` or `interruptIf()` declares the 4.x return type and returns the parent's value unchanged: `protected function interrupt(InterruptRequest $request): ?array { /* ... */ return parent::interrupt($request); }`.

### Case 5: Nodes that wait more than once

Review every node where one execution can reach two or more `interrupt()`/`interruptIf()` calls, or one call inside a loop.

1. Re-asking by waiting again and ignoring the return value. 3.x gave the next answer to the first wait. 4.x returns the recorded first answer there, and gives the new answer to the second wait, whose return is discarded: unchanged code completes silently with the rejected first answer. Read every answer where it arrives.

   Before (3.x):
   ```php
   $request = $this->interrupt(new CodeRequest('Enter the code'));
   if (!$this->isValid($request->code)) {
       $this->interrupt(new CodeRequest('Invalid code, try again'));
   }

   return new CodeVerified($request->code);
   ```

   After (`CodeRequest` as in Case 6):
   ```php
   $message = 'Enter the code';
   do {
       $answer = $this->interrupt(new CodeRequest($message));
       $message = 'Invalid code, try again';
   } while (!$this->isValid($answer['code'] ?? null));

   return new CodeVerified($answer['code']);
   ```
2. The node must reach its waits in the same order every time it runs. Every `interruptIf()` condition, and every `if` or loop that decides whether a wait is reached, may depend only on the event, the state, earlier answers or a memoized value. Wrap clock, random or live lookups: `$late = $this->memoize('late', fn (): bool => $this->clock->now() > $deadline);`. Otherwise a resumed node throws `WorkflowException: A node must reach its waits in the same order every time it runs...`.
3. An `interruptIf()` whose condition is false returns `null` and never takes an answer (3.x handed it the pending answer). Read the answer from the wait that paused.
4. `checkpoint()` still works: it is deprecated, and `memoize()` takes the same arguments if you rename it. Its value is now persisted and returned every time the node runs, where 3.x returned it once, so use distinct names where a second call expected a fresh value. Reads of the 3.x `$this->checkpoints` array → `$this->recallMemo('<name>')`.

### Case 6: Custom request classes

Before (3.x):
```php
use NeuronAI\Workflow\Interrupt\InterruptRequest;

class CodeRequest extends InterruptRequest
{
    public ?string $code = null; // set by the caller before init($request)

    public function jsonSerialize(): array
    {
        return ['message' => $this->message];
    }
}
```

After:
```php
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;

class CodeRequest extends WaitForEventRequest
{
    public function __construct(protected string $message)
    {
        parent::__construct('code');
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    protected function metadata(): array
    {
        return ['message' => $this->message];
    }
}
```

1. Extend `NeuronAI\Workflow\Interrupt\WaitForEventRequest`: every 3.x request was answered by the caller. The 4.x base `InterruptRequest` declares abstract `type()`, `getMessage()`, `validate()` and `coordinationData()`; `WaitForEventRequest` implements all but `getMessage()`.
2. Declare a constructor even where the 3.x class inherited `__construct($message)`, and call `parent::__construct('<event name>')` with a stable name for this kind of wait. Without your own constructor, `new CodeRequest('Enter the code')` silently uses the message as the event name.
3. Implement `getMessage()` to keep the 3.x message: the one `WaitForEventRequest` provides returns `Waiting for event '<name>'`.
4. Rename the `jsonSerialize()` override (now final) to `protected function metadata(): array`, returning only the extra fields. `interruptId`, `type`, `eventName` and `expiresAt` keep the framework's values.
5. Rename members of the 3.x class whose names the 4.x base now uses, and update their callers: the `$id` property, the methods `getId()`, `withId()`, `type()`, `validate()`, `coordinationData()`, `getEventName()` and `getExpiresAt()`, and the properties `$eventName` and `$expiresAt`.
5. Fields the caller filled in to answer (`$code` above) leave the request. The caller sends them in the answer (`submitInputs(['code' => $code])`), and the node reads `$answer['code']`.
6. `extends ApprovalRequest`: import `NeuronAI\Agent\Interrupt\ApprovalRequest`, pass the actions to `parent::__construct($message, $actions)`, and move a `jsonSerialize()` override to `protected function metadata(): array { return parent::metadata() + [/* extra fields */]; }`.

### Case 7: Resume state read outside the node

1. `$node->isResuming()` or `$node->getResumeRequest()` in a `WorkflowMiddleware`, or anywhere outside the node: `isResuming()` is protected, `getResumeRequest()` is removed, and middleware never sees the answer. Move the logic into the node that pauses, working on the array `interrupt()` returns. Give the node the service the middleware used through its constructor (guide 18 offers `WorkflowResources` as the alternative). Delete the middleware once it is empty, together with its registration (`addMiddleware()`, `addGlobalMiddleware()`, `middleware()`, `globalMiddleware()`).

   Before (3.x):
   ```php
   class AuditDecisions implements WorkflowMiddleware
   {
       public function __construct(protected AuditLog $audit) {}

       public function before(NodeInterface $node, Event $event, WorkflowState $state): void
       {
           if ($node->isResuming() && $node->getResumeRequest() instanceof ApprovalRequest) {
               $this->audit->record($node->getResumeRequest());
           }
       }

       public function after(NodeInterface $node, Event $result, WorkflowState $state): void {}
   }
   ```

   After (the middleware is deleted; the node from Case 4 records the answer):
   ```php
   class PublishNode extends Node
   {
       public function __construct(protected AuditLog $audit) {}

       public function __invoke(DraftReady $event, WorkflowState $state): Published|Discarded
       {
           $answer = $this->interrupt(new ApprovalRequest('Publish the draft?', [
               new Action('publish', 'Publish', 'Publish the reviewed draft'),
           ]));
           $this->audit->record($answer); // the answer array, e.g. ['publish' => 'approve']

           return ($answer['publish'] ?? null) === 'approve' ? new Published() : new Discarded();
       }
   }
   ```
   Adapt `record()` to take the array instead of an `ApprovalRequest`.
2. A middleware that paused the run itself (`throw new WorkflowInterrupt($request, $node, $state, $event)` in `before()`): that constructor no longer exists, and the middleware could not receive the answer anyway. Move the pause into the node: `$answer = $this->interrupt($request);`.
3. A middleware on an Agent's `ToolNode` that read tool-approval decisions through `getResumeRequest()`: delete that code, because the `ToolNode` applies the decisions itself (guide 28). If it recorded the decisions, carry that over when applying guide 29: record `$decisions` where guide 29 writes the `submitApprovalDecisions($decisions)` call, or read `ToolCall::getApprovalState()` and `getRejectReason()` on the calls of the conversation's `ToolCallMessage`. Guide 27 migrates the rest of such a middleware.
4. A class implementing `NodeInterface` directly: delete its `isResuming()` and `getResumeRequest()` methods, which the interface no longer declares. Guide 16 migrates its `setWorkflowContext()`.

### Case 8: Tests

Before (3.x, after guide 12):
```php
try {
    $workflow->run();
    $this->fail('Expected WorkflowInterrupt exception');
} catch (WorkflowInterrupt $e) {
    $interrupt = $e;
}
$this->assertSame('human input needed', $interrupt->getRequest()->getMessage());

$finalState = $workflow->init($interrupt->getRequest())->run();
$this->assertTrue($finalState->get('interruptable_node_executed'));
```

After:
```php
$state = $workflow->run();
$this->assertTrue($state->isInterrupted());
$this->assertSame('human input needed', $state->getInterruptRequest()?->getMessage());

$finalState = $workflow->submitInputs([])->run(); // or the answer the node reads (Case 3)
$this->assertFalse($finalState->isInterrupted());
$this->assertTrue($finalState->get('interruptable_node_executed'));
```

- Replace `expectException(WorkflowInterrupt::class)` around a workflow run with the `isInterrupted()` assertion. For nodes that wait more than once, assert the second pause too.
- A test that simulated a resume on a node alone passes the answer array through a `NodeContext`:

  Before (3.x):
  ```php
  $request = new ApprovalRequest('Publish the draft?', [new Action('publish', 'Publish')]);
  $request->getAction('publish')->approve();
  $node->setWorkflowContext($state, $event, $request);
  $result = $node($event, $state);
  ```

  After:
  ```php
  use NeuronAI\Workflow\NodeContext;

  $node->setWorkflowContext(new NodeContext(payload: ['publish' => 'approve']));
  $result = $node($event, $state);
  ```
- A node invoked alone without that context still throws `NeuronAI\Workflow\Interrupt\WorkflowInterrupt` from `interrupt()`. The class is internal, and only `getRequest()` remains on it: prefer running the node inside a workflow and asserting `isInterrupted()`.

## Checklist

- No `catch (WorkflowInterrupt`, `instanceof WorkflowInterrupt` or `new WorkflowInterrupt(` remains around workflow runs, in workflow nodes or in workflow middleware. The only remaining hits are around Agent/RAG calls (guide 29), in custom executors (guide 17), on the `WorkflowInterrupted` event (guide 46), and in unit tests that invoke a node alone.
- Every `run()`/`events()` of a workflow whose nodes can pause is followed by an `isInterrupted()` check, and code meant for completed runs comes after it.
- No `init(`, `start(` or `resume(` call that carries a request remains on a workflow. An argument-less `init()`/`start()` that feeds an adapter stream remains for guide 36. Every resume is `submitInputs($answer)->run()` or `->events()` (or `run(ExecutionRequest::resume($answer))`) with a JSON-compatible array.
- Every value returned by `interrupt()`/`interruptIf()` is read as an array, and the keys each node reads match the keys its resuming caller sends.
- No `consumeResumeRequest(`, `getResumeRequest(`, `->resumeRequest` or `->checkpoints` remains, and `isResuming()` is only called on `$this` inside Node subclasses.
- Workflow code no longer references `NeuronAI\Workflow\Interrupt\ApprovalRequest`, `Action` or `ActionDecision`, or calls `addAction(`, `getAction(`, `setActions(`, `approve(`, `reject(`, `edit(` or `fromArray(` on approval requests. Agent-side hits are left for guide 29.
- Custom requests extend `WaitForEventRequest`, declare their own constructor, implement `getMessage()`, and put extra JSON in `metadata()`.
- Nodes that wait more than once read each wait's answer and reach their waits in a deterministic order.
- Tests cover pause, resume and completion, including a second pause.
