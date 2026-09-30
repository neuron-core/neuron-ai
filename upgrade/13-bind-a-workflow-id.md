# Upgrade: Workflows, Agents and RAG are addressed by a workflow ID you bind

## Summary

In 3.x a `Workflow` (and `Agent` and `RAG`, which extend it) was built with `(persistence, resumeToken, state)`. Without a resume token it generated its own ID (`uniqid('workflow_')`). An Agent's conversation thread existed only as a chat-history constructor argument.

In 4.x the constructor takes only the workflow ID and the initial state, and persistence is set separately. The framework never generates an ID: you bind one before the instance runs. For `Agent` and `RAG`, the workflow ID is the conversation thread ID.

| 3.x | 4.x |
|---|---|
| `X::make($persistence, $resumeToken, $state)`, `new X(...)` with the same arguments | `X::make($workflowId, $state)->setPersistence($persistence)` |
| named `persistence:` / `resumeToken:` arguments | `->setPersistence($persistence)` / `workflowId:` |
| `setPersistence($persistence, $resumeToken)` | `setPersistence($persistence)->setWorkflowId($resumeToken)` |
| no persistence hook | `protected function persistence(): PersistenceInterface` (default: `InMemoryPersistence`; `setPersistence()` wins over it) |
| `getWorkflowId(): string`, generated when no token was given | `getWorkflowId(): ?string`, `null` until bound or declared by the class |
| `getResumeToken()` | `getWorkflowId()`; on Agent/RAG also `getThreadId(): ?string` |
| thread ID passed only to `new SQLChatHistory($threadId, ...)` etc. | `Agent::make(workflowId: $threadId)`, `setThreadId($threadId)` |
| `$state->get('__workflowId')` | `$state->getWorkflowId()` |
| new | `setWorkflowId(string): static`, `for(string): static` (bound copy), overridable `public function workflowId(): ?string` |

These calls throw on an instance that has no ID:
- Workflow: `run()`, `events()`, `inspect()`, `submitInputs()`, `abandon()`, `acknowledge()` throw `NeuronAI\Exceptions\WorkflowException`: `This workflow has no workflow ID: bind one with setWorkflowId() first.`
- Agent and RAG: the same calls, plus `chat()`, `stream()`, `structured()`, `getChatHistory()`, `resetConversation()`, `pendingApprovals()`, `submitApprovalDecisions()` and `submitToolResults()`, throw `NeuronAI\Exceptions\AgentException`: `This agent has no thread ID: bind one with setThreadId() first.` The 3.x README quick start `DataAnalystAgent::make()->chat(...)` hits this.

Data the app stored with 3.x:
- Workflow IDs and resume tokens the app stored are read as-is and stay usable as 4.x workflow IDs: the IDs 3.x generated always satisfy the ID rules in Case 4, and app-chosen tokens must be checked against them. Runs paused under 3.x cannot be resumed by 4.x at all: guide 14 covers draining them.
- Agent resume tokens are no longer used, because the thread ID addresses the paused run. Stop writing them. Do not drop the column or cache key yourself: tell the developer that it can be removed once no 3.x pause is pending.

## What to Search For

Run from the application root:

```bash
# 1. Classes built on Workflow, Agent or RAG. Also find the subclasses of every class listed here.
grep -rnE 'extends ([\\A-Za-z0-9_]*\\)?(Workflow|Agent|RAG)([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .

# 2. 3.x identity arguments and removed identity APIs
grep -rnE 'resumeToken|getResumeToken|__workflowId|persistence:|setPersistence[(]' --include='*.php' --exclude-dir=vendor .

# 3. Constructions, including those with a persistence argument or with no ID at all
grep -rnE '[A-Za-z0-9_]*(Workflow|Agent|RAG)::make[(]|new [A-Za-z0-9_\\]*(Workflow|Agent|RAG)[(]' --include='*.php' --exclude-dir=vendor .

# 4. Readers of the ID
grep -rnE 'getWorkflowId[(]|[$]this->workflowId([^A-Za-z0-9_(]|$)' --include='*.php' --exclude-dir=vendor .

# 5. Where 3.x agents kept the conversation thread
grep -rnE '(SQL|Eloquent|File|InMemory)ChatHistory[(]|function chatHistory[(]|setChatHistory[(]' --include='*.php' --exclude-dir=vendor .

# 6. Container definitions that pass persistence
grep -rnE 'resumeToken|Persistence' --include='*.yaml' --include='*.yml' --include='*.xml' --exclude-dir=vendor .

# 7. Re-runs that may reuse an ID (Case 7)
grep -rnE 'setStartEvent[(]' --include='*.php' --exclude-dir=vendor .
```

Then follow the hits:
- For every class from search 1, whatever its name, grep `ClassName::make(`, `new ClassName(` and the bare class name. The bare name finds container bindings (service providers, `app()->make()`, `$container->get()`) and autowired constructor parameters.
- For every construction, follow the instance to where it runs: controllers, queue jobs (and their retry settings), console commands, nodes that build a sub-agent, and tests.
- Check the constructor and the `@method static static make(...)` docblock of every class from search 1.

If searches 1 and 3 find nothing, this guide does not apply.

## How to Refactor

### Case 1: Construction arguments at call sites

`make()` forwards its arguments to the new constructor `(?string $workflowId = null, ?WorkflowState $state = null)`. An `Agent` takes an `?AgentState` as its state.

| 3.x call | 4.x call | If left as is |
|---|---|---|
| `X::make($persistence)`, `new X($persistence)` | `X::make()->setPersistence($persistence)`, then bind an ID (Case 4 or 5) | TypeError: `Argument #1 ($workflowId) must be of type ?string` |
| `X::make($persistence, $token)` | `X::make($token)->setPersistence($persistence)` | same TypeError |
| `X::make($persistence, $token, $state)` | `X::make($token, $state)->setPersistence($persistence)` | same TypeError |
| `X::make(null, null, $state)` | `X::make(state: $state)`, then bind an ID | the third argument is silently ignored and the initial state is lost |
| `make(persistence: $p, resumeToken: $t, state: $s)` | `make(workflowId: $t, state: $s)->setPersistence($p)` | `Unknown named parameter $persistence` |

Before (3.x):

```php
$workflow = OrderWorkflow::make($persistence, $token, $state);
$workflow = OrderWorkflow::make(persistence: $persistence, resumeToken: $token);
$workflow = new OrderWorkflow($persistence);
```

After (4.x):

```php
$workflow = OrderWorkflow::make($token, $state)->setPersistence($persistence);
$workflow = OrderWorkflow::make(workflowId: $token)->setPersistence($persistence);
$workflow = (new OrderWorkflow($workflowId))->setPersistence($persistence); // $workflowId: Case 4
```

For `Agent` and `RAG`, the `$token` of a 3.x resume endpoint is replaced by the thread ID (Case 5), not carried over.

In container definitions, move the persistence argument to a `setPersistence()` call (a Symfony `calls:` entry), and never bind an ID at registration (Case 4, shared definitions).

### Case 2: Subclass constructors

A subclass that forwards persistence or a token to `parent::__construct()` must forward only the ID and the state.

Before (3.x):

```php
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;

/**
 * @method static static make(Mailer $mailer, ?PersistenceInterface $persistence = null, ?string $resumeToken = null)
 */
class OrderWorkflow extends Workflow
{
    public function __construct(
        protected Mailer $mailer,
        ?PersistenceInterface $persistence = null,
        ?string $resumeToken = null,
    ) {
        parent::__construct($persistence, $resumeToken);
    }
}
```

After (4.x):

