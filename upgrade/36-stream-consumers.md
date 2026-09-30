# Upgrade: Consuming streams: attach the adapter and frame ProtocolEvents

## Summary

In 3.x the stream adapter was an argument of the handler's `events()` call, and the stream yielded ready-made SSE strings (`"data: {...}\n\n"`). In 4.x the adapter is attached to the Agent, RAG or Workflow with a factory closure. The stream then yields `NeuronAI\Workflow\Streaming\ProtocolEvent` objects, and the endpoint frames them with `NeuronAI\Workflow\Streaming\SSEEncoder`.

| 3.x | 4.x |
|---|---|
| `$agent->stream($m)->events($adapter)`, `->streamEvents($adapter)` (same on RAG) | `$agent->setStreamAdapter(fn (): VercelAIAdapter => $adapter)->stream($m)` |
| `$workflow->init()->events($adapter)`, `->start()->streamEvents($adapter)` | `$workflow->setStreamAdapter(fn (): MyAdapter => new MyAdapter())->events()` |
| The stream yields SSE strings, and `echo $line` sends them | The stream yields `ProtocolEvent` objects. `echo $event` is a fatal error (`could not be converted to string`). Use `SSEEncoder::encode($stream)` for HTTP and `SSEEncoder::frame($event)` for a single event |
| `$handler->run()` / `$handler->getMessage()` after the loop | `$lines->getReturn()` / `$lines->getReturn()->getMessage()` |
| `new AGUIAdapter()`, `new AGUIAdapter(null, $runId)` | `new AGUIAdapter($threadId, $runId, $messages, $state)`: the thread ID is required |
| `StreamAdapterInterface::getHeaders()` | Removed from the interface. `AGUIAdapter` and `VercelAIAdapter` still declare it |
| An exception mid-stream sent no frame. The endpoint often wrote its own error frame | The adapter sends its error frames (`RUN_ERROR` / `error`, text `The run failed.`), then the exception is rethrown |

`setStreamAdapter()` takes a closure. Passing an adapter instance is a `TypeError`. The factory runs once per execution (`stream()`, `events()`, `run()`, `chat()`, a continuation), when that execution starts.

No stored data is involved: adapters and their frames were never persisted.

Handled by other guides:
- Adapter classes, their namespaces and custom adapters: guide 35 (already applied).
- `catch (WorkflowInterrupt ...)` around these loops, and the pause frames that the catch wrote: guides 15 and 29 (already applied). With an adapter, a pause now ends the stream with the adapter's own pause frames, and the returned state reports `isInterrupted()`.
- The frames the built-in adapters send, and the frontends and tests that read them: guide 37.
- Loops over streams without an adapter (raw chunks): guide 23 for agents, guides 12 and 15 for workflows, guide 41 for provider streams.

## What to Search For

Run from the application root:

```bash
# 1. Streams that receive an adapter: events()/streamEvents() called with an argument
grep -rnE -e '->(events|streamEvents)\(([^)]|$)' --include='*.php' --exclude-dir=vendor .

# 2. Adapters that are constructed, typed, injected or attached
grep -rnwE 'AGUIAdapter|VercelAIAdapter|StreamAdapterInterface|setStreamAdapter|streamAdapter' --include='*.php' --exclude-dir=vendor .

# 3. Code that treats adapter output as SSE strings, or drives an adapter by hand
grep -rnE -e "[\"']data: |text/event-stream|RUN_ERROR|errorText|substr\([^,]+, *6[,)]" -e '[aA]dapter->(start|transform|end|getHeaders)\(' --include='*.php' --exclude-dir=vendor .
```

Follow the hits:
- Search 1: keep the calls whose argument is an adapter. Skip 4.x `events(ExecutionRequest ...)` calls. Trace the receiver back to where it was created: `$agent->stream(...)`, `$agent->chat(...)`, `$workflow->init()`/`start()`, or a continuation that guide 15 or 29 wrote (`submitInputs(...)`, `submitApprovalDecisions(...)`, `submitToolResults(...)`). Then follow the generator to every place its items are echoed, sent, stored or asserted, and to any later `$handler->run()` or `$handler->getMessage()` on the same handler.
- Search 2: the `new AGUIAdapter(` calls (Case 4), container bindings of the adapter classes, and parameters or properties typed `StreamAdapterInterface` that call `getHeaders()` (Case 7).
- Search 3: hand-written error frames (Case 5), string consumers and adapters driven by hand (Case 6), and tests (Case 8). A `getHeaders()` hit on an HTTP request or response object is unrelated.

