# Upgrade: Stream adapter classes: new namespaces and the 4.x adapter contract

## Summary

- The adapter classes left `NeuronAI\Chat\Messages\Stream\Adapters`. There is no alias at the old location.

| 3.x | 4.x |
|---|---|
| `NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface` | `NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface` |
| `NeuronAI\Chat\Messages\Stream\Adapters\AGUIAdapter` | `NeuronAI\Agent\Adapters\AGUIAdapter` |
| `NeuronAI\Chat\Messages\Stream\Adapters\VercelAIAdapter` | `NeuronAI\Agent\Adapters\VercelAIAdapter` |
| `NeuronAI\Chat\Messages\Stream\Adapters\SSEAdapter` (abstract base with `sse()`, `generateId()`, `getHeaders()`) | Removed: implement the interface directly (Case 2) |

- The interface contract changed. Every method now yields `NeuronAI\Workflow\Streaming\ProtocolEvent` objects instead of `"data: {...}\n\n"` strings.

| 3.x `StreamAdapterInterface` | 4.x `StreamAdapterInterface` |
|---|---|
| `transform(object $chunk): iterable`, `start(): iterable`, `end(): iterable`, all yielding strings | Same signatures, yielding `ProtocolEvent`s |
| none | `interrupt(NeuronAI\Workflow\Interrupt\InterruptRequest $request): iterable`: called instead of `end()` when the run pauses |
| none | `error(Throwable $error): iterable`: called instead of `end()` when the run fails; the exception is rethrown afterwards |
| `getHeaders(): array` | Not on the interface any more. `AGUIAdapter` and `VercelAIAdapter` still declare it and return the 3.x headers |

- `new ProtocolEvent(string $type, array $data = [])` has public readonly `$type` and `$data`. `json_encode($event)` gives `{"type": <type>, ...data}` with `type` first. A `type` key inside `$data` is dropped.
- The protected hooks of `AGUIAdapter` and `VercelAIAdapter` changed, which affects application subclasses (Case 3).
- Owned by other guides:
  - Attaching an adapter, framing its events with `NeuronAI\Workflow\Streaming\SSEEncoder`, `new AGUIAdapter(...)` call sites and `getHeaders()` called through a `StreamAdapterInterface` type: guide 36.
  - The frames the built-in adapters send: guide 37.
  - `$chunk->tool` on tool chunks being a `ToolCall`: guide 39.
- This guide changes code only. No stored data is involved.

## What to Search For