```php
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;

/**
 * @method static static make(Mailer $mailer, ?PersistenceInterface $persistence = null, ?string $workflowId = null)
 */
class OrderWorkflow extends Workflow
{
    public function __construct(
        protected Mailer $mailer,
        ?PersistenceInterface $persistence = null,
        ?string $workflowId = null,
    ) {
        parent::__construct($workflowId);

        if ($persistence !== null) {
            $this->setPersistence($persistence);
        }
    }
}
```

When the subclass always builds the same persistence, override the hook instead of calling the setter. Keep the backend's constructor arguments as they are: guide 14 migrates them.

Before (3.x):

```php
public function __construct(protected PDO $pdo, ?string $resumeToken = null)
{
    parent::__construct(new DatabasePersistence($pdo), $resumeToken);
}
```

After (4.x):

```php
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

public function __construct(protected PDO $pdo, ?string $workflowId = null)
{
    parent::__construct($workflowId);
}

protected function persistence(): PersistenceInterface
{
    return new DatabasePersistence($this->pdo);
}
```

Steps:
1. Rename the subclass's `$resumeToken` parameter to `$workflowId` and rename the `resumeToken:` named arguments its callers pass.
2. Move a 3.x third `$state` argument to the second position: `parent::__construct($workflowId, $state)`.
3. A subclass with its own constructor rejects `make(workflowId: ...)` with `Unknown named parameter $workflowId` unless it declares that parameter. Either declare and forward it, or bind at the call site with `setWorkflowId()` / `setThreadId()`.
4. Update the class's `@method static static make(...)` docblock to its real constructor parameters.

### Case 3: `setPersistence()` with a token

`setPersistence()` takes one argument. PHP silently drops a second one, so the instance stays unbound and the run throws.

Before (3.x):

```php
$workflow->setPersistence($persistence, $token);
```

After (4.x):

```php
$workflow->setPersistence($persistence)->setWorkflowId($token);
```

- An override of `setPersistence()` becomes `public function setPersistence(PersistenceInterface $persistence): static` and returns `parent::setPersistence($persistence)`. Move any token handling into `setWorkflowId()` calls at the call sites. The 3.x signature is fatal when the class loads.
- `WorkflowInterface` no longer declares `setPersistence()`. Where it is called on a value typed `WorkflowInterface`, retype the value to `Workflow`, `Agent` or `RAG`. To bind an ID on an interface-typed value, use `for($id)`, which `WorkflowInterface` and `AgentInterface` declare.

### Case 4: Bind an ID on every Workflow you run

An ID must be 1 to 255 characters long, contain no control characters and not start with `__`. Otherwise the run throws `WorkflowException: Invalid workflow ID: ...`. With `DatabasePersistence` or `EloquentPersistence`, the limit is 255 bytes. `setWorkflowId()` accepts the same ID again, but a different ID on a bound instance throws `WorkflowException: This workflow is bound to 'a' and cannot be re-pointed to 'b'.`

Choose the binding per call site. If the right ID is unclear, ask the developer whether two runs for the same entity can overlap or be paused at the same time. If they can, the runs need distinct IDs (Case 7).

(a) The run belongs to a business entity, or is continued later: use a stable key.

Before (3.x, `run()` from guide 12):

```php
$state = OrderWorkflow::make($persistence)->run();
```

After (4.x):

```php
$state = OrderWorkflow::make('order:' . $order->id)->setPersistence($persistence)->run();
```

(b) The class knows its own key: declare it. Binding a different ID on such an instance throws `WorkflowException`.

```php
use NeuronAI\Workflow\Workflow;

class OrderWorkflow extends Workflow
{
    public function __construct(protected int $orderId)
    {
        parent::__construct();
    }

    public function workflowId(): ?string
    {
        return 'order:' . $this->orderId;
    }
}

$state = OrderWorkflow::make($order->id)->setPersistence($persistence)->run();
```

