# Upgrade: `workflowId` / `resumeToken` become `runId`

## Summary

The identifier of a workflow run and the "resume token" were always the same value with
two different names. They are now unified under a single name: **`runId`**.

What changed:

1. **`getWorkflowId()` is now `getRunId()`** — on `WorkflowInterface`, `Workflow`, and
   everything extending it (`Agent`, `RAG`).
2. **The constructor parameter `resumeToken` is now `runId`** — `Workflow::make(runId: ...)`,
   `Agent::make(runId: ...)`.
3. **`ToolCallMessage::setResumeToken()` / `getResumeToken()` are deprecated** — replaced by
   `setRunId()` / `getRunId()`. The old methods still work and delegate to the new ones;
   they will be removed in the next major version.
4. **Extension-point parameter names changed** — `PersistenceInterface`,
   `SchedulerInterface`, and `StepEngineInterface` methods now take `string $runId`
   instead of `string $workflowId`. Custom implementations keep working (PHP does not
   require overrides to match parameter names); only named-argument calls need updating.
5. **The database persistence column `workflow_id` is now `run_id`** — see the migration
   below.
6. **Stored-data keys renamed, with read fallbacks** — new writes use the `run_id` chat
   history metadata key and the `__runId` state key; data stored under the legacy
   `resume_token` / `__workflowId` keys is still read transparently, so in-flight
   suspended runs persisted before the upgrade remain resumable.

## Update your code

```php
// Before
$workflowId = $workflow->getWorkflowId();
$resumed = Workflow::make(resumeToken: $workflowId)->resume($payload);

// After
$runId = $workflow->getRunId();
$resumed = Workflow::make(runId: $runId)->resume($payload);
```

For tool approval UIs reading the suspended thread's tail message:

```php
// Before (deprecated, still works)
$token = $toolCallMessage->getResumeToken();

// After
$runId = $toolCallMessage->getRunId();
```

Serialized `tool_call` messages now carry the id under the `run_id` metadata key.
Messages stored with the legacy `resume_token` key are read transparently by
`getRunId()` — just keep preserving the metadata if you re-serialize messages
client-side.

## What to search for

```
grep -rn "getWorkflowId\|resumeToken\|getResumeToken\|setResumeToken\|workflow_id" --include="*.php" .
```
