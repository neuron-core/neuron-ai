# ADR 0006 — Externalize chat history from durable workflow state

## Status

Proposed

## Context

### How agent durability works today

Every node execution is persisted as a `StepResult` by `LocalStepEngine::runStep()`.
A `StepResult` carries the step's terminal event **and a full snapshot of the entire
`WorkflowState`** (`StepResult::__serialize`). On replay, `WorkflowExecutor::traverse()`
skips completed steps and restores their snapshot via `setState()`, so the per-step
state snapshots are load-bearing for interrupt-resume and crash recovery.

For an `AgentState`, each snapshot contains **two full copies of the conversation**:

1. `$chatHistory` — the `ChatHistoryInterface` object is a plain protected property
   on `AgentState` with no serialization exclusion, so the whole component (including
   its in-memory `Message[]`) is serialized into every step snapshot.
2. `__steps` — a second array of the same `Message` objects, appended by
   `ChatHistoryHelper::addToChatHistory()` on every message.

The agent loop (`ChatNode → ToolNode → ChatNode → …`) produces two node steps per
tool round, and the conversation grows by ~two messages per round. Step *k*'s
snapshot therefore has size O(k), and total persisted size across a run is
**O(N²)** in the number of iterations.

### Measured impact

A 15-round tool loop (fake provider, ~500-byte tool results) over `FilePersistence`:

| Metric | Value |
|---|---|
| First node snapshot (`ChatNode-0`) | 5.2 KB |
| Last node snapshot (`ToolNode-29`) | 35.5 KB |
| Sum of all node-step snapshots | **665 KB** |
| Largest single snapshot (≈ actual data) | 35.5 KB |
| Amplification | ~19× at 15 rounds, grows linearly with rounds |

`FilePersistence` compounds this by rewriting the entire workflow file on every
step save, making cumulative write I/O cubic in loop length. The `HistoryTrimmer`
eventually bounds the `$chatHistory` copy at the context window, but `__steps` is
never trimmed and grows without bound.

The blob is deleted on clean completion (`deleteSteps()`), so the quadratic cost is
transient for successful runs — but it persists for the whole lifetime of a
suspended run (e.g. waiting on `ToolApproval`) and after a crash, and its peak hits
storage and I/O on every long run.

### A hard bug: the documented production combo crashes

Because the state snapshot serializes the chat history *object itself*, the exact
combination the docs recommend for tool approval — durable workflow persistence
plus a durable chat history — fails on the first step save:

```
FilePersistence (or DatabasePersistence) + SQLChatHistory
→ Exception: Serialization of 'PDO' is not allowed
```

Reproduced against sqlite. `EloquentChatHistory` has the same problem;
`FileChatHistory` only survives because its properties happen to be serializable.
The durable layer is serializing a **live service** (a storage-backed component),
not data.

### What resume actually needs

Replay correctness after an interrupt or crash requires exactly:

1. **Routing** — the terminal event of each completed step, so traversal can walk
   to the first incomplete step. Events are O(1) per step: `AIInferenceEvent`
   carries only the latest round's messages.
2. **State as of the last completed step** — intermediate snapshots are dead
   weight; each `setState()` during replay immediately overwrites the previous one.
3. **Pending memos** of the interrupted/crashed step (inference response, tool
   results) — the at-most-once guarantee.
4. **Chat history** — which already has its own durable system of record, and which
   ADR 0003/0005 already treat as authoritative (approval state and the resume
   token live in history, not in workflow persistence).

Everything beyond that is redundancy — and it is the redundancy that is quadratic.

### Every consumer treats history as a service

A census of `$state->getChatHistory()` call sites confirms history does not belong
in state:

- **Nodes**: `ChatNode`, `StreamingNode`, `StructuredOutputNode`, `ToolNode` /
  `ParallelToolNode` (via `ChatHistoryHelper`), RAG's `PreProcessNode`.
- **Middleware**: `ToolApproval` (reads the tail, writes the annotated
  `ToolCallMessage`), `Summarization` (flushes and rewrites).
- **Agent-level conveniences**: `AgentState::getMessage()` (backing
  `AgentHandler::getMessage()`), `Agent::checkResumeToken()` (ADR 0005 token).