(c) 3.x code relied on the generated ID (it read `getWorkflowId()` to store it or hand it to a client), or never read it: generate an ID before the run and bind it. This also covers one-off runs such as `Workflow::make()->addNodes([...])->run()`.

Before (3.x, `run()` from guide 12):

```php
$workflow = ReportWorkflow::make($persistence);
$report->workflow_id = $workflow->getWorkflowId();
$state = $workflow->run();
```

After (4.x):

```php
use NeuronAI\UniqueIdGenerator;

$workflow = ReportWorkflow::make(UniqueIdGenerator::generateId('workflow_'))->setPersistence($persistence);
$report->workflow_id = $workflow->getWorkflowId();
$state = $workflow->run();
```

(d) The instance is shared (a container singleton, a Symfony service, which is shared by default, a property of a long-lived worker) or serves several IDs: bind a copy at each call site. `for()` returns a bound copy and never modifies the receiver. Calling `setWorkflowId()` on a shared instance throws on the second ID.

```php
$state = $this->orderWorkflow->for('order:' . $order->id)->run();
```

(e) Tests:

Before (3.x):

```php
$workflow = Workflow::make(persistence: new InMemoryPersistence(), resumeToken: 'test-workflow');
```

After (4.x):

```php
$workflow = Workflow::make('test-workflow')->setPersistence(new InMemoryPersistence());
```

Resume sites keep their continuation call (the `init($request)`, `start($request)` or `resume($request)` left by guide 12) for guide 15: only the construction in front of it changes, as in Case 1.

### Case 5: Agents and RAG: bind the conversation thread

`RAG` extends `Agent`: everything here applies to both. `getThreadId(): ?string` and `setThreadId(string $threadId): static` read and bind the same ID as `getWorkflowId()` and `setWorkflowId()`. Leave every chat-history construction, `setChatHistory()` call and `chatHistory()` hook exactly as it is: guide 31 replaces them.

1. Find the thread each construction's 3.x history received (search 5). Cast a non-string value with `(string)`.

   | 3.x history | The thread ID is |
   |---|---|
   | `new SQLChatHistory($threadId, $pdo, ...)` | argument 1 (`thread_id:`) |
   | `new EloquentChatHistory($threadId, $modelClass, ...)` | argument 1 (`threadId:`) |
   | `new FileChatHistory($directory, $key, ...)` | argument 2 (`key:`) |
   | `new InMemoryChatHistory()`, or no history | none: see step 3 |

2. Bind that value on the agent:
   - A class without its own constructor: `SupportAgent::make(workflowId: $threadId)`.
   - A class with its own constructor that receives the thread: pass it on with `parent::__construct($threadId)`. Otherwise bind at the call site with `->setThreadId($threadId)`.
   - A `chatHistory()` hook that computed the thread from app state: return the same expression from `public function workflowId(): ?string`.
   - A shared instance (a container singleton, a Symfony service, an Octane or queue-worker lifetime): `$agent->for($threadId)->chat(...)`. Never `setThreadId()`, which throws on the second thread.

   Before (3.x):

   ```php
   $agent = SupportAgent::make()
       ->setChatHistory(new SQLChatHistory($threadId, $pdo));
   ```

   After (4.x). Only `make()` changes; the `->setChatHistory(new SQLChatHistory($threadId, $pdo))` line stays chained after it, unchanged:

   ```php
   $agent = SupportAgent::make(workflowId: $threadId);
   ```

   Before (3.x):

   ```php
   use NeuronAI\Agent\Agent;
   use NeuronAI\Chat\History\ChatHistoryInterface;
   use NeuronAI\Chat\History\SQLChatHistory;

   class SupportAgent extends Agent
   {
       public function __construct(protected string $threadId, protected PDO $pdo)
       {
           parent::__construct();
       }

       protected function chatHistory(): ChatHistoryInterface
       {
           return new SQLChatHistory($this->threadId, $this->pdo);
       }
   }
   ```

   After (4.x). The `chatHistory()` hook and its imports stay as they are:

   ```php
   use NeuronAI\Agent\Agent;

   class SupportAgent extends Agent
   {
       public function __construct(protected string $threadId, protected PDO $pdo)
       {
           parent::__construct($threadId);
       }

       // chatHistory() unchanged
   }
   ```