```bash
grep -rnE 'Stream\\+(Adapters\\+|\{)' --include='*.php' --include='*.yaml' --include='*.yml' --include='*.neon' --include='*.xml' --include='*.json' --exclude-dir=vendor --exclude-dir=node_modules .
grep -rnwE 'SSEAdapter|AGUIAdapter|VercelAIAdapter|StreamAdapterInterface' --include='*.php' --exclude-dir=vendor .
grep -rnE 'extends +[A-Za-z\\]*(SSEAdapter|AGUIAdapter|VercelAIAdapter)\b|implements .*StreamAdapterInterface' --include='*.php' --exclude-dir=vendor .
grep -rnE '\$this->(sse|generateId)\(|function +(resolveToolCallId|handleText|handleReasoning|handleToolCall|handleToolResult)\(' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:
- The first search finds imports, fully qualified names, `::class` constants, group imports and class names in strings. It matches both single backslashes (PHP, plain YAML) and doubled ones (JSON, double-quoted strings). Every hit goes to Case 1.
- The second search finds short-name uses, including members of group imports the first search cannot see. It finds call sites too: leave those for guide 36 (Case 1, step 4).
- The third search finds adapter classes:
  - A class that extends `SSEAdapter` or implements `StreamAdapterInterface` goes to Case 2.
  - A class that extends `AGUIAdapter` or `VercelAIAdapter` goes to Case 3.
  - Also follow subclasses of the classes you find: an application base adapter passes the change on to its children.
- The fourth search finds the bodies to convert. Only hits inside adapter classes, or traits they use, count.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: References to the moved classes

Apply the table to every reference: imports, fully qualified names, `::class` constants, type hints, and class names in strings or config files. Keep each file's escaping: JSON and double-quoted PHP strings need doubled backslashes.

Before (3.x):
```php
use NeuronAI\Chat\Messages\Stream\Adapters\AGUIAdapter;
use NeuronAI\Chat\Messages\Stream\Adapters\VercelAIAdapter;
```

After (4.x):
```php
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
```

1. Split group imports. The `Chunks` namespace did not move.

   Before (3.x):
   ```php
   use NeuronAI\Chat\Messages\Stream\{Adapters\AGUIAdapter, Chunks\TextChunk};
   ```

   After (4.x):
   ```php
   use NeuronAI\Agent\Adapters\AGUIAdapter;
   use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
   ```

   A group import that lists only `Chunks\...` members needs no change.
2. In PHP config, replace a string class name with the `::class` constant. For example, `'adapter' => 'NeuronAI\Chat\Messages\Stream\Adapters\VercelAIAdapter'` becomes `'adapter' => \NeuronAI\Agent\Adapters\VercelAIAdapter::class`. In YAML, XML, NEON and JSON files, write the new name as a string.
3. Leave `SSEAdapter` references for Case 2. The class has no 4.x name.
4. Leave call sites in their 3.x shape: `->events($adapter)`, `->streamEvents($adapter)`, `new AGUIAdapter()` and loops that echo adapter output. Guide 36 migrates them, and those endpoints do not work until then.
5. Rely on the searches and on static analysis, not only on tests. Some stale references never fail: an `instanceof` check against an old name quietly evaluates to false, and `::class` strings and unused imports raise no error.

### Case 2: Custom adapters (extends `SSEAdapter` or implements `StreamAdapterInterface`)

Before (3.x):
```php
use NeuronAI\Chat\Messages\Stream\Adapters\SSEAdapter;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;

final class MyProtocolAdapter extends SSEAdapter
{
    public function start(): iterable
    {
        return [];
    }

    public function transform(object $chunk): iterable
    {
        if ($chunk instanceof TextChunk) {
            yield $this->sse(['type' => 'delta', 'id' => $this->generateId('msg'), 'text' => $chunk->content]);
        }
    }

    public function end(): iterable
    {
        yield $this->sse(['type' => 'done']);
        yield "data: [DONE]\n\n";
    }
}
```

After (4.x):
```php
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\UniqueIdGenerator;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use Throwable;

final class MyProtocolAdapter implements StreamAdapterInterface
{
    public function start(): iterable
    {
        return [];
    }

    public function transform(object $chunk): iterable
    {
        if ($chunk instanceof TextChunk) {
            yield new ProtocolEvent('delta', ['id' => UniqueIdGenerator::generateId('msg_'), 'text' => $chunk->content]);
        }
    }

    public function end(): iterable
    {
        yield new ProtocolEvent('done');
    }

    public function interrupt(InterruptRequest $request): iterable
    {
        yield new ProtocolEvent('paused', ['request' => $request->jsonSerialize()]);
    }