Every usage is service-style — "give me the live conversation store" — never
"give me the history as it was at this point in the durable timeline." Nothing
depends on history being snapshot data.

`__steps` has **no consumer** anywhere in the framework: it is written on every
message and read only by its own accessor and tests.

## Decision

Remove the chat history from the durable workflow state. History is a runtime
service with its own storage; the workflow's durable layer stores only workflow
data (events, memos, scalar state).

### 1. Nodes receive the chat history as a constructor dependency

Agent nodes take `ChatHistoryInterface` in their constructors. All framework nodes
are built inside `Agent::compose()` / `chat()` / `stream()` — after every fluent
setter has run — so there is a single wiring point and no setup-ordering hazard:

```php
new ChatNode($this->resolveProvider(), $this->getChatHistory())
```

An `AgentNodeInterface` (Agent module — the Workflow module must stay Chat-free)
exposes the reference:

```php
interface AgentNodeInterface extends NodeInterface
{
    public function getChatHistory(): ChatHistoryInterface;
}
```

Custom workflow authors composing agent nodes by hand inject the instance
themselves — ordinary dependency injection.

### 2. Middleware borrows the history from the node it wraps

Middleware are **user-constructed** (`->addMiddleware(ToolNode::class, new
ToolApproval())`), so constructor-injecting them is explicitly rejected: it forces
double wiring and allows a silent split-brain where the middleware records
approval state on a different history instance (or thread) than the one the nodes
read and write. Instead, middleware ask the node at execution time —
`$node->getChatHistory()` — which makes instance divergence unrepresentable and
keeps `new ToolApproval()` zero-config.

Because PHP parameter contravariance forbids narrowing `before(NodeInterface …)`
in a subclass, the narrowing is centralized once in a template-method base class:

```php
abstract class AgentMiddleware implements WorkflowMiddleware
{
    final public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if ($node instanceof AgentNodeInterface && $state instanceof AgentState) {
            $this->beforeAgentNode($node, $event, $state);
            return;
        }

        $this->onAgentContextMismatch($node, $event, $state);
    }

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state): void
    {
    }

    /**
     * Called when this middleware is attached outside the agent context.
     * Empty by default; safety-critical middleware override it to fail
     * loudly instead of being silently skipped.
     */
    protected function onAgentContextMismatch(NodeInterface $node, Event $event, WorkflowState $state): void
    {
    }
}
```

- `after()` routes through the same type guard but does not re-invoke the
  mismatch hook — mismatch reaction is a `before()`-only concern.
- The mismatch hook receives the raw generic types so an override can report
  which half of the contract broke (agent node with plain state, or vice versa).
- `Summarization` inherits the silent no-op. `ToolApproval` overrides the hook to
  throw: silently skipping an approval gate is a safety hazard, and the type
  system cannot express this contract — the runtime hook enforces it.
- Docblock-only narrowing is rejected: it asserts without checking, and PHPStan
  would require `@var` lies to accept it.

### 3. `__steps` is removed from durable state

