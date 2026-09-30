# Upgrade: Built-in adapters send different frames: update frontends and frame assertions

## Summary

`VercelAIAdapter` and `AGUIAdapter` send a different sequence of frames in 4.x. The `data: <JSON>\n\n` framing and the response headers are unchanged, and no PHP API changes in this guide. What must change is the code that reads the frames: client code (JavaScript, TypeScript, Vue, Svelte, templates), PHP tests that pin frames, and PHP code that branches on frame types.

In 3.x a paused or failed run stopped the stream with an exception, and the endpoint wrote its own frames. Guides 29 and 36 removed that PHP code. In 4.x the adapters end the stream with native pause and failure frames, and the client must handle them.

### Vercel AI SDK (`VercelAIAdapter`)

| Frame | 3.x | 4.x |
|---|---|---|
| Message start | `start {messageId}`: the first chunk's ID, a vendor ID or `null` | `start {messageId}`: the stored ID of the first assistant message of the turn, never `null` |
| Text | `text-delta {id, messageId, delta}` with a new random `id` per delta, no start or end frames | `text-start {id}`, `text-delta {id, delta}`, `text-end {id}`: one `id` for the whole part, no `messageId` |
| Reasoning | `reasoning-delta {id, messageId, delta}`, shaped like text | `reasoning-start {id}`, `reasoning-delta {id, delta}`, `reasoning-end {id}` |
| Steps | none | `start-step` when a model call that follows tool results starts streaming; `finish-step` before the next `start-step` and before `finish`. The first model call has no step frames |
| Tool call (the agent's own tools) | `tool-input-available {toolCallId, toolName, input}` just before the tool ran; `toolCallId` was a random `call_…` | `tool-input-start {toolCallId, toolName}`, then `tool-input-delta {toolCallId, inputTextDelta}`: fragments of the arguments JSON while the model writes the call, or the whole JSON (`{}` without arguments) just before the tool runs when the provider does not stream arguments. `toolCallId` is the provider's call ID |
| Tool result | `tool-output-available {toolCallId, output}` | exactly one of `tool-output-available {toolCallId, output}`, `tool-output-error {toolCallId, errorText}` (the result is an error `ToolOutput`) or `tool-output-denied {toolCallId}` (the approval was rejected) |
| `tool-input-available` | sent for every tool | sent only for tools the browser executes (a 4.x feature), never for the agent's own tools |
| Approval pause | none: the stream threw | for each gated call, the argument preview if not sent yet, then `tool-approval-request {toolCallId, approvalId, reason}` (`approvalId` equals `toolCallId`), then `finish`. A call already decided when the run pauses again also gets `tool-approval-response {approvalId, approved, reason?}` |
| Other pause (a node's `interrupt()`) | none | `data-workflow-interrupt {data: <InterruptRequest JSON>, transient: true}`, then `finish` |
| Failure | none: the stream threw | open parts closed, then `error {errorText: "The run failed."}`; no `finish` |
| End | `finish`, then the raw line `data: [DONE]` | open parts closed, `finish-step` if a step started, then `finish`; no `[DONE]` |

### AG-UI (`AGUIAdapter`)

| Frame | 3.x | 4.x |
|---|---|---|
| `RUN_STARTED`, `RUN_FINISHED` `threadId` | the constructor's thread ID, or a random `thread_…` when none was passed | the thread ID the endpoint passes to the constructor, now required (guide 36) |
| `TEXT_MESSAGE_*` `messageId` | a random `msg_…` per text segment | the stored ID of the assistant message the text belongs to, the same ID a reload shows. A new model call's text starts a new message |
| `REASONING_*` `messageId` | the provider chunk's ID, a vendor ID or `null` | `reasoning_{messageId}`, built from the stored message ID |
| `TOOL_CALL_START`, `TOOL_CALL_ARGS`, `TOOL_CALL_END` | sent when the tool started, before it ran. `TOOL_CALL_ARGS` was left out when the call had no arguments. `parentMessageId` was present only when text preceded the call | sent right before the call's `TOOL_CALL_RESULT`, after the tool ran. `TOOL_CALL_ARGS` is always sent: the provider's argument fragments, or one JSON string (`{}` without arguments). `parentMessageId` is always present: the stored ID of the message holding the call |
| `toolCallId` | the provider's call ID | unchanged |
| `TOOL_CALL_RESULT` | `{toolCallId, content, role: "tool", messageId: <random msg_…>}` | `{messageId: "result_{toolCallId}", role: "tool", toolCallId, content}`, plus `error` (the error text) when the result is an error `ToolOutput`. `content` is a string |
| Approval pause | none: the stream threw | `STATE_SNAPSHOT {snapshot}`, `MESSAGES_SNAPSHOT {messages}`, then `RUN_FINISHED {threadId, runId, outcome: {type: "interrupt", interrupts: [...]}}`. Gated calls get no `TOOL_CALL_*` frames until the run continues after the decision |
| Approval interrupt | none | `{id: <tool call ID>, reason: "confirmation", message, responseSchema: {type: "object", properties: {approved: {type: "boolean"}, reason: {type: "string"}}, required: ["approved"]}, metadata: <the action: id, name, description, decision, feedback, reason, inputs>, expiresAt?}` |
| Other pause (a node's `interrupt()`) | none | the same three frames, with interrupts `{id: "<interrupt ID>", reason: "neuron:wait_for_event" or "neuron:sleep_until", message, metadata: <InterruptRequest JSON>, expiresAt?}` |
| Failure | none: the stream threw | open text or reasoning closed, then `RUN_ERROR {message: "The run failed."}`; no `RUN_FINISHED` |
| Completion | `RUN_FINISHED {threadId, runId}` | unchanged |

`expiresAt` is an ISO 8601 string, present when the request has a deadline. IDs Neuron generates are UUIDv7 (guide 33): treat every ID as an opaque string.

Not in this guide:
- The endpoint: `SSEEncoder`, the `AGUIAdapter` thread ID, seeding a continuation's adapter, continuation endpoints, removing hand-built frames in PHP, the failure text. Guide 36 covers them (already applied).
- Approval decisions (`submitApprovalDecisions()`) and the approval JSON the application sends itself: guide 29.
- Subclasses of `AGUIAdapter` or `VercelAIAdapter`: guide 35.

## Stored data

Neuron does not store frames. Transcripts a client saved itself (AI SDK `UIMessage` arrays, AG-UI message lists) stay readable by that client. The IDs in them are random 3.x IDs that match no message stored by 4.x: do not write code to reconcile them. Nothing to migrate.

## What to Search For

```bash
# 1. Vercel AI SDK frames and hooks
grep -rnE 'text-delta|reasoning-delta|tool-(input|output|approval)-[a-z]+|onToolCall([^A-Za-z]|$)|useChat|useCompletion|DefaultChatTransport|@ai-sdk/|\[DONE\]' --include='*.ts' --include='*.tsx' --include='*.js' --include='*.jsx' --include='*.mjs' --include='*.cjs' --include='*.vue' --include='*.svelte' --include='*.html' --include='*.twig' --include='*.php' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=dist --exclude-dir=build --exclude-dir=.next --exclude-dir=.nuxt .

# 2. AG-UI frames and clients
grep -rnE 'TEXT_MESSAGE_|REASONING_|TOOL_CALL_|RUN_(STARTED|FINISHED|ERROR)|parentMessageId|@ag-ui/|@copilotkit/|HttpAgent|on(ToolCall|RunFinished|RunError|TextMessage|Reasoning)[A-Za-z]*Event' --include='*.ts' --include='*.tsx' --include='*.js' --include='*.jsx' --include='*.mjs' --include='*.cjs' --include='*.vue' --include='*.svelte' --include='*.html' --include='*.twig' --include='*.php' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=dist --exclude-dir=build --exclude-dir=.next --exclude-dir=.nuxt .

# 3. Frontend dependency versions
grep -rnE '"(ai|@ai-sdk/[a-z-]+|@ag-ui/[a-z-]+|@copilotkit/[a-z-]+)" *:' --include='package.json' --exclude-dir=node_modules .
```

Follow the hits:
- Searches 1 and 2 find `useChat` users (Case 1), custom Vercel parsers (Case 2), AG-UI clients (Case 3), and PHP tests or PHP code that pin frames (Case 4). Correctly migrated code still matches them, because many frame names did not change.
- Find the frame types the 3.x endpoint wrote itself in its `catch (WorkflowInterrupt ...)` or `catch (Throwable ...)` blocks, which guides 29 and 36 deleted: read the endpoint's pre-upgrade version (`git show HEAD:<path>` or the VCS history), then grep the client code for those type names.
- Hits in compiled bundles (for example `public/js/app.js`): change the sources, and report to the developer that the assets must be rebuilt.
- Search 3: Neuron verifies these frames with `ai` 7.x and `@ai-sdk/react` 4.x, `@ag-ui/client` 0.0.x, and CopilotKit 1.x. The `tool-approval-request` part needs an AI SDK release that supports tool approval. If the application pins an older major line, report it to the developer and ask whether to upgrade. Do not change the versions yourself.
- If the client is not in this repository (a mobile app, a separate SPA), report the two tables above and the Cases below to the developer.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: Vercel AI SDK `useChat`

`useChat` with `DefaultChatTransport` (the `Chat` class in Vue and Svelte) and `useCompletion` parse the 4.x frames without change. What changes around them:

1. `onToolCall` no longer fires for the agent's own tools: 3.x sent `tool-input-available` for them, and 4.x does not. Move logic written for those tools (running indicators, logs) to the message's tool parts: `isToolUIPart(part)`, with `part.state` going from `input-streaming` (the model is writing the call) to `output-available`, `output-error` or `output-denied`. Keep `onToolCall` only for tools the browser executes.
2. A gated call arrives as a tool part in state `approval-requested`. Answer with `addToolApprovalResponse({ id: part.approval.id, approved, reason })` and send the message back, automatically with `sendAutomaticallyWhen: lastAssistantMessageIsCompleteWithApprovalResponses` or with the next `sendMessage()`. The endpoint continues the run as guide 36 describes. The frame's `reason` is `part.approval.requestReason` in `ai` 7.x.
3. A failure arrives as an `error` frame: `useChat` sets `error` and the status `error`. A node's `interrupt()` arrives as a transient `data-workflow-interrupt` part, which is delivered only to `onData`.
4. Remove the handling of approval or error frames the 3.x endpoint wrote itself.
5. If the UI renders every part type and throws on unknown ones, handle `step-start` parts (from `start-step`).

Before (3.x):
```tsx
import { useChat } from '@ai-sdk/react';
import { DefaultChatTransport } from 'ai';

const { messages, sendMessage } = useChat({
  transport: new DefaultChatTransport({ api: '/chat' }),
  onToolCall({ toolCall }) {
    setRunningTool(toolCall.toolName); // Neuron 3.x sent tool-input-available for the agent's tools
  },
  onData(part) {
    if (part.type === 'data-approval') setApproval(part.data); // written by the 3.x endpoint's catch block
  },
});
```

After (4.x):
```tsx
import { useChat } from '@ai-sdk/react';
import { DefaultChatTransport, getToolName, isToolUIPart, lastAssistantMessageIsCompleteWithApprovalResponses } from 'ai';

const { messages, sendMessage, addToolApprovalResponse } = useChat({
  transport: new DefaultChatTransport({ api: '/chat' }),
  sendAutomaticallyWhen: lastAssistantMessageIsCompleteWithApprovalResponses,
  onData(part) {
    if (part.type === 'data-workflow-interrupt') setWaiting(part.data); // a node's interrupt(), not an approval
  },
});

// While rendering message.parts:
if (isToolUIPart(part) && part.state === 'approval-requested') {
  return (
    <div key={index}>
      {getToolName(part)}: {part.approval.requestReason}
      <button onClick={() => addToolApprovalResponse({ id: part.approval.id, approved: true })}>Approve</button>
      <button onClick={() => addToolApprovalResponse({ id: part.approval.id, approved: false, reason: 'Denied by the user' })}>Reject</button>
    </div>
  );
}
if (isToolUIPart(part)) {
  return <span key={index} data-state={part.state}>{getToolName(part)}</span>;
}
```

### Case 2: Custom Vercel stream parsers

Code that reads the response itself (a `fetch` reader, `EventSource`, a PHP or Node consumer):

1. Stop on `finish` or `error`, or when the response ends. Never wait for `[DONE]`.
2. Key text and reasoning by the part `id`, which stays the same from `text-start` to `text-end`. Read the message ID only from `start`.
3. Build each tool call from `tool-input-start` plus the `inputTextDelta` fragments concatenated per `toolCallId`. The JSON is complete once a `tool-output-*` or `tool-approval-request` frame for that call arrives.
4. Handle `tool-output-error`, `tool-output-denied`, `tool-approval-request`, `data-workflow-interrupt` and `error`. An approval is answered through an application endpoint that calls `submitApprovalDecisions()` keyed by `toolCallId` (guide 29). `start-step` and `finish-step` can be ignored.

Before (3.x):
```ts
for await (const payload of payloads(response)) { // the text after each "data: "
  if (payload === '[DONE]') break;
  const event = JSON.parse(payload);
  switch (event.type) {
    case 'start': ui.setMessageId(event.messageId); break;
    case 'text-delta': ui.appendText(event.messageId, event.delta); break;
    case 'reasoning-delta': ui.appendReasoning(event.messageId, event.delta); break;
    case 'tool-input-available': ui.showTool(event.toolCallId, event.toolName); break;
    case 'tool-output-available': ui.showResult(event.toolCallId, null, event.output); break;
  }
}
ui.done();
```

After (4.x):
```ts
const inputs: Record<string, string> = {};
const parsedInput = (callId: string): unknown => JSON.parse(inputs[callId] || '{}');

for await (const payload of payloads(response)) { // the text after each "data: "
  const event = JSON.parse(payload);
  switch (event.type) {
    case 'start': ui.setMessageId(event.messageId); break;
    case 'text-delta': ui.appendText(event.id, event.delta); break; // text-start/text-end bracket the part
    case 'reasoning-delta': ui.appendReasoning(event.id, event.delta); break;
    case 'tool-input-start': inputs[event.toolCallId] = ''; ui.showTool(event.toolCallId, event.toolName); break;
    case 'tool-input-delta': inputs[event.toolCallId] += event.inputTextDelta; break;
    case 'tool-output-available': ui.showResult(event.toolCallId, parsedInput(event.toolCallId), event.output); break;
    case 'tool-output-error': ui.showToolError(event.toolCallId, event.errorText); break;
    case 'tool-output-denied': ui.showDenied(event.toolCallId); break;
    case 'tool-approval-request': ui.askApproval(event.toolCallId, parsedInput(event.toolCallId), event.reason); break;
    case 'data-workflow-interrupt': ui.showWaiting(event.data); break;
    case 'error': ui.showError(event.errorText); return; // no finish follows
    case 'finish': ui.done(); return;
  }
}
```

### Case 3: AG-UI clients

This covers `@ag-ui/client` (`HttpAgent`), CopilotKit and custom parsers.

1. Use the IDs the frames carry. Remove code that parses ID prefixes (`msg_`, `call_`), generates its own IDs for these messages, or re-keys messages after a reload.
2. Tool frames arrive after the tool ran. Do not show a tool as running from `TOOL_CALL_START`. `TOOL_CALL_ARGS` always arrives, and a failed tool's `TOOL_CALL_RESULT` carries `error`.
3. On `RUN_FINISHED`, check the outcome before treating the turn as complete: `params.outcome === 'interrupt'` in an `@ag-ui/client` subscriber, `event.outcome?.type === 'interrupt'` in a raw parser. Then render the interrupts. Answer every interrupt of the pause in one request, in one of two ways:
   - With AG-UI `resume` entries `{interruptId, status: 'resolved' | 'cancelled', payload: {approved, reason?}}`. The endpoint forwards them with `AGUIInputTranslator` (guide 36).
   - Through an application endpoint that calls `submitApprovalDecisions([$id => 'approve' | 'reject' | ['reject', $reason]])` (guide 29). A confirmation's `id` is the tool call ID.

   A `neuron:*` interrupt is resolved with the payload that node expects (guide 15). With CopilotKit's v2 API, render confirmations with `useInterrupt({ render: ({ interrupt, resolve }) => ... })` from `@copilotkit/react-core/v2` and answer with `resolve({ approved: true })` or `resolve({ approved: false, reason })`.
4. `RUN_ERROR` is terminal: no `RUN_FINISHED` follows. Remove the handling of approval or error frames the 3.x endpoint wrote itself.
5. `MESSAGES_SNAPSHOT` replaces the client's messages. If messages disappear on a pause, the endpoint does not seed the adapter with the request's messages: guide 36 covers that.

Before (3.x):
```ts
import { HttpAgent } from '@ag-ui/client';

await agent.runAgent({}, {
  onToolCallStartEvent({ event }) {
    showSpinner(event.toolCallId, event.toolCallName); // 3.x sent it before the tool ran
  },
  onToolCallResultEvent({ event }) {
    showResult(event.toolCallId, event.content);
  },
  onRunFinishedEvent() {
    markTurnComplete();
  },
});
```

After (4.x):
```ts
import { HttpAgent } from '@ag-ui/client';

await agent.runAgent({}, {
  onToolCallResultEvent({ event }) {
    // TOOL_CALL_START/ARGS/END arrive right before this frame, after the tool ran
    showResult(event.toolCallId, event.content, (event as { error?: string }).error);
  },
  onRunFinishedEvent(params) {
    if (params.outcome === 'interrupt') {
      askForDecisions(params.interrupts); // the run is paused, not complete
      return;
    }
    markTurnComplete();
  },
  onRunErrorEvent({ event }) {
    showError(event.message); // terminal: no RUN_FINISHED follows
  },
});

// Answering the pause
await agent.runAgent({
  resume: agent.pendingInterrupts.map((interrupt) => ({
    interruptId: interrupt.id,
    status: 'resolved' as const,
    payload: { approved: approved[interrupt.id] === true },
  })),
});
```

### Case 4: PHP tests and PHP code pinned to frames

Guide 36 already turned the collected frames into `ProtocolEvent` objects. The expected frames are still the 3.x ones:

1. Re-capture every expected sequence from a 4.x run and check it against the tables above.
2. The last Vercel frame is `finish` (or `error`). Delete `[DONE]` assertions and filters. As bytes, the last line is `"data: {\"type\":\"finish\"}\n\n"`.
3. Assert IDs by structure, not by value or random prefix: one part `id` from start to end, `toolCallId` equal to the call ID the provider fake returns, and AG-UI IDs equal to the stored message IDs.
4. A failing run yields `error` or `RUN_ERROR` as its last frame before the exception is rethrown.
5. PHP code outside tests that branches on frame types (a broadcaster that forwards only `text-delta`, a logger for `tool-input-available`): update it using the tables.

Before (the provider fake answers with a call to `clock`, ID `call_clock`, no arguments, then "It is ten"):
```php
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Streaming\ProtocolEvent;

$agent->setStreamAdapter(fn (): VercelAIAdapter => new VercelAIAdapter());

$frames = array_map(
    fn (ProtocolEvent $event): array => json_decode(json_encode($event), true),
    iterator_to_array($agent->stream(new UserMessage('What time is it?')), false),
);

$this->assertSame(
    ['start', 'tool-input-available', 'tool-output-available', 'text-delta', 'text-delta', 'finish'],
    array_column($frames, 'type'),
);
$this->assertStringStartsWith('call_', $frames[1]['toolCallId']);
$this->assertSame($frames[1]['toolCallId'], $frames[2]['toolCallId']);
```

After (4.x):
```php
$this->assertSame([
    'start',
    'tool-input-start', 'tool-input-delta',
    'tool-output-available',
    'start-step',
    'text-start', 'text-delta', 'text-delta', 'text-end',
    'finish-step',
    'finish',
], array_column($frames, 'type'));
$this->assertSame('call_clock', $frames[1]['toolCallId']); // the provider's call ID
$this->assertSame($frames[1]['toolCallId'], $frames[3]['toolCallId']);
$this->assertCount(1, array_unique(array_column(array_slice($frames, 5, 4), 'id'))); // one part ID from text-start to text-end
```

The same run through `new AGUIAdapter('thread-1')`.

Before:
```php
$this->assertSame([
    'RUN_STARTED',
    'TOOL_CALL_START', 'TOOL_CALL_END', 'TOOL_CALL_RESULT',
    'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END',
    'RUN_FINISHED',
], array_column($frames, 'type'));
$this->assertArrayNotHasKey('parentMessageId', $frames[1]);
$this->assertStringStartsWith('msg_', $frames[3]['messageId']);
```

After (4.x):
```php
$this->assertSame([
    'RUN_STARTED',
    'TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'TOOL_CALL_RESULT',
    'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END',
    'RUN_FINISHED',
], array_column($frames, 'type'));

[, $toolCallMessage, , $answer] = $agent->getChatHistory()->getMessages();
$this->assertSame($toolCallMessage->getId(), $frames[1]['parentMessageId']);
$this->assertSame('{}', $frames[2]['delta']);
$this->assertSame('result_call_clock', $frames[4]['messageId']);
$this->assertSame($answer->getId(), $frames[5]['messageId']);
```

## Checklist

- No client code waits for or filters `[DONE]`; custom Vercel parsers stop on `finish` or `error`.
- `onToolCall` handles only tools the browser executes; progress of the agent's own tools is rendered from tool parts or from `tool-input-*` and `tool-output-*` frames.
- Vercel clients handle `tool-approval-request` and `error`; AG-UI clients check the `RUN_FINISHED` outcome, answer every interrupt by its `id`, and treat `RUN_ERROR` as terminal.
- No handler remains for frame types the 3.x endpoint wrote itself.
- No client code parses ID prefixes, generates IDs for these messages, re-keys messages after a reload, or expects AG-UI tool frames before the tool ran.
- Custom Vercel parsers key text and reasoning by the part `id` and read the message ID only from `start`.
- PHP frame tests are re-captured from 4.x runs, and assert IDs by structure.
- Out-of-repository clients, compiled assets that need a rebuild, and frontend dependencies older than the verified lines have been reported to the developer.
