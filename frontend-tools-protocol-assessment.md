**Frontend tools: UI protocol compatibility assessment**

Assessment date: 11 September 2026. This assesses the working tree, including the deferred-tool implementation under review. No runtime changes were made for this assessment.

**Conclusion**

Neuron has the engine capabilities needed for external execution: portable calls, approval before dispatch, a durable results wait, partial-result accumulation, and continuation to inference. The missing component is a bidirectional Agent integration. The existing streaming adapters encode output; they do not implement either protocol's complete conversation lifecycle.

AG-UI frontend tools, AG-UI interrupts, and Vercel client tools must not all be treated as the same wire operation. In particular, a Neuron workflow interruption does not necessarily need to become an AG-UI interrupt outcome. CopilotKit's ordinary frontend-tool continuation follows a different path.

**Baseline and evidence**

The public npm registry reported [`ai` 7.0.97](https://registry.npmjs.org/ai/latest), [`@copilotkit/core` 1.71.0](https://registry.npmjs.org/@copilotkit/core/latest), and [`@ag-ui/core` 0.0.59](https://registry.npmjs.org/@ag-ui/core/latest) during this assessment. CopilotKit's documentation uses its React V2 API. These are the assessed targets, not a claim about compatibility with every previous release.

Key source findings were checked against fixed upstream revisions:

| Project | Inspected revision |
|---|---|
| Vercel AI SDK | [cdc12a1](https://github.com/vercel/ai/tree/cdc12a150e96485541116cf37f5ae26d87514e58) |
| CopilotKit | [d1584b8](https://github.com/CopilotKit/CopilotKit/tree/d1584b840f904674b470be5d2b16c4ccca99c4f4) |
| AG-UI | [161b2d5](https://github.com/ag-ui-protocol/ag-ui/tree/161b2d5570ee5d831763efb9c7a05bac8201b261) |

Neuron's existing adapter and push-delivery tests pass: **47 tests, 883 assertions**. They verify PHP-generated frames and selected lifecycle invariants. They do not run those frames through the official JavaScript clients or cover the complete deferred-tool round trip. The Vercel tests currently expect some of the incompatible output described below.

**1. What each client expects**

| Integration | Tool definitions | Execution and return path | Consequence for Neuron |
|---|---|---|---|
| AG-UI frontend tools | `RunAgentInput.tools`: name, description, JSON Schema parameters | Tool-call events; client returns `role: "tool"` messages correlated by `toolCallId` | Decode tool definitions and returned tool messages; resume the existing workflow |
| AG-UI standard interrupts | Separately defined interrupt response contract | `RUN_FINISHED.outcome.interrupts`; next input contains `resume[]` | Map protocol interrupt identities and responses to the active Neuron request |
| CopilotKit | `useFrontendTool`, registered at component scope | Client handlers and automatic follow-up through its AG-UI agent | Follow the actual CopilotKit orchestration behavior, not just the event schema |
| Vercel AI SDK | Usually backend declarations; client tool schemas are not a mandatory transport field | `tool-input-available`; `onToolCall` or UI handler; `addToolOutput`; another messages request | Decode assistant tool parts and distinguish results from new user input |

AG-UI explicitly distinguishes client-offered tools from backend tools. Frontend results normally arrive as tool messages; those messages can carry an `error` string. This is a current supported mechanism, not merely a deprecated alternative to `resume[]`. [AG-UI tools](https://docs.ag-ui.com/concepts/tools), [input types](https://docs.ag-ui.com/sdk/js/core/types).

Standard AG-UI interrupts require the next resume to cover every open interrupt, on the same thread. The specification also requires safe repeated resumes, expiry handling, and snapshots of the state/messages needed for continuation. Tool-bound interrupt continuation should emit results against the original call IDs without repeating their call-start/arguments/end sequence. Neuron's support for partial native results can remain; a standard AG-UI resume boundary must enforce its stricter batch contract. [AG-UI interrupts](https://docs.ag-ui.com/concepts/interrupts).

CopilotKit's `useFrontendTool` registers and unregisters handlers with component lifecycle, supports conditional availability, and converts frontend schemas for transmission. `useInterrupt` is a separate API; it accumulates responses until every standard interrupt is addressed. [Frontend hook](https://docs.copilotkit.ai/reference/hooks/useFrontendTool), [interrupt hook](https://docs.copilotkit.ai/reference/hooks/useInterrupt).

Vercel's documented client flow uses `addToolOutput` and optional automatic submission once tool outputs are complete. Its documented built-in approval flow concerns server-executed tools. Browser execution with an additional backend approval gate therefore needs explicit integration work. [Vercel tool usage documentation](https://github.com/vercel/ai/blob/cdc12a150e96485541116cf37f5ae26d87514e58/content/docs/04-ai-sdk-ui/03-chatbot-tool-usage.mdx).

**2. Current outbound implementation**

The [adapter interface](/mnt/c/GitHub/neuron-ai/src/Chat/Messages/Stream/Adapters/StreamAdapterInterface.php) already has the right basic transport lifecycle: `start`, `transform`, `suspended`, `end`, and `error`. Workflow chooses the terminal operation after determining the segment outcome. Keep that separation.

| Concern | AGUIAdapter | VercelAIAdapter |
|---|---|---|
| Framing | SSE and run boundaries implemented | SSE header, finish and `[DONE]` implemented |
| Text/reasoning lifecycle | Start/content/end tracking implemented | Deltas emitted without part start/end; IDs generated per delta |
| Tool inputs | Start/args/end, including streamed arguments | Input-start/delta and input-available |
| Results on a fresh resumed stream | Uses the call's stored ID when present | Ignores the stored call ID; relies on a name cache |
| Approval suspension | One tool-bound interrupt per action | Input-available followed by approval-request |
| Deferred suspension | Generic `neuron:wait_for_event` interrupt | Generic transient `data-workflow-interrupt` |
| Execution type | Does not inspect `ToolCall::isDeferred()` | Does not inspect `ToolCall::isDeferred()` |
| Inbound decoding | None | None |
| Reload/snapshot mapping | No messages/state snapshot support | No stored-history-to-UIMessage mapping |

The portable event mapping facility is useful for application progress, but it currently maps yielded objects only. It does not customize interruption handling or decode incoming requests.

**3. AG-UI and CopilotKit: the principal mismatch**

[AGUIAdapter::suspended()](/mnt/c/GitHub/neuron-ai/src/Chat/Messages/Stream/Adapters/AGUIAdapter.php:365) recognizes `ApprovalRequest`, but treats `ToolResultsRequest` like any generic wait. A deferred call therefore ends with an interrupt whose ID is the engine's integer converted to a string and whose reason is `neuron:wait_for_event`.

That is an understandable custom interrupt description. It is not sufficient for transparent `useFrontendTool` interoperability.

CopilotKit executes unanswered registered tool calls after a run, adds tool messages, and starts a follow-up without automatically building `resume[]`. AG-UI's client blocks runs with uncovered standard interrupts. Combining these paths can execute the handler and then block continuation. This is a source-based finding, not yet a browser-tested reproduction. [CopilotKit run handler](https://github.com/CopilotKit/CopilotKit/blob/d1584b840f904674b470be5d2b16c4ccca99c4f4/packages/core/src/core/run-handler.ts), [AG-UI client guard](https://github.com/ag-ui-protocol/ag-ui/blob/161b2d5570ee5d831763efb9c7a05bac8201b261/sdks/typescript/packages/client/src/agent/agent.ts).

**Recommended ordinary frontend-tool flow:**

1. Stream the tool-call lifecycle, preserving Neuron's call IDs.
2. Commit the Neuron deferred-results suspension.
3. Finish the AG-UI wire run normally, without creating standard pending interrupts for this ordinary frontend handoff.
4. Let CopilotKit execute its registered handlers and submit tool messages.
5. Recognize those messages as answers to the persisted `ToolResultsRequest`; resume Neuron instead of starting a fresh agent turn.

The HTTP/AG-UI invocation has ended; the durable Neuron run remains suspended. These are different lifetimes and should have an explicit mapping.

For applications deliberately using `useInterrupt` to execute external work, an interrupt-based profile is also possible. It needs an explicit handler and agreed result payload schema. Do not advertise that custom profile as automatic `useFrontendTool` compatibility, and do not make both paths compete for the same call.

**Additional AG-UI gaps:**

- Approval resumes need an inbound translation from protocol payloads such as `{approved: true}` to Neuron's decision map. `cancelled` must become an explicit rejection or external error according to the active request type.
- Approval call events currently get announced again by a fresh adapter when ToolNode resumes. Avoid repeated call lifecycles across standard interrupt runs by retaining presentation identity/context at the integration boundary.
- There are no `MESSAGES_SNAPSHOT` or `STATE_SNAPSHOT` mappings for interruption/reload. Export the portable conversation and application-facing state needed by the client, not executable tools or internal workflow records.
- UI message IDs are currently generated by the adapter independently of stored history. A history mapper must establish stable identities and avoid duplicating client-added result messages when the backend later confirms them.
- `ToolMessage.error` is supported inbound, but the inspected `TOOL_CALL_RESULT` event schema has no dedicated `error` member. Do not invent one and assume native SDK support. Choose a documented metadata/snapshot presentation for backend error state while preserving the model-facing error result. [Message contract](https://docs.ag-ui.com/concepts/messages), [event schema](https://github.com/ag-ui-protocol/ag-ui/blob/161b2d5570ee5d831763efb9c7a05bac8201b261/sdks/typescript/packages/core/src/events.ts).

**4. Vercel: existing defects that block the round trip**

These are existing adapter issues exposed by the feature, not reasons to redesign the deferred execution engine.

| Finding | Evidence in Neuron | Required change |
|---|---|---|
| Wrong result correlation | [handleToolResult()](/mnt/c/GitHub/neuron-ai/src/Chat/Messages/Stream/Adapters/VercelAIAdapter.php:196) selects an ID by tool name, ignoring `getCallId()` | Use the persisted call ID for every input/result phase |
| Invalid text/reasoning lifecycle | [handleText()/handleReasoning()](/mnt/c/GitHub/neuron-ai/src/Chat/Messages/Stream/Adapters/VercelAIAdapter.php:136) emit only deltas, with fresh IDs | Track part IDs and emit their start/delta/end lifecycle |
| Null message ID | Lazy start uses `StreamChunk::$messageId`; tool chunks can contain null | Supply a valid UI message identity, preserving it during continuation |
| Error outcomes look successful | All outputs use `tool-output-available` and string conversion | Map error results to `tool-output-error`; distinguish approval denial where appropriate |
| Deferred waits are only custom data | `suspended()` has no external-tool branch | Finish the executable frontend-tool handoff in the correct UI message, with enough context to resume |

Read-only PHP probes reproduced the first three defects: after calls A and B to the same tool, A's output was emitted under B's ID; a fresh adapter invented another ID for A; a tool-result-only stream emitted `start` with `messageId: null`. A text-only probe emitted `start` and `text-delta`, with no `text-start`.

The official client requires a previously opened text part for a text delta and an existing tool invocation for a result. Its schema permits an omitted start message ID, but not null. These failures are therefore more than cosmetic differences. [Client stream processor](https://github.com/vercel/ai/blob/cdc12a150e96485541116cf37f5ae26d87514e58/packages/ai/src/ui/process-ui-message-stream.ts), [chunk schema](https://github.com/vercel/ai/blob/cdc12a150e96485541116cf37f5ae26d87514e58/packages/ai/src/ui-message-stream/ui-message-chunks.ts).

The Vercel inbound bridge must parse `tool-<name>` and `dynamic-tool` parts in assistant messages. `output-available` maps to a Neuron result; `output-error` maps to an error. Approval responses are a separate decision path. The default transport submits `id`, `messages`, `trigger`, and `messageId`; extra schema catalogs require a documented custom body field or backend registration. Runtime/dynamic tool parts do not themselves transmit executable definitions. [HTTP transport](https://github.com/vercel/ai/blob/cdc12a150e96485541116cf37f5ae26d87514e58/packages/ai/src/ui/http-chat-transport.ts).

A continuation must update the existing assistant UI message that owns the tool parts. The current client initializes a continuation from the last assistant message; replacing its identity carelessly can duplicate or orphan tool parts. Stream reconnection is another operation altogether: Vercel's transport has a GET reconnect route, whereas supplying results is a POST conversation continuation. Neuron does not currently provide an SSE replay buffer. [Chat continuation](https://github.com/vercel/ai/blob/cdc12a150e96485541116cf37f5ae26d87514e58/packages/ai/src/ui/chat.ts), [transport reconnect](https://github.com/vercel/ai/blob/cdc12a150e96485541116cf37f5ae26d87514e58/packages/ai/src/ui/http-chat-transport.ts).

**5. Deferred tools requiring approval need a client execution gate**

The backend gate is tested and works inside Neuron. Wire compatibility must ensure the browser respects it too.

Vercel's client invokes `onToolCall` while processing `tool-input-available`, before a subsequent `tool-approval-request` is processed. The current adapter emits exactly that ordering during approval suspension. CopilotKit's inspected run handler also selects unanswered registered calls without an approval-state check at that execution point. Consequently, merely announcing a pending approval alongside an executable frontend call is insufficient. [Vercel processor](https://github.com/vercel/ai/blob/cdc12a150e96485541116cf37f5ae26d87514e58/packages/ai/src/ui/process-ui-message-stream.ts), [CopilotKit handler](https://github.com/CopilotKit/CopilotKit/blob/d1584b840f904674b470be5d2b16c4ccca99c4f4/packages/core/src/core/run-handler.ts).

The integration must explicitly distinguish a proposal awaiting approval from an approved external dispatch. Preserve the backend gate and provide a small client orchestration helper/profile if the stock hook cannot express that distinction. Executing only tool names in the frontend registry is necessary, but does not by itself solve approval for those same names. Vercel's `providerExecuted` is not a general substitute for Neuron's deferred flag.

For simpler application flows, confirmation inside a browser handler is possible, but it is a different contract from Neuron's backend `requireApproval()`. It should be documented as such, not silently substituted.

CopilotKit handler exceptions produce textual errors and suppress automatic follow-up. A wrapper must submit an explicit error outcome to Neuron. Disabling follow-up or closing the page can otherwise leave the durable wait unresolved; the node has no default deadline. Test cancellation separately from successful completion. [CopilotKit result handling](https://github.com/CopilotKit/CopilotKit/blob/d1584b840f904674b470be5d2b16c4ccca99c4f4/packages/core/src/core/run-handler.ts).

**6. The inbound bridge and identity mapping**

Use an Agent-level protocol integration with these responsibilities:

1. Decode and validate protocol input into a new turn, approval answers, or external results.
2. Resolve the authenticated thread and its active durable request.
3. Apply the current request's frontend tool catalog and server-side authorization rules.
4. Translate answers into an addressed `ResumeInput` and stream `events()` for continuation.
5. Configure the outbound adapter with the conversation identities and client profile needed for that segment.

[Agent::toolResults()/toolApprovalDecisions()](/mnt/c/GitHub/neuron-ai/src/Agent/Agent.php:498) already provide convenient application sugar. An HTTP bridge should prefer [addressed continuation](/mnt/c/GitHub/neuron-ai/src/Workflow/Workflow.php:397) with `ResumeInput::event()` and the existing run/attempt fences where available. A name-only signal should not accidentally attach a stale browser response to a later wait on the same thread.

On a fresh Agent, [`getState()`](/mnt/c/GitHub/neuron-ai/src/Workflow/ResolveState.php:28) creates the default in-memory state; it does not inspect persistence. [WorkflowRunStore](/mnt/c/GitHub/neuron-ai/src/Workflow/Executor/WorkflowRunStore.php:186) already loads checkpoints/outcomes. Expose or reuse a narrow read-only inspection seam for current control and pending requests, rather than teaching HTTP controllers reserved persistence keys or running nodes merely to render a pending UI.

AG-UI's wire `runId` changes between invocations; Neuron's durable run survives multiple invocations. Do not pass the new wire ID as Neuron's `expectedRunId`. Standard interrupt IDs must map to the active engine request and call; several protocol answers can become one cumulative Neuron payload. No one-interrupt-per-tool change is needed in Workflow.

For standard interrupts, an opaque identifier incorporating the durable request generation and the call ID is safer than reusing the call ID alone for both approval and execution phases. Keep `toolCallId` unchanged as the conversation correlation key. The integration owns this mapping.

On input, distinguish standard approval `resume[]` from tool messages even if the client sends both. In particular, a tool-bound approval response represented in client history is not the external execution result. Normalize once according to the active request type.

Both clients may send the whole conversation. Do not append that entire history on every HTTP request, and do not append their tool results directly before AwaitToolResultsNode constructs the authoritative combined result message. Extract only answers relevant to the active batch; treat older tool parts as history. Define retry/idempotency behavior across completed HTTP requests as well as Neuron's existing partial-result deduplication.

**7. Dynamic schemas and result representation**

[DeferredTool](/mnt/c/GitHub/neuron-ai/src/Tools/DeferredTool.php) already accepts the fields needed by AG-UI. A protocol decoder can map `parameters` to `inputSchema`; an `AGUIFrontendTool` subclass is not needed just to rename fields.

The shared [ToolPropertyFactory](/mnt/c/GitHub/neuron-ai/src/Tools/ToolPropertyFactory.php:29) supports a subset of JSON Schema. It rejects references, composition keywords, tuple schemas, and multi-type unions. A valid frontend schema can therefore still be unsupported by Neuron. Test actual schemas generated by the supported client versions and document the subset. Broader JSON Schema support is a separate, explicit Tools decision; silently flattening or discarding unsupported constraints is unsuitable.

Frontend availability is per request. [addTool()](/mnt/c/GitHub/neuron-ai/src/Agent/HandleTools.php:161) appends; repeatedly adding client catalogs to a reused Agent would accumulate stale offerings. Start with a fresh Agent per HTTP request or a clearly owned request-scoped catalog. Reject ambiguous names against backend tools instead of letting array order choose the implementation.

After external dispatch, the captured event supplies the outstanding calls even if their definitions disappear. Re-supply definitions for future inference calls, and for a continuation that still has to pass ToolNode's approval/dispatch phase. This is more precise than requiring every original schema on every results resume.

Neuron normalizes results to model-facing strings or ToolOutput. Vercel supports arbitrary JSON outputs, so echoing a normalized result can replace a frontend object with a JSON string. Decide whether the integration preserves the validated original UI output or documents string-only echoing. Widening ToolCall's result model would be another separate design change.

Client schemas, history, and results are untrusted model-context input: authorize the thread and exposed capabilities, and correlate results against the server's persisted calls rather than client-supplied names or execution flags.

**8. Recommended implementation sequence**

1. **Pin the client targets and add conformance fixtures.** Include official schema parsing and client state reducers, not only PHP string assertions. Reproduce the confirmed Vercel defects before fixing them.
2. **Repair the existing outbound contract.** Call IDs, message/part lifecycle, errors, and continuation message ownership come first. Normalize missing provider call IDs once before persistence; adapter-local invented IDs cannot identify a later backend result.
3. **Implement ordinary frontend execution for AG-UI/CopilotKit.** Parse tool catalogs and tool messages; adapt a Neuron results suspension to the normal frontend-tool handoff; resume through the existing engine.
4. **Implement Vercel tool-part input and continuation.** Support static and dynamic parts, original UI output types, and an explicit opt-in body shape for client tool catalogs.
5. **Implement standard interrupt input and approved frontend dispatch together.** Include the required client gate and correlation across both approval and execution phases. This cannot be judged by outbound frames alone.
6. **Complete reload and retry behavior.** Stable message mapping, snapshots, invalid/stale input responses, repeated submissions, and the chosen reconnect contract.

Keep protocol field names out of ToolNode, AwaitToolResultsNode, and Workflow. The existing stream interface can stay outbound. A small Agent protocol bridge can own input translation and supply protocol-aware interruption presentation. If multiple implementations repeat the same interruption mapping, extract that specific seam then; a universal bidirectional adapter framework is not a prerequisite.

Execution-enabling frontend events should be released at the committed dispatch boundary where the client can safely act on them. ToolArgumentChunk remains useful for preview, but currently contains no deferred or approval phase metadata. Do not infer permission to execute from argument completeness alone.

**9. Acceptance tests before claiming compatibility**

- Run ordinary local/deferred/mixed batches through real AG-UI/CopilotKit and Vercel client state handling, using a fresh backend and adapter for each request.
- Execute two calls with the same name and distinct IDs; verify every result lands on the correct call.
- Exercise text, reasoning, tool-only, empty-input, and multi-inference streams against official schemas and lifecycle checks.
- Submit partial results, repeated identical results, conflicting results, null/false/object outputs, and failures/cancellation; verify no duplicate execution or history.
- Approve, reject, and partially answer a mixed approval batch, then deliver external results; assert the frontend handler never runs before backend dispatch and never runs for a rejection.
- Verify CopilotKit automatic follow-up uses the intended tool-message path without a standard-interrupt deadlock.
- Verify standard AG-UI resumes cover all published interrupts, use the correct phase identity, and reject stale/unknown input through the protocol error contract.
- Reload during approval and during external execution, restoring UI message IDs and pending state without treating stream reconnect as tool completion.
- Change or remove the frontend catalog between requests; reject name collisions and unsupported schemas explicitly.

The engine tests already provide a strong base. The next milestone should be a tested end-to-end protocol session, rather than adding another event name to `suspended()` and declaring the integration complete.
