# Upgrade: Stream adapters see a suspended run

Use `Agent::submitApprovalDecisions($decisions)` for tool approval and `Agent::submitToolResults($results)` for deferred tool results. Both accept maps keyed by tool call ID and stage a continuation; finish with `run()` for an `AgentState` or `events()` for a stream.

## What Changed

1. **`StreamAdapterInterface` gained `interrupt(InterruptRequest $request): iterable`.** The Workflow
   calls it *instead of* `end()` when a segment ends with an interruption (a tool approval,
   `awaitEvent()`, `sleepUntil()`), passing the single current `InterruptRequest`. Both pull consumers and an attached channel (`send()`) receive its events. Custom
   adapters must implement it.
2. **An `InterruptEvent` no longer passes through `transform()`.** A
   `mapEvent(InterruptEvent::class, ...)` mapping is never invoked anymore; the pause is
   encoded by `interrupt()`.
3. **Built-in adapters encode the pause natively.** Previously a suspended stream ended
   exactly like a completed one — on AG-UI with the gated tool call still open, which the
   official `@ag-ui/client` verifier rejects (`Cannot send 'RUN_FINISHED' while tool calls are
   still active`).
   - `AGUIAdapter`: approval proposals appear in `RUN_FINISHED` under
     `outcome: {type: "interrupt", interrupts: [...]}` — one `confirmation`
     interrupt per action, with the tool call ID as `id`, a response schema and
     action metadata. Approval does not dispatch executable calls. Deferred calls
     are published when the agent later waits for their results. Other requests
     use `neuron:<type>` with their portable JSON as metadata. A request deadline
     becomes `expiresAt`.
   - `VercelAIAdapter`: a pending approval emits an argument preview and
     `tool-approval-request` (the approval ID is the tool call ID) per action before
     `finish`; other requests travel as a transient `data-workflow-interrupt` part.
4. **`Action` gained `inputs`** (the tool call arguments), serialized as `inputs` in the
   `ApprovalRequest` JSON, so an approval can be rendered as data without parsing the
   pretty-printed `description`.

## What to Search For

```
grep -rn "implements StreamAdapterInterface" --include="*.php" .
grep -rn "InterruptEvent::class" --include="*.php" .
```

Also review frontend code that treats every `RUN_FINISHED` (AG-UI) or `finish` (Vercel) as a
completed turn: a suspended run now announces itself.

## How to Refactor

### Custom adapters

Before:

```php
final class MyAdapter implements StreamAdapterInterface
{
    public function start(): iterable { /* ... */ }
    public function transform(object $chunk): iterable { /* ... */ }
    public function end(): iterable { /* ... */ }
    public function error(Throwable $error): iterable { /* ... */ }
}
```

After — encode what the run is waiting for, or return nothing if the protocol cannot express
a pause:

```php
final class MyAdapter implements StreamAdapterInterface
{
    // ...

    public function interrupt(InterruptRequest $request): iterable
    {
        yield new ProtocolEvent('paused', ['request' => $request->jsonSerialize()]);
    }
}
```

### Frontends

An AG-UI client checks `event.outcome?.type === 'interrupt'` on `RUN_FINISHED` and reads
`event.outcome.interrupts`; each `confirmation` interrupt's `id` is the key of the
decision map to deliver with `submitApprovalDecisions($decisions)->events()`. A Vercel AI SDK client receives the
standard `tool-approval-request` part and answers it through the SDK's approval response.

Custom frontends sending native result maps continue deferred execution with
`submitToolResults($results)->events()`, where each call ID maps to either
`['result' => $value]` or `['error' => $message]`. Raw AG-UI and Vercel payloads
use `submitInputs($payload, $translator)` with their protocol translator.

## Verification Checklist

- [ ] Every custom `StreamAdapterInterface` implementation defines `interrupt()`
- [ ] No `mapEvent(InterruptEvent::class, ...)` registrations remain
- [ ] A streamed approval over AG-UI exposes `confirmation` actions in the
      `RUN_FINISHED` interrupt outcome without dispatching executable calls