Nothing in the framework consumes it. Either delete it outright (derive "messages
of this run" from chat history on demand) or, if kept as public API, make it
transient (excluded from serialization, documented as reflecting only the current
process's portion of a resumed run). Merely resetting it at interruption is
rejected: the snapshot restore in `setState()` would resurrect the pre-interrupt
copy, and the dominant growth happens within a single uninterrupted loop anyway.

### 4. `addMessage()` becomes idempotent

With nodes replaying against the *live* durable history instead of a
point-in-time snapshot, a crashed node may re-add a message its first attempt
already wrote. The replace-last rule (ADR 0003) already makes `ToolCallMessage`
re-adds convergent; plain messages need the same protection — a stable message id
(or content hash), with `AbstractChatHistory::addMessage()` skipping/replacing an
incoming message whose id matches the tail. This is a **requirement** of
externalizing history, not an optional hardening.

### 5. Re-plumbed conveniences

- `Agent::checkResumeToken()` reads the tail via the agent's own
  `getChatHistory()` (same trait, no state involved).
- `AgentHandler::getMessage()` derives the final message from
  `__provider_response` (already in state), or the Agent hands the handler its
  history reference. `AgentState::getMessage()` / `getChatHistory()` are removed
  with the migration below.

## Migration path

Phased so the durability wins land without waiting for a major release:

1. **Bridge (minor version, non-breaking).** Make `$chatHistory` transient:
   exclude it from `AgentState` serialization and re-bind the live instance after
   every snapshot restore (an `Agent`-level `setState()` override carries the
   configured instance onto the restored state). Internally switch nodes and
   middleware to the injected reference (`AgentNodeInterface` +
   `AgentMiddleware`); keep `AgentState::getChatHistory()` as a delegating,
   deprecated shim. Ship the idempotent `addMessage()` guard in the same release.
   All durability behavior changes land here.
2. **End state (next major).** Remove `AgentState::getChatHistory()` /
   `getMessage()` and the rebinding shim; change node constructors. `AgentState`
   is then pure serializable data and the serialization problem is structurally
   impossible rather than patched around.

Projects already planning a major release may skip the bridge and go straight to
injection.

### Related engine-level work (out of scope here, enabled by this ADR)

After steps 1–4, per-step snapshots are O(1) and total storage is O(N) — almost
entirely memos, which are genuinely required for at-most-once execution. Two
further engine refactors remain available for generic workflows and very long
loops, and become simpler once state is small:

- **Single current-state record**: persist state once per workflow (overwritten
  at each successful step boundary, one record per parallel branch) instead of
  once per step; per-step `StepResult`s keep only events and markers. Replay
  semantics are unchanged — only the last completed step's state is ever consumed.
- **Checkpoint cursor + truncation**: persist `{index, next event, state}` and
  delete steps behind the cursor, making storage and resume cost O(1) in loop
  length. Requires per-branch cursors for parallel branches.
- **`FilePersistence` write amplification**: move from whole-file rewrite per
  save to per-step files or an append-only journal.

An application-level mitigation is available immediately and should be
documented: Temporal-style *continue-as-new* — cap the loop, let the workflow
complete (steps are deleted on completion), and start a fresh `workflowId` seeded
from the same chat history, which is the real conversational state.

## Consequences

### Positive

- Durable snapshots stop carrying the conversation: the measured 15-round run
  drops from 665 KB of step snapshots to roughly the ~40 KB of memos plus O(1)
  snapshots; the quadratic term is gone.
- `SQLChatHistory` / `EloquentChatHistory` become usable with durable workflow
  persistence — the `Serialization of 'PDO' is not allowed` crash disappears
  structurally.
- The architecture matches the documented model: chat history is the system of
  record (ADR 0003/0005) with its own storage; workflow persistence stores
  workflow data.
- Middleware↔node history divergence becomes unrepresentable; agent middleware
  get fully-typed, PHPStan-verifiable hooks; misattachment of safety middleware
  fails loudly.

### Negative / behavior changes

- **Cross-process resume with `InMemoryChatHistory` is no longer rescued by
  snapshots.** Today `FilePersistence` + `InMemoryChatHistory` happens to survive
  a cross-process resume because the history rides inside the first step
  snapshot. After this change, durable workflow persistence requires a comparably
  durable chat history — which is what the documentation already states. In-process
  interrupt-resume (same instance) is unaffected. Must be called out in the
  changelog and upgrade guide.
- A crashed step now replays against the live history rather than an exact
  point-in-time copy; correctness relies on the idempotent `addMessage()` guard
  (decision 4) and the existing replace-last rule.
- Breaking API in the major release: `AgentState::getChatHistory()` /
  `getMessage()`, `ChatNode`/`StreamingNode`/`StructuredOutputNode`/`ToolNode`
  constructor signatures, and any third-party nodes or middleware that read
  history from state (the bridge release gives them a deprecation window).
- `getSteps()` is removed or becomes process-local.

## References

- ADR 0003 — chat history as the system of record for approval state
- ADR 0004 — tools declare approval, middleware activates
- ADR 0005 — resume token stamped on the `ToolCallMessage` in chat history
- `src/Workflow/Executor/LocalStepEngine.php`, `StepResult::__serialize`
- `src/Workflow/Executor/WorkflowExecutor.php` (`traverse()` / `setState()` replay)
- `src/Agent/AgentState.php` (`$chatHistory`, `__steps`)
- `src/Agent/Middleware/ToolApproval.php`, `src/Agent/Middleware/Summarization.php`