3. Agents with an in-memory history or no history ran as a new, anonymous conversation in 3.x. Bind a generated ID to keep that behaviour: one conversation per agent instance, as in 3.x. This covers the README quick start and agents built inside a node or a job.

   Before (3.x):

   ```php
   $agent = DataAnalystAgent::make();
   $response = $agent->chat(new UserMessage("Hi, I'm Valerio. Who are you?"))->getMessage();
   ```

   After (4.x):

   ```php
   use NeuronAI\UniqueIdGenerator;

   $agent = DataAnalystAgent::make(workflowId: UniqueIdGenerator::generateId('thread_'));
   $response = $agent->chat(new UserMessage("Hi, I'm Valerio. Who are you?"))->getMessage();
   ```

   If such an agent is resumed later (3.x built it with a persistence and a resume token), generate the thread ID when the conversation starts. Store or send it wherever 3.x stored the token.

4. Resume endpoints: the thread ID replaces the resume token.

   Before (3.x):

   ```php
   $agent = SupportAgent::make($persistence, $resumeToken);
   ```

   After (4.x):

   ```php
   $agent = SupportAgent::make(workflowId: $threadId)->setPersistence($persistence);
   ```

   Take `$threadId` from the conversation the request belongs to. Leave the continuation call (`chat(interrupt: ...)`, `stream(interrupt: ...)`) for guide 29.

App-declared `getThreadId()`, `setThreadId()`, `workflowId()` or `setWorkflowId()` methods and a redeclared `$workflowId` property now collide with framework members: guide 57 resolves them. Here, only bind the value they carried. Classes that implement `WorkflowInterface` or `AgentInterface` directly: guide 57.

### Case 6: Code that reads the old identity

| 3.x | 4.x |
|---|---|
| `$workflow->getResumeToken()` | `$workflow->getWorkflowId()` (`$agent->getThreadId()` on agents) |
| `$state->get('__workflowId')` in nodes and middleware | `$state->getWorkflowId()` |
| `$this->workflowId` read in a subclass | `$this->getWorkflowId()` (the property stays `null` when the class declares `workflowId()`) |
| `$this->workflowId = $id` in a subclass | `$this->setWorkflowId($id)` |

- `getWorkflowId()` and `getThreadId()` return `?string`. Where the result feeds a `string` parameter or property, bind the ID first (Cases 4 and 5), or handle `null`.
- `$interrupt->getWorkflowId()` / `getResumeToken()` inside a `catch (WorkflowInterrupt ...)` block: leave the block alone. Guide 15 (workflows) and guide 29 (agents) replace it and read the ID from the returned state. The ID is the one you bound in this guide. The same goes for a client that reads the `resumeToken` key of a JSON-encoded interrupt: guides 15 and 29 rebuild that payload.

### Case 7: Reusing an ID