    public function error(Throwable $error): iterable
    {
        return [];
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }
}
```

1. Replace `extends SSEAdapter` with `implements StreamAdapterInterface`, imported from `NeuronAI\Workflow\Streaming\Adapter`. A class that already implemented the interface only changes the import.
2. Make every yielded item a `ProtocolEvent`.
   - `yield $this->sse(['type' => 'T', ...rest])` becomes `yield new ProtocolEvent('T', [...rest])`.
   - Once framed, the event produces the same JSON that 3.x sent, except that `type` always comes first.
   - A 3.x payload without a `type` key needs a type chosen, because every event carries one.
   - An adapter that yielded ready-made strings (`"data: {...}\n\n"`) converts the JSON it wrote in the same way.
3. `$this->generateId('x')` becomes `UniqueIdGenerator::generateId('x_')`, because the 3.x helper inserted the underscore itself. `$this->generateId()` becomes `UniqueIdGenerator::generateId()`.
4. Delete output that is not a JSON event: `data: [DONE]`, SSE `event:`, `id:` and `retry:` lines, and comments. An adapter cannot express them. If the client relies on one, tell the developer: the endpoint that frames the events (guide 36) has to write it.
5. Add `interrupt(InterruptRequest $request): iterable`.
   - The Workflow calls it instead of `end()` when the run pauses, for example when a tool needs approval or a node calls `interrupt()`. Yield what the client must learn about the pause.
   - The endpoint may write its own pause frame for this protocol once the stream stops. In 3.x that was a `catch (WorkflowInterrupt ...)` block; guides 15 and 29 turned it into an `isInterrupted()` check. If so, move that frame here, because guide 36 deletes hand-built frames.
   - Otherwise `return [];`. A 3.x adapter sent nothing on a pause either.
6. Add `error(Throwable $error): iterable`.
   - The Workflow calls it instead of `end()` when the run fails, and rethrows the exception after its events.
   - If the endpoint's `catch` block writes a failure frame for this protocol, move that frame here in the same way. Otherwise `return [];`.
   - Never put `$error->getMessage()` on the wire: it can carry provider URLs, response bodies and file paths.
7. `end()` is not called after `interrupt()` or `error()`. If the client needs a closing frame in those cases, yield it from those methods too.
8. Keep `start()`, `transform()` and `end()`. Return `[]` when there is nothing to send.
9. `getHeaders()` is no longer inherited and no longer part of the interface. Declare it when any code calls it on this adapter, as 3.x endpoints usually did. Return the four headers `SSEAdapter` returned, as in the After.
10. `transform()` receives every object the stream yields. That now includes `NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk`, as well as whatever application nodes yield. Return nothing for objects the protocol ignores, and give a `match (true)` a `default => []` arm.

### Case 3: Subclasses of `AGUIAdapter` or `VercelAIAdapter`

Keep `extends` and apply the Case 1 import.

Before (3.x):
```php
use NeuronAI\Chat\Messages\Stream\Adapters\AGUIAdapter;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;

class TracedAGUIAdapter extends AGUIAdapter
{
    public function __construct(?string $threadId = null, ?string $runId = null, protected string $traceId = '')
    {
        parent::__construct($threadId, $runId);
    }

    public function start(): iterable
    {
        yield from parent::start();
        yield $this->sse(['type' => 'CUSTOM', 'name' => 'trace', 'value' => $this->traceId]);
    }

    protected function resolveToolCallId(ToolCallChunk|ToolResultChunk $chunk): string
    {
        return $chunk->tool->getCallId() ?? 'call_' . $chunk->tool->getName();
    }
}
```

After (4.x):
```php
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Streaming\ProtocolEvent;

class TracedAGUIAdapter extends AGUIAdapter
{
    public function __construct(string $threadId, ?string $runId = null, protected string $traceId = '', array $messages = [], array $state = [])
    {
        parent::__construct($threadId, $runId, $messages, $state);
    }

    public function start(): iterable
    {
        yield from parent::start();
        yield new ProtocolEvent('CUSTOM', ['name' => 'trace', 'value' => $this->traceId]);
    }

