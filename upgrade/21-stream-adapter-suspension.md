# Upgrade: Stream adapters see a suspended run

Native approval examples use `NeuronAI\Agent\Interrupt\ApprovalTranslator`; import it alongside the Agent.

## What Changed

1. **`StreamAdapterInterface` gained `suspended(array $requests): iterable`.** The Workflow
   calls it *instead of* `end()` when a segment ends with active interrupts (a tool approval,
   `awaitEvent()`, `sleepUntil()`), passing the active `InterruptRequest`s keyed by interrupt
   ID. Both pull consumers and an attached channel (`sendLine()`) receive its lines. Custom
   adapters must implement it.
2. **An `InterruptEvent` no longer passes through `transform()`.** A
   `mapEvent(InterruptEvent::class, ...)` mapping is never invoked anymore; the pause is
   encoded by `suspended()`.
3. **Built-in adapters encode the pause natively.** Previously a suspended stream ended
   exactly like a completed one — on AG-UI with the gated tool call still open, which the
   official `@ag-ui/client` verifier rejects (`Cannot send 'RUN_FINISHED' while tool calls are
   still active`).
   - `AGUIAdapter`: every open tool call is closed, gated calls that never reached the stream
     are announced (`TOOL_CALL_START` / `TOOL_CALL_ARGS` / `TOOL_CALL_END`), and
     `RUN_FINISHED` carries `outcome: {type: "interrupt", interrupts: [...]}` — one
     `tool_call` interrupt per approval action (its `id` and `toolCallId` are the tool call
     ID, `message` is the approval reason, `metadata` the action) and one `neuron:<type>`
     interrupt per other request (its portable JSON as `metadata`). `expiresAt` is set when
     the request has a deadline.
   - `VercelAIAdapter`: a pending approval emits `tool-input-available` then
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

    /** @param array<int, InterruptRequest> $requests */
    public function suspended(array $requests): iterable
    {
        foreach ($requests as $request) {
            yield 'data: ' . json_encode(['type' => 'paused', 'request' => $request]) . "\n\n";
        }
    }
}
```

### Frontends

An AG-UI client checks `event.outcome?.type === 'interrupt'` on `RUN_FINISHED` and reads
`event.outcome.interrupts`; each `tool_call` interrupt's `toolCallId` is the key of the
decision map to deliver with `submitInputs($decisions, new ApprovalTranslator())`. A Vercel AI SDK client receives the
standard `tool-approval-request` part and answers it through the SDK's approval response.

## Verification Checklist

- [ ] Every custom `StreamAdapterInterface` implementation defines `suspended()`
- [ ] No `mapEvent(InterruptEvent::class, ...)` registrations remain
- [ ] A streamed approval over AG-UI ends with `TOOL_CALL_END` before `RUN_FINISHED`, and the
      `RUN_FINISHED` event carries an `interrupt` outcome