Check controllers, route files, queued jobs, broadcast events, console commands and tests. If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: An Agent or RAG stream through an adapter

Before (guide 13 bound the thread ID, guide 35 moved the adapter import, guide 23 left the handler in place):

```php
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\UserMessage;

public function chat(Request $request, string $threadId): StreamedResponse
{
    $adapter = new VercelAIAdapter();
    $handler = MyAgent::make(workflowId: $threadId)
        ->stream(new UserMessage($request->input('message')));
    $stream = $handler->events($adapter);            // or ->streamEvents($adapter)

    return response()->stream(function () use ($stream): void {
        foreach ($stream as $line) {
            echo $line;
            ob_flush();
            flush();
        }
    }, 200, $adapter->getHeaders());
}
```

After:

```php
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use Throwable;

public function chat(Request $request, string $threadId): StreamedResponse
{
    $adapter = new VercelAIAdapter();
    $stream = MyAgent::make(workflowId: $threadId)
        ->setStreamAdapter(fn (): VercelAIAdapter => $adapter)
        ->stream(new UserMessage($request->input('message')));
    $stream->valid(); // admission runs on the first iteration: refusals throw here, before any header

    return response()->stream(function () use ($stream, $adapter): void {
        try {
            foreach (SSEEncoder::encode($stream) as $line) {
                echo $line;
                ob_flush();
                flush();
            }
        } catch (Throwable $e) {
            report($e);
            foreach ($adapter->error($e) as $event) {   // sends nothing if the adapter already did
                echo SSEEncoder::frame($event);
                flush();
            }
        }
    }, 200, $adapter->getHeaders());
}
```

1. Call `setStreamAdapter()` on the agent before `stream()`, and remove the `->events($adapter)` / `->streamEvents($adapter)` call. The generator that `stream()` returns is the stream.
2. Wrap the generator with `SSEEncoder::encode()` wherever its items reach an HTTP response. The framing is the 3.x `data: {...}\n\n`.
3. Pick the factory:
   - `fn (): VercelAIAdapter => $adapter`, which returns a captured instance, is correct when this agent object runs one stream. That is the usual per-request endpoint. The instance also stays available for `getHeaders()`.
   - If the same object runs more than one stream (a loop, a long-lived worker), build the adapter inside the factory: `fn (): VercelAIAdapter => new VercelAIAdapter()`. Read the headers from a separate instance: `(new VercelAIAdapter())->getHeaders()`. An adapter that has finished a stream emits nothing more.
   - On a shared instance (container singleton, service), attach the adapter to the copy that guide 13's `for()` returns, never to the shared object: `$this->agent->for($threadId)->setStreamAdapter(fn (): VercelAIAdapter => $adapter)->stream($message)`.
   - Optional: a class that always streams through one adapter can override `protected function streamAdapter(): ?StreamAdapterInterface { return new VercelAIAdapter(); }` (`use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;`). A factory passed to `setStreamAdapter()` takes precedence over it.
4. Replace reads of the 3.x handler after the loop. Call `getReturn()` only after the loop has finished:

   | 3.x, after the loop | 4.x |
   |---|---|
   | `$handler->run()` | `$lines->getReturn()`, with `$lines = SSEEncoder::encode($stream)`. `$stream->getReturn()` returns the same `AgentState` |
   | `$handler->getMessage()` | `$lines->getReturn()->getMessage()`. It is nullable, and on a paused run it is not the final answer: check `isInterrupted()` first |

   ```php
   $lines = SSEEncoder::encode($stream);
   foreach ($lines as $line) {
       echo $line;
       flush();
   }
   $state = $lines->getReturn();       // AgentState
   ```