    protected function resolveToolCallId(ToolCall $call): string
    {
        return $call->getCallId() ?? 'call_' . $call->getName();
    }
}
```

1. Convert every `$this->sse(...)` and `$this->generateId(...)` call as in Case 2, steps 2 and 3. Neither built-in adapter has these methods any more. Overridden hooks keep their names and `iterable` return types, but yield `ProtocolEvent`s.
2. `resolveToolCallId()` now takes a `ToolCall` on both built-in adapters, so an override becomes `resolveToolCallId(ToolCall $call): string`.
   - Import `NeuronAI\Tools\ToolCall`.
   - Replace `$chunk->tool` with `$call` in the body.
   - Where the subclass calls the method, pass `$chunk->tool`.
3. Constructors of `AGUIAdapter` subclasses.
   - The 4.x parent constructor is `__construct(string $threadId, ?string $runId = null, array $messages = [], array $state = [])`.
   - Pass a non-null thread ID to `parent::__construct()`: a subclass parameter `?string $threadId = null` becomes `string $threadId`.
   - Delete a redeclared `$threadId` property. The parent promotes `protected string $threadId`, and a `?string` redeclaration is fatal.
   - Guide 36 seeds AG-UI adapters with the client's messages and state. If the subclass declares its own constructor, append `array $messages = [], array $state = []` after its own parameters (existing positional calls keep working) and forward them to `parent::__construct()`, as in the After.
   - The code that constructs the subclass gets its thread ID from guide 36, in the same way as `new AGUIAdapter(...)`.
   - `VercelAIAdapter` subclasses need no constructor change.
4. Overrides of `handleText()`, `handleReasoning()`, `handleToolCall()`, `handleToolResult()`, `start()`, `end()`, `endText()` and `endReasoning()` must be rebuilt, because the parent emits different frames in 4.x. Start from `vendor/neuron-core/neuron-ai/src/Agent/Adapters/AGUIAdapter.php` or `VercelAIAdapter.php` and re-apply only the application's own change. The main differences:
   - `AGUIAdapter::handleToolCall()` emits nothing any more.
   - `TOOL_CALL_START`, `TOOL_CALL_ARGS` and `TOOL_CALL_END` come from `publishToolCall(ToolCall $call)`, through `startToolCall()`, `toolCall()` and `endToolCall()`. They are sent right before `TOOL_CALL_RESULT`, or when the run pauses.
   - `toolResult(string $toolCallId, string|ToolOutput|null $result): array` shapes the result message.
   - `VercelAIAdapter::handleToolCall()` only previews the input, through `previewTool(ToolCall $call)`. The `tool-output-*` frames come from `handleToolResult()`.
   - Streamed tool arguments arrive in a new hook, `handleToolArgument(ToolArgumentChunk $chunk)`.
   - Reading `$chunk->tool` is covered by guide 39.
5. 4.x added members to the built-in adapters. A subclass member with the same name now overrides the parent's, or stops the class from loading when its signature or type differs. Rename such subclass members and their uses, unless the override is deliberate.
   - Both adapters: `interrupt`, `error`, `errorMessage`, `mapEvent`, `resolveStreamEvent`, `handleStreamEvent`, `handleStepEvent`, `handleToolArgument`, `$eventMappings`, `$runFailed`, `$finished`.
   - `AGUIAdapter`: `publishToolCall`, `startToolCall`, `endToolCall`, `toolCall`, `toolResult`, `hydrate`, `seedId`, `uncommittedInput`, `assistant`, `content`, `part`, `media`, `acceptedResult`, `confirmations`, `interruption`, `withExpiry`, `$messages`, `$state`, `$parentMessageIds`, `$openToolCalls`, `$argumentDeltas`, `$knownResults`.
   - `VercelAIAdapter`: `resolveToolCallId`, `startMessage`, `beginInferenceStep`, `closeParts`, `publishToolArgument`, `previewTool`, `$messageId`, `$toolInputStarted`, `$textPartId`, `$reasoningPartId`, `$partSourceId`, `$afterToolResults`, `$stepStarted`, `$knownOutputs`, `$dispatchedTools`.
6. A `transform()` override still works if it yields `ProtocolEvent`s and hands everything else to `parent::transform()`.

## Checklist

- The first search returns nothing outside `vendor/` and `node_modules/`, except group imports that list only `Chunks\...` members.
- No `SSEAdapter` reference remains, and no adapter class calls `$this->sse(` or `$this->generateId(`.
- Every custom adapter implements `NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface` and declares `start()`, `transform()`, `end()`, `interrupt()` and `error()`. It yields only `ProtocolEvent` objects and sends no exception message.
- A custom adapter whose `getHeaders()` is called declares it.
- Every `resolveToolCallId()` override takes a `ToolCall`.
- `AGUIAdapter` subclasses pass a string thread ID to `parent::__construct()` and do not redeclare `$threadId`.
- No adapter subclass accidentally declares a member listed in Case 3, step 5.
- `vendor/bin/phpstan`, or loading each adapter class, reports no unknown class, unimplemented abstract method, incompatible declaration or non-`ProtocolEvent` generator value for the adapter classes. Errors at stream call sites, and from `getHeaders()` called through `StreamAdapterInterface`, remain until guide 36.