One workflow ID holds one live run at a time. Starting a new run (a plain `run()` or `events()`, `run(ExecutionRequest::start(...))`, or an Agent's `chat()`, `stream()` or `structured()`) on an ID whose run is paused, or still executing in another process, throws `NeuronAI\Exceptions\RunInFlightException` (a `WorkflowException`). 3.x silently started a new run. A completed run frees the ID. For agents this covers every turn of a thread, because they all share the thread ID.

- Give independent runs distinct IDs.
- A run whose process was killed before it could record the failure (timeout, OOM kill, container restart) stays marked running; 3.x left nothing behind. With durable persistence, a plain Workflow's ID is then refused with `RunInFlightException` (`$e->status === WorkflowStatus::Running`) until `$workflow->run(ExecutionRequest::resume())` takes the run over or `$workflow->abandon()` discards it. An Agent's thread is refused for up to 600 seconds, its default lease. For plain workflows with durable persistence and reused IDs, ask the developer which they want: a lease (`->setLeaseTimeout($seconds)`, or `protected function leaseTimeout(): ?int`, set well above the slowest node) so that a later start replaces a dead run, or handling of the `Running` case at the entry point. Report the Agent's 600-second default to the developer.
- Where a start may hit a paused or busy ID (a second trigger for the same business key, two concurrent turns on one thread), ask the developer what should happen: answer the pending workflow run (guide 15), discard a paused workflow run with `$workflow->abandon()`, or refuse the request. A new agent turn while a tool approval is pending is handled by guide 29; leave it alone here.

  ```php
  use NeuronAI\Exceptions\RunInFlightException;

  try {
      $state = $this->orderWorkflow->for('order:' . $order->id)->run();
  } catch (RunInFlightException $e) {
      // $e->status is the held run's WorkflowStatus; $e->interrupt is its pending request, if paused
      throw new OrderBusyException($order->id, previous: $e);
  }
  ```

- A plain `run()` or `events()` on an ID whose last run failed resumes that run from its last completed step, with its original start event: a newer `setStartEvent()` is ignored. This happens when the failed run is still in persistence: with a durable backend, or on the same instance, because the default `InMemoryPersistence` belongs to the instance. Where a retry must start over, start it explicitly. An Agent's `chat()`, `stream()` and `structured()` always start a new run.

  Before (3.x, `run()` from guide 12):

  ```php
  $workflow->setStartEvent(new ImportEvent($fixedFile));
  $state = $workflow->run();
  ```

  After (4.x):

  ```php
  use NeuronAI\Workflow\Executor\ExecutionRequest;

  $state = $workflow->run(ExecutionRequest::start(new ImportEvent($fixedFile)));
  ```

  `ExecutionRequest::start()` with no argument uses the workflow's default start event. Calling `$workflow->abandon()` before a plain `run()` also starts over.

## Checklist

- Search 2 finds no `resumeToken:` named argument, no `getResumeToken()` on a Workflow, Agent or RAG, no `__workflowId`, and no `persistence:` argument aimed at the Neuron constructor. Every `setPersistence(` call has exactly one argument. These hits stay on purpose: `getWorkflowId()`/`getResumeToken()` on a caught `WorkflowInterrupt`, and `resumeToken` keys of payloads exchanged with clients (guides 15 and 29 migrate them). App variable and request-field names such as `$resumeToken` or `$request->input('resumeToken')` need no rename. A `persistence:`/`$persistence` argument also stays for an app subclass whose own constructor declares it (Case 2).
- No persistence object reaches `parent::__construct()` or the constructor of a class that does not declare its own `$persistence` parameter. State passed positionally to the Neuron constructor is the second argument.
- Subclass constructors call `parent::__construct($workflowId[, $state])`, and their `@method static static make(...)` docblocks match.
- Every construction binds an ID before it runs, streams, chats, inspects, submits, abandons or reads the chat history: `make($id)` / `make(workflowId: $id)`, `setWorkflowId()`, `setThreadId()`, `for()`, or a declared `workflowId()`. Check controllers, jobs, commands, nodes and tests.
- Shared instances are bound with `for()`, never with `setWorkflowId()` or `setThreadId()`.
- Each Agent/RAG thread ID is the thread its 3.x chat history used, and the history code itself is untouched (guide 31).
- IDs are 1 to 255 characters long (255 bytes with SQL or Eloquent persistence), contain no control characters and do not start with `__`.
- Independent runs use distinct IDs. Entry points that may start on a paused or busy ID follow what the developer decided. Retries that must start over use `ExecutionRequest::start(...)` or `abandon()`.
- Running the application or its tests no longer raises `This workflow has no workflow ID` or `This agent has no thread ID`.