5. Keep sending the adapter's `getHeaders()`. The headers are unchanged from 3.x.
6. `$agent->chat($m)->events($adapter)`: 4.x `chat()` returns the state and yields nothing. Use `stream($m)` as above, and tell the developer that the provider now streams that turn.
7. Keep `stream()` outside the response callback, and prime it there with `$stream->valid()` before returning the response. `stream()` throws at call time only when no thread ID is bound. Admission refusals (`RunInFlightException`, guide 13 Case 7 and guide 29; `StaleWorkflowRunException` or a stale-attempt `WorkflowException` on continuations) and failures while building the run are thrown by the first iteration, before the adapter sends any frame. Priming throws them in the controller, where the handling from guides 13 and 29 can turn them into an HTTP error. After priming, iterate only through `SSEEncoder::encode($stream)`, which continues a started generator. With `VercelAIAdapter`, priming returns at the first provider chunk.

### Case 2: A Workflow stream through an adapter

Before (guide 12 left the handler in place; `ProgressAdapter` is an application adapter that guide 35 migrated):

```php
$handler = $workflow->init();                          // or ->start()
foreach ($handler->events(new ProgressAdapter()) as $line) {   // or ->streamEvents(...)
    echo $line;
    flush();
}
$state = $handler->run();
```

After:

```php
use NeuronAI\Workflow\Streaming\SSEEncoder;

$lines = SSEEncoder::encode(
    $workflow->setStreamAdapter(fn (): ProgressAdapter => new ProgressAdapter())->events()
);
foreach ($lines as $line) {
    echo $line;
    flush();
}
$state = $lines->getReturn();                          // WorkflowState
```

1. Delete the argument-less `init()`/`start()` that created the handler. Attach the adapter with `setStreamAdapter()` and call `events()` with no argument. Passing the adapter to `$workflow->events(...)` is a `TypeError`, because that parameter is now `?ExecutionRequest`.
2. Replace `$handler->run()` after the loop with `$lines->getReturn()`.
3. The factory rules of Case 1, step 3 apply, as do the `streamAdapter()` hook and `for()` on shared instances.

### Case 3: Streamed continuations

Guides 15 and 29 turned the 3.x continuations (`$workflow->init($request)->events($adapter)`, `$agent->stream([], $request)->events($adapter)`) into `submitInputs()`, `submitApprovalDecisions()` or `submitToolResults()`. They left the adapter argument in place. If a continuation still passes a request to `init()`, `start()` or `stream()`, apply guide 15 (workflows) or guide 29 (agents) to it first.

Before:

```php
$lines = $workflow->submitInputs($answer)->events(new ProgressAdapter());

$stream = $agent->submitApprovalDecisions($decisions)->events($adapter);
```

After:

```php
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Workflow\Streaming\SSEEncoder;

$lines = SSEEncoder::encode(
    $workflow->setStreamAdapter(fn (): ProgressAdapter => new ProgressAdapter())
        ->submitInputs($answer)
        ->events()
);

$stream = $agent->setStreamAdapter(fn (): AGUIAdapter => $adapter)
    ->submitApprovalDecisions($decisions)
    ->events();
$lines = SSEEncoder::encode($stream);
```

1. `PendingExecution::events()` takes no argument, and PHP silently ignores the extra one. Left as it is, the stream yields raw chunks instead of protocol events. Attach the adapter to the agent or workflow and call `->events()` with no argument. The same applies after `submitToolResults(...)`.
2. Frame and read the state as in Case 1, including priming the continuation's generator in the controller (Case 1, step 7). After the loop, `$stream->getReturn()->isInterrupted()` is `true` when the run paused again.
3. AG-UI: build the continuation's adapter from the same request, with its `messages` and `state` (Case 4).
4. Vercel: `new VercelAIAdapter()` still works. If the client sends its last assistant message with the continuation (AI SDK tool approvals, guide 37), use `new VercelAIAdapter($last['id'], $last['parts'] ?? [])`. The reply then continues that message instead of starting a new one.

### Case 4: Constructing `AGUIAdapter`

3.x: `__construct(?string $threadId = null, ?string $runId = null)`, which generated a thread ID when none was given. 4.x: `__construct(string $threadId, ?string $runId = null, array $messages = [], array $state = [])`.

Before:

```php
$adapter = new AGUIAdapter($input['threadId'] ?? null, $input['runId'] ?? null);
// or: new AGUIAdapter()  /  new AGUIAdapter(null, $runId)
```

After:

```php
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Exceptions\InputTranslationException;

try {
    $adapter = new AGUIAdapter(
        $threadId,                        // the thread ID bound on the agent (guide 13)
        $input['runId'] ?? null,
        $input['messages'] ?? [],
        $input['state'] ?? [],
    );
} catch (InputTranslationException $e) {
    return response()->json(['error' => $e->getMessage()], 422);
}

$stream = $agent->setStreamAdapter(fn (): AGUIAdapter => $adapter)->stream($message);
```

1. Pass the thread ID that the agent is bound to. For AG-UI that is the RunAgentInput `threadId`, once the caller is authorized for it. Do not generate a new one.
2. Pass the RunAgentInput `messages` and `state`. The adapter uses them to build the `STATE_SNAPSHOT` and `MESSAGES_SNAPSHOT` frames it sends when the run pauses. Without them, the snapshot holds only the messages this stream produced.
3. Each seeded message needs a non-empty string `id`, each tool call an `id`, and each tool message a `toolCallId`. Otherwise the constructor throws `InputTranslationException`. Build the adapter before streaming, as shown, so that a malformed request gets a 4xx response. A factory that constructs the adapter throws only once the stream is iterated, after the headers have been sent.
4. Container bindings or autowiring of `AGUIAdapter` without arguments now fail. Remove them and construct the adapter in the endpoint, where the thread ID is known.
5. `new VercelAIAdapter()` is unchanged.

### Case 5: Error frames the endpoint wrote itself

Before:

```php
try {
    foreach ($stream as $line) {
        echo $line;
        flush();
    }
} catch (Throwable $e) {
    report($e);
    echo 'data: ' . json_encode(['type' => 'RUN_ERROR', 'message' => $e->getMessage()]) . "\n\n";
    flush();
}
```

After:

```php
use NeuronAI\Workflow\Streaming\SSEEncoder;

try {
    foreach (SSEEncoder::encode($stream) as $line) {
        echo $line;
        flush();
    }
} catch (Throwable $e) {
    report($e);
    foreach ($adapter->error($e) as $event) {   // sends nothing if the adapter already sent RUN_ERROR
        echo SSEEncoder::frame($event);
        flush();
    }
}
```

1. When a streamed run fails, the adapter's error frames are sent first: AG-UI `RUN_ERROR` with `message`, Vercel `error` with `errorText`, both with the text `The run failed.`. The exception is then rethrown. Delete every hand-written error frame, otherwise the client receives two terminal frames. In the catch, send `$adapter->error($e)` framed with `SSEEncoder::frame()`: it yields nothing when the run already sent its error frames, and it closes the protocol when the run failed before any frame.
2. If the 3.x frame sent `$e->getMessage()` or other text to the client, ask the developer which text clients may see. To send more than the neutral text, subclass the adapter and override the hook, returning only safe text:

   ```php
   use NeuronAI\Agent\Adapters\AGUIAdapter;
   use Throwable;

   class AppAGUIAdapter extends AGUIAdapter
   {
       protected function errorMessage(Throwable $error): string
       {
           return $error instanceof QuotaExceeded ? 'You have run out of credits.' : parent::errorMessage($error);
       }
   }
   ```
3. Exceptions thrown before the first frame still need the endpoint's own HTTP error response: a missing thread or workflow ID from `stream()`/`events()`, `InputTranslationException` from the adapter constructor or from `submitInputs()`, and admission refusals (`RunInFlightException`, stale continuations), which reach the controller only if the generator is primed there (Case 1, step 7).

### Case 6: Consumers other than an HTTP response

This covers broadcasts, queued jobs, Redis or cache writes, callbacks typed `string $line`, and code that calls an adapter's methods itself.

Before:

```php
foreach ($agent->stream($message)->events(new VercelAIAdapter()) as $line) {
    broadcast(new ChatChunk($threadId, $line));
}

$onLine = function (string $line): void {
    $payload = json_decode(substr($line, 6, -2), true);
    if ($payload['type'] === 'text-delta') {
        echo $payload['delta'];
    }
};

foreach ($adapter->transform($chunk) as $line) {
    echo $line;
}
```

After:

```php
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Streaming\SSEEncoder;

$stream = $agent->setStreamAdapter(fn (): VercelAIAdapter => new VercelAIAdapter())->stream($message);
foreach ($stream as $event) {
    broadcast(new ChatChunk($threadId, SSEEncoder::frame($event)));
}

$onEvent = function (ProtocolEvent $event): void {
    if ($event->type === 'text-delta') {
        echo $event->data['delta'];
    }
};

foreach ($adapter->transform($chunk) as $event) {
    echo SSEEncoder::frame($event);
}
```

1. Retype `string $line` parameters, properties and docblocks as `ProtocolEvent $event`.
2. To transmit the event, send `SSEEncoder::frame($event)` for the 3.x `data: {...}\n\n` string, or `json_encode($event)` for the bare JSON object, which has `type` first. Choose whichever the receiving side parsed in 3.x.
3. Replace parsing of the string (`substr($line, 6, -2)`, `json_decode($line)`) with `$event->type` and `$event->data` (the payload without `type`).
4. Code that calls `$adapter->start()`, `transform()` or `end()` itself receives `ProtocolEvent`s: frame each one.

### Case 7: `getHeaders()` on a value typed `StreamAdapterInterface`

Before (guide 35 moved the import):

```php
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;

protected function sseHeaders(StreamAdapterInterface $adapter): array
{
    return $adapter->getHeaders();
}
```

After:

```php
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Adapters\VercelAIAdapter;

protected function sseHeaders(AGUIAdapter|VercelAIAdapter $adapter): array
{
    return $adapter->getHeaders();
}
```

Retype every parameter, property or variable that calls `getHeaders()` to the concrete class that declares it: `AGUIAdapter`, `VercelAIAdapter`, or the application adapter that guide 35 gave a `getHeaders()` method. A helper that received a 3.x handler and an adapter now receives the `Generator` from `stream()`/`events()`, with the adapter already attached at the call site (Case 1).

### Case 8: Tests

Before:

```php
$frames = iterator_to_array($agent->stream($message)->events(new VercelAIAdapter()), false);
$decoded = array_map(fn (string $line) => json_decode(substr($line, 6, -2), true), $frames);
```

After:

```php
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Workflow\Streaming\ProtocolEvent;

$frames = iterator_to_array(
    $agent->setStreamAdapter(fn (): VercelAIAdapter => new VercelAIAdapter())->stream($message),
    false,
);
$decoded = array_map(fn (ProtocolEvent $event): array => json_decode(json_encode($event), true), $frames);
```

- To assert on bytes, compare `SSEEncoder::frame($event)`.
- The built-in adapters send different frame sequences in 4.x. Update the expected frames with guide 37 instead of assuming the 3.x ones still hold.
- Tests that expected an error frame written by the endpoint now receive the adapter's frame (Case 5).

## Checklist

- No search 1 hit passes an adapter: no `->events(<adapter>)` or `->streamEvents(` call remains, including after `submitInputs(`, `submitApprovalDecisions(` and `submitToolResults(`.
- Every adapter is attached with `setStreamAdapter(fn () => ...)` (or the `streamAdapter()` hook) before `stream()`/`events()`. A captured instance is used only where the object runs one stream, and shared instances are attached through `for()`.
- Every adapted stream that reaches an HTTP response goes through `SSEEncoder::encode()`. Every other consumer uses `SSEEncoder::frame()`, `json_encode()` or `$event->type`/`$event->data`. Nothing echoes or concatenates a `ProtocolEvent`.
- Endpoints still send the adapter's `getHeaders()`, and no `getHeaders()` call goes through a `StreamAdapterInterface` type.
- No `$handler->run()` or `$handler->getMessage()` remains. The final state comes from `getReturn()` after the loop.
- Every `new AGUIAdapter(` passes the bound thread ID first, plus the request's `messages` and `state` where the request has them. `InputTranslationException` from it becomes a 4xx response.
- No endpoint writes its own error or pause frame into an adapted stream.
- PHPStan reports no error about `events()`, `streamEvents()`, `setStreamAdapter()`, `getHeaders()` or `AGUIAdapter::__construct()` in the files you changed. At level 7 or higher, `SSEEncoder::encode()`/`frame()` on a `stream()`/`events()` generator reports `argument.type` (object given, ProtocolEvent expected), because those generators are also typed for raw chunks: that report is expected on correct code, do not change the call to silence it.
