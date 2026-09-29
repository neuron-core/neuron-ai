# Upgrade: Agent-owned thread identity & thread binding

## Summary

Thread identity moves onto the **Agent** (a nullable slot assigned exactly
once, mirroring how the engine assigns the runId), and chat histories become
**bound, not identity-constructed**: a history is constructible without its
thread, loads lazily, and the framework injects the resolved identity into it
before first use. Identity never appears in wiring code.

What changed:

1. **One constructor identity**: Agent inherits Workflow's constructor, accepting
   optional `workflowId` and initial state. Use `Agent::make(workflowId: 'thread-42')`
   or call `setThreadId('thread-42')` after container resolution. The redundant
   `threadId` constructor alias is removed.
2. **Identity reader**: `Agent::getThreadId(): ?string` delegates to
   `getWorkflowId()` — the thread ID and workflow ID are one stored address.
3. **`ChatHistoryInterface` gains binding**:

   ```php
   public function setThreadId(string $threadId): void;   // assign-once; different id throws
   public function getThreadId(): ?string;                // null until bound
   ```

   Loading moved out of backend constructors to first use — that is what
   makes identity-free construction possible. A durable history *used* while
   unbound throws (`ChatHistoryException`).
4. **Backend constructors reorder** — identity is optional, after required
   dependencies:

   ```php
   // Before                                  // After
   new SQLChatHistory($threadId, $pdo);       new SQLChatHistory($pdo);              // unbound
   new EloquentChatHistory($threadId, M::class);  new SQLChatHistory($pdo, $threadId);   // pre-bound
   new FileChatHistory($dir, $key);           new EloquentChatHistory(M::class, $threadId);
                                              new FileChatHistory($dir, $key);       // unchanged order
   ```

5. **The `Closure` (resolver/factory) form of `setChatHistory()` is
   removed.** It existed to defer construction until the threadId was known;
   binding makes deferral unnecessary.
6. **Conflicting identity claims throw** (`AgentException` on the agent,
   `ChatHistoryException` on the history): explicit `workflowId:` vs a
   pre-bound history with a different key; the ignition record vs an
   explicitly claimed identity on a resume; re-binding a bound history.
7. **No generation**: the framework never fabricates a thread identity.
   Anonymous runs that worked in 3.x (`Agent::make()->chat(...)`) now throw
   `AgentException` ("This agent has no thread ID: bind one with
   setThreadId() first."), and so do inspecting, answering or resetting an
   unbound Agent and reading its chat history. Bind a thread before the first
   call, quick starts included: `Agent::make()->setThreadId('demo')->chat(...)`.
8. `Agent::getThreadId()` is a **pure read** of the identity slot, null until
   bound. Hooks may consult it (every run is bound before they execute), but
   the recommended pattern remains constructing the history without identity
   — the framework binds it.

## Update your code

The entry points share one rule — identity enters through `make()` when you
hold it, through the record when you don't, and never through collaborator
construction:

```php
// Fresh turn (controller)
SupportAgent::make(workflowId: $threadId)->chat(new UserMessage($input));

// Thread-first resume (approve endpoint)
SupportAgent::make(workflowId: $threadId)
    ->submitApprovalDecisions(['call_123' => 'approve'])->run();

// Thread-first continuation after frontend execution
SupportAgent::make(workflowId: $threadId)
    ->submitToolResults(['call_123' => ['result' => 'Page title']])->run();

// workflowId-first resume (background wake): the ignition record supplies it
SupportAgent::make(workflowId: $workflowId)->resume($payload)->run();
```

Subclass hooks construct identity-free:

```php
// Before
protected function chatHistory(): ChatHistoryInterface
{
    return new SQLChatHistory($this->threadId, $this->pdo);
}

// After — identity is not the hook's job
protected function chatHistory(): ChatHistoryInterface
{
    return new SQLChatHistory($this->pdo, contextWindow: 50000);
}
```

If you used the resolver form, delete the closure and pass the history
unbound:

```php
// Before
Agent::make(workflowId: $workflowId)
    ->setChatHistory(fn (string $id) => new SQLChatHistory($id, $pdo))
    ->resume($payload);

// After
Agent::make(workflowId: $workflowId)
    ->setChatHistory(new SQLChatHistory($pdo))
    ->resume($payload)->run();
```

Pre-bound histories (`new SQLChatHistory($pdo, $threadId)`) remain a legal
identity declaration — the Agent adopts the key; a disagreement with an
explicit `workflowId:` throws.

## What to search for

```
grep -rn "new SQLChatHistory(\|new EloquentChatHistory(\|new FileChatHistory(\|setChatHistory(fn\|setChatHistory(function" --include="*.php" .
```

Flip SQL/Eloquent constructor argument orders; replace history resolver
closures with unbound instances; bind a thread on every Agent that runs without
one (item 7), whether built with `make()` or resolved from a container; check any custom `ChatHistoryInterface`
implementation adds `setThreadId()`/`getThreadId(): ?string` (extend
`AbstractChatHistory` to inherit them plus the lazy-load seam).
