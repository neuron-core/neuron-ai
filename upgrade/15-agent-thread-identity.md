# Upgrade: Agent-owned thread identity & thread binding

## Summary

Thread identity moves onto the **Agent** (a nullable slot assigned exactly
once, mirroring how the engine assigns the runId), and chat histories become
**bound, not identity-constructed**: a history is constructible without its
thread, loads lazily, and the framework injects the resolved identity into it
before first use. Identity never appears in wiring code.

What changed:

1. **New constructor parameter**: `Agent::make(threadId: 'thread-42')` — the
   one front door for declaring which conversation a run belongs to.
2. **New reader**: `Agent::getThreadId(): ?string` (null = the run is not
   thread-addressable). `address()` delegates to it — the threadId IS the run's address.
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

5. **The `Closure` (resolver/factory) forms of `setChatHistory()` AND
   `setChannel()` are removed.** They existed to defer construction until
   the threadId was known; binding makes deferral unnecessary (and no
   thread-scoped channel exists in core — pass a concrete channel).
6. **Conflicting identity claims throw** (`AgentException` on the agent,
   `ChatHistoryException` on the history): explicit `threadId:` vs a
   pre-bound history with a different key; the ignition record vs an
   explicitly claimed identity on a resume; re-binding a bound history.
7. **No generation**: the framework never fabricates a thread identity.
   Anonymous quick-start runs (`Agent::make()->chat(...)`) still work —
   `InMemoryChatHistory` self-keys as its own storage default — but they are
   **not thread-addressable** (no pointer, no `threadId` in the ignition
   record): an identity nobody declared is not an address.
8. `Agent::getThreadId()` is a **pure read** of the identity slot. Hooks may
   consult it (null on anonymous runs), but the recommended pattern remains
   constructing the history without identity — the framework binds it.
   Addressability requires identity declared before the run starts
   (`make(threadId:)` or a pre-bound history at the setter); identity
   arriving later (a self-keyed hook default) is adopted but does not make
   the run thread-addressable.

## Update your code

The three entry points, one rule — identity enters through `make()` when you
hold it, through the record when you don't, and never through collaborator
construction:

```php
// Fresh turn (controller)
SupportAgent::make(threadId: $threadId)->chat(new UserMessage($input));

// Thread-first resume (approve endpoint)
SupportAgent::make(threadId: $threadId)->resume(['call_123' => 'approve']);

// Address-first resume (background wake): the ignition record supplies it
SupportAgent::make(address: $address)->resume($payload);
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
Agent::make(address: $address)
    ->setChatHistory(fn (string $id) => new SQLChatHistory($id, $pdo))
    ->resume($payload);

// After
Agent::make(address: $address)
    ->setChatHistory(new SQLChatHistory($pdo))
    ->resume($payload);
```

Pre-bound histories (`new SQLChatHistory($pdo, $threadId)`) remain a legal
identity declaration — the Agent adopts the key; a disagreement with an
explicit `threadId:` throws.

## What to search for

```
grep -rn "new SQLChatHistory(\|new EloquentChatHistory(\|new FileChatHistory(\|setChatHistory(fn\|setChatHistory(function" --include="*.php" .
```

Flip SQL/Eloquent constructor argument orders; replace history resolver
closures with unbound instances; check any custom `ChatHistoryInterface`
implementation adds `setThreadId()`/`getThreadId(): ?string` (extend
`AbstractChatHistory` to inherit them plus the lazy-load seam).
