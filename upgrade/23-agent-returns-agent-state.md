# Upgrade: Agent verbs return AgentState; stream() is a generator

## Summary

In 3.x, `chat()` and `stream()` returned a `NeuronAI\Agent\AgentHandler`, a lazy `WorkflowHandler` subclass. Nothing ran until the app called `run()`, `start()`, `getMessage()`, `events()` or `streamEvents()` on the handler. 4.x removes `AgentHandler`:

- `chat()` runs the turn before it returns, and returns the final `NeuronAI\Agent\AgentState`.
- `stream()` returns a lazy `Generator` of chunks. After the loop, its `getReturn()` is the final `AgentState`.
- `structured()` already ran immediately in 3.x. Its call sites do not change.

Everything here applies to `RAG`, which extends `Agent`.

| 3.x | 4.x |
|---|---|
| `$agent->chat($messages)` returned a lazy `AgentHandler` | `$agent->chat($messages, bool $stream = false): AgentState`, runs before returning |
| `$handler->run()` / `$handler->start()` on a `chat()` handler | the `AgentState` that `chat()` returned |
| `$handler->getMessage(): Message` | `AgentState::getMessage(): ?Message` (the same call compiles) |
| `$agent->stream($messages)` returned a lazy `AgentHandler` | `$agent->stream($messages): Generator` (lazy) |
| `$handler->events()` / `$handler->streamEvents()` on a `stream()` handler | iterate the generator itself |
| `$handler->run()` after the loop | `$stream->getReturn()` |
| `$agent->stream($m)->run()` / `->getMessage()` with no loop | `$agent->chat($m, stream: true)` / `->getMessage()` |
| `$agent->chat($m)->events()` (tool chunks only) | `$agent->events(ExecutionRequest::start(new AgentStartEvent([...])))` |
| `AgentState::getChatHistory()` / `setChatHistory()` | removed: `$agent->getChatHistory()` (Cases 4 and 5) |
| Verb overrides typed `AgentHandler` with an `?InterruptRequest $interrupt` parameter | 4.x signatures (Case 6) |
| `AgentHandler` types, imports and test doubles | `AgentState` or `Generator` (Case 3) |

Stored data is not affected. Handlers were never persisted. Guide 14 covers 3.x runs persisted with their state.

### Leave these to their guides

- **Pauses and resumes: guide 29.** This covers `catch (WorkflowInterrupt ...)` blocks around agent calls, and every `chat()`, `stream()` or `structured()` call that passes an `InterruptRequest`: named `interrupt:`, second positional argument of `chat()`/`stream()`, fourth of `structured()`. It also covers `getPendingActions()`, `approve()`, `reject()`, and `structured()` returning `null` on a pause. Leave those calls, and anything chained on them, as they are. Inside a `try`, rewrite the other agent calls as this guide says and leave the `catch` block alone.
- **Streams with a stream adapter: guide 36.** This covers `->events($adapter)` and `->streamEvents($adapter)` on a handler, for example `->events(new VercelAIAdapter())`. Leave the whole chain untouched: the `stream()` call, the `events()`/`streamEvents()` call, any later `$handler->run()`, and a helper that receives the handler only to call them.
- **Chat history inside nodes: guide 26. Inside middleware: guide 27.** The Agent's own `setChatHistory()` and `chatHistory()` hook: guide 31. Types and methods of the returned history (`ChatHistoryInterface`): guide 32.
- **Node classes and graph hooks: guide 26.**
- **Classes that implement `AgentInterface` directly: guide 57.**
- Guide 13 (already applied) bound a thread ID. `chat()`, `stream()`, `structured()` and `getChatHistory()` throw `AgentException: This agent has no thread ID` without one.

## What to Search For

Run from the application root:

```bash
# 1. The removed handler type and the deprecated stream alias
grep -rnE -e 'AgentHandler' -e '->streamEvents\(' --include='*.php' --exclude-dir=vendor .

# 2. Agent and RAG verb calls
grep -rnE -e '->(chat|stream|structured)\(' --include='*.php' --exclude-dir=vendor .

# 3. Chat history read or set on a state, resolveState(), and state() overrides
grep -rnE -e '->(getChatHistory|setChatHistory|resolveState)\(' -e 'function state\(' --include='*.php' --exclude-dir=vendor .

# 4. Verb overrides, and verbs rebuilt with compose()
grep -rnE -e 'function (chat|stream|structured)\(' -e '->compose\(' --include='*.php' --exclude-dir=vendor .
```

Sort the hits:

- **Search 2:** keep calls on an Agent or RAG instance: a class that extends `Agent` or `RAG` (directly or through another app class), a value typed `AgentInterface`, or `X::make(...)`. Provider calls such as `$provider->chat(...)` belong to guide 41. Framework helpers such as Laravel's `response()->stream(...)` are unrelated.
- **Follow every kept result.** Find the variable, property or return value that receives it, and every later `run()`, `start()`, `events()`, `streamEvents()` or `getMessage()` on it. Also follow the helpers it is passed to, and `yield from` or `iterator_to_array()` over its events.
- **Out of scope:** the hits listed under "Leave these to their guides".
- **Search 3:** keep `getChatHistory()` called on a state: a run result, `->run()->getChatHistory()`, `->getState()->getChatHistory()` or `resolveState()->getChatHistory()` (Case 4). Keep `setChatHistory()` called on a state: in a `state()` override or on a seed state (Case 5). Keep every `resolveState()` called on an Agent or RAG (`$agent->resolveState()`, or `$this->resolveState()` in their subclasses): guide 18 left all of them here (Case 4). `$agent->getChatHistory()` needs no change.
- **Search 4:** keep declarations in classes that extend `Agent` or `RAG`, and `$this->compose(...)` calls inside them (Cases 6 and 7). A `compose()` override is guide 26's. `function chat(` in a provider class is guide 41's.

Also check tests (mocks of `AgentHandler`), controllers, jobs, console commands and evaluators that call agents. If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: `chat()` results

Before (3.x):

```php
$handler = $agent->chat(new UserMessage('Hello'));
$state = $handler->run();                                            // or $handler->start()
$reply = $handler->getMessage()->getContent();

$reply = $agent->chat(new UserMessage('Hello'))->run()->getMessage()->getContent();

$reply = $agent->chat(new UserMessage('Hello'))->getMessage()->getContent();
```

After (4.x):

```php
$state = $agent->chat(new UserMessage('Hello'));                     // NeuronAI\Agent\AgentState
$reply = $state->getMessage()->getContent();

$reply = $agent->chat(new UserMessage('Hello'))->getMessage()->getContent();   // ->run() removed

$reply = $agent->chat(new UserMessage('Hello'))->getMessage()->getContent();   // unchanged
```

1. Remove `->run()` and `->start()` chained on a `chat()` result. Where the code called them on a handler variable, use the state that `chat()` returned.
2. `getMessage()` on a `chat()` result needs no change.
3. `chat()` now runs where it is called. In 3.x the turn ran at the first `run()`, `start()`, `getMessage()` or `events()` on the handler. So:
   - Move the `chat()` call to where the handler first ran: into the same `try` block, branch, loop or closure. Exceptions from the run are now thrown by `chat()`.
   - Calls that configure the agent between `chat()` and that point (`addTool()`, `setInstructions()`, ...) move before `chat()`.
   - A `chat()` whose handler nothing ran never called the model in 3.x. 4.x runs it. Keep the call and report it to the developer.
   - Never call `chat()` again to read the same result: every call is a new turn. Reuse `$state`. In 3.x, a second `run()` on the same handler returned the cached state.

### Case 2: `stream()` results without an adapter

Before (3.x):

```php
$handler = $agent->stream(new UserMessage('Hello'));

foreach ($handler->events() as $chunk) {                             // or $handler->streamEvents()
    if ($chunk instanceof TextChunk) {
        echo $chunk->content;
    }
}

$state = $handler->run();                                            // the result cached by the loop
$reply = $state->getMessage()->getContent();

$reply = $agent->stream(new UserMessage('Hello'))->getMessage()->getContent();   // no loop
```

After (4.x):

```php
$stream = $agent->stream(new UserMessage('Hello'));

foreach ($stream as $chunk) {
    if ($chunk instanceof TextChunk) {
        echo $chunk->content;
    }
}

$state = $stream->getReturn();                                       // NeuronAI\Agent\AgentState
$reply = $state->getMessage()->getContent();

$reply = $agent->chat(new UserMessage('Hello'), stream: true)->getMessage()->getContent();
```

- Call `getReturn()` only after the loop has finished, because it throws on a generator that has not returned yet.
- `$handler->getMessage()` after the loop becomes `$stream->getReturn()->getMessage()`.
- `yield from $handler->events()` becomes `yield from $agent->stream(...)`, and its value is the `AgentState`. `iterator_to_array($handler->events())` becomes `iterator_to_array($agent->stream(...))`.
- A `stream()` handler that was only run or asked for its message (`->run()`, `->start()`, `->getMessage()`, no loop) becomes `$agent->chat($m, stream: true)`. The provider still streams, as in 3.x.
- Keep the `instanceof` chunk filters. Guides 38 and 39 cover changes to the chunk classes. When a run pauses, the stream ends with an `InterruptEvent` instead of throwing: guide 29 handles it.

A 3.x loop over the `events()` of a **`chat()`** handler received only tool chunks (`ToolCallChunk`, `ToolResultChunk`), because the provider did not stream. The equivalent in 4.x starts the run through `events()`:

Before (3.x):

```php
$handler = $agent->chat(new UserMessage('Book a table'));
foreach ($handler->events() as $chunk) {
    if ($chunk instanceof ToolCallChunk) {
        $progress->toolStarted($chunk);
    }
}
$state = $handler->run();
```

After (4.x):

```php
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;

$stream = $agent->events(ExecutionRequest::start(new AgentStartEvent([new UserMessage('Book a table')])));
foreach ($stream as $chunk) {
    if ($chunk instanceof ToolCallChunk) {
        $progress->toolStarted($chunk);
    }
}
$state = $stream->getReturn();
```

`AgentStartEvent` takes an array of messages. Wrap a single message in `[...]`.

### Case 3: Types, helpers and test doubles

Before (3.x):

```php
use Generator;
use NeuronAI\Agent\AgentHandler;

class ReplyService
{
    public function reply(AgentHandler $handler): string
    {
        return $handler->getMessage()->getContent();
    }

    public function relay(AgentHandler $handler): Generator
    {
        foreach ($handler->events() as $chunk) {
            yield $chunk;
        }

        return $handler->run();
    }
}

$text = $service->reply($agent->chat($message));
$relay = $service->relay($agent->stream($message));
```

After (4.x):

```php
use Generator;
use NeuronAI\Agent\AgentState;

class ReplyService
{
    public function reply(AgentState $state): string
    {
        return $state->getMessage()->getContent();
    }

    public function relay(Generator $stream): Generator
    {
        foreach ($stream as $chunk) {
            yield $chunk;
        }

        return $stream->getReturn();
    }
}

$text = $service->reply($agent->chat($message));
$relay = $service->relay($agent->stream($message));
```

1. Retype parameters, properties, return types and `@var`/`@param`/`@return` docblocks. Use `AgentState` where the code needed the result, and `Generator` where it streamed. Remove `use NeuronAI\Agent\AgentHandler;`.
2. Guide 12 may have typed a shared helper `WorkflowState`. That type still accepts an `AgentState`. Narrow it to `AgentState` if the helper calls `getMessage()` or another `AgentState` method.
3. Test doubles: replace a mocked `AgentHandler` with a real state.

   Before (3.x):

   ```php
   $handler = $this->createMock(AgentHandler::class);
   $handler->method('getMessage')->willReturn(new AssistantMessage('Hi!'));
   $agent->method('chat')->willReturn($handler);
   ```

   After (4.x):

   ```php
   use NeuronAI\Agent\AgentState;
   use NeuronAI\Providers\ProviderResponse;

   $state = (new AgentState())->setResponse(new ProviderResponse(new AssistantMessage('Hi!')));
   $agent->method('chat')->willReturn($state);
   ```

### Case 4: Reading a run's state

The 3.x state held the chat history. The 4.x state does not. Read the conversation from the agent.

Before (3.x):

```php
$history = $agent->chat($message)->run()->getChatHistory();

$state = $agent->chat($message)->run();
$history = $state->getChatHistory();

$history = $agent->resolveState()->getChatHistory();
```

After (4.x):

```php
$state = $agent->chat($message);
$history = $agent->getChatHistory();
```

| 3.x | 4.x |
|---|---|
| `$state->getChatHistory()`, on the state of a run | `$agent->getChatHistory()` |
| `$agent->resolveState()->getChatHistory()` | `$agent->getChatHistory()` |
| `$agent->resolveState()->get('key')`, `->getSteps()` or any other read after a run | the same call on the state the run returned: `$state = $agent->chat($m); $state->get('key')`, or `$stream->getReturn()->get('key')` |
| `$agent->resolveState()->set('key', $value)` before a run | a seed state: `SupportAgent::make($threadId, new SupportState(['key' => $value]))` or `->setState(new SupportState(['key' => $value]))` |
| `$this->resolveState()` in an Agent/RAG subclass, outside nodes and middleware | no 4.x equivalent. Ask the developer what the value is for: nodes and middleware receive `$state`, and callers read the state the run returns |
| `$interrupt->getState()->getChatHistory()` in a `catch (WorkflowInterrupt $interrupt)` block | `$agent->getChatHistory()`. Rewrite only this expression and leave the `catch` block for guide 29 |
| `getMessage(): Message`, the last message in the history | `getMessage(): ?Message`, the final model message of the run. After a completed run it is the same message |
| `getSteps()`, accumulated over every call on the same agent instance | the messages of this call only. For the whole conversation use `$agent->getChatHistory()->getMessages()` |

`$agent->getChatHistory()` returns a new view of the conversation on every call. Do not write to it while a run is executing, for example inside a stream loop.

### Case 5: Chat history set on a state

Before (3.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;

class SupportAgent extends Agent
{
    protected function state(): AgentState
    {
        $state = new SupportState();
        $state->setChatHistory($this->chatHistory());

        return $state;
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;

class SupportAgent extends Agent
{
    protected function state(): AgentState
    {
        return new SupportState();
    }
}
```

1. Delete every `setChatHistory()` call on a state: in a `state()` override, or on a seed state passed to `make(state: ...)`, `new X(state: ...)` or `setState()`. Keep returning or passing the bare state.
2. If the history it set was `$this->chatHistory()`, nothing else is needed: guide 31 converts the `chatHistory()` hook.
3. If it set another history object, move that object to the agent so guide 31 can find it. Chain `->setChatHistory($history)` on the agent, or return it from a `chatHistory()` hook. Guide 31 turns both into a message store. Until then, PHPStan reports these calls, as it already reports the ones guide 13 left.

### Case 6: Verb overrides in Agent and RAG subclasses

A 3.x override fails to load in 4.x. The error is `Declaration of SupportAgent::chat(...) must be compatible with NeuronAI\Agent\Agent::chat(...)`.

Before (3.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

class SupportAgent extends Agent
{
    public function chat(Message|array $messages = [], ?InterruptRequest $interrupt = null): AgentHandler
    {
        return parent::chat($this->redactor->redact($messages), $interrupt);
    }

    public function stream(Message|array $messages = [], ?InterruptRequest $interrupt = null): AgentHandler
    {
        return parent::stream($this->redactor->redact($messages), $interrupt);
    }

    public function structured(
        Message|array $messages = [],
        ?string $class = null,
        int $maxRetries = 1,
        ?InterruptRequest $interrupt = null
    ): mixed {
        return parent::structured($this->redactor->redact($messages), $class ?? Ticket::class, $maxRetries, $interrupt);
    }
}
```

After (4.x):

```php
use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\Message;

class SupportAgent extends Agent
{
    public function chat(Message|array $messages = [], bool $stream = false): AgentState
    {
        return parent::chat($this->redactor->redact($messages), $stream);
    }

    public function stream(Message|array $messages = []): Generator
    {
        return parent::stream($this->redactor->redact($messages));
    }

    public function structured(Message|array $messages = [], ?string $class = null, int $maxRetries = 1): mixed
    {
        return parent::structured($this->redactor->redact($messages), $class ?? Ticket::class, $maxRetries);
    }
}
```

1. Use these signatures exactly, and remove `use NeuronAI\Agent\AgentHandler;` and `use NeuronAI\Workflow\Interrupt\InterruptRequest;` if nothing else uses them.
2. Forward `$stream` to `parent::chat()`. Otherwise `chat(..., stream: true)` stops streaming.
3. Resumes no longer pass through these methods. If the body did something when `$interrupt` was not `null`, move that code to an `events()` override as guide 12 Case 5 shows, under `$request?->starting === false`.
4. `parent::chat()` now returns after the turn has run. In 3.x it returned an unstarted handler, so code placed after it ran before the model was called. Move such code before the parent call if it must still run first.

### Case 7: Overrides that rebuilt a verb to register nodes

A 3.x override that called `$this->compose(...)` and returned `new AgentHandler(...)`, or called `parent::init()`, existed to put its own nodes in the graph. `compose()` no longer exists. Delete the override and register the nodes in a `nodes()` override. In 4.x, one `ChatNode` serves both `chat()` and `stream()` (`StreamingNode` is gone), and `StructuredOutputNode` serves `structured()`.

Before (3.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

class SupportAgent extends Agent
{
    public function chat(Message|array $messages = [], ?InterruptRequest $interrupt = null): AgentHandler
    {
        $this->resolveStartEvent()->setMessages(...(is_array($messages) ? $messages : [$messages]));
        $this->compose(new AuditedChatNode($this->resolveProvider()));

        return new AgentHandler($this, $interrupt);
    }
}
```

After (4.x):

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Workflow\NodeInterface;

class SupportAgent extends Agent
{
    protected function nodes(): array
    {
        return array_map(
            fn (NodeInterface $node): NodeInterface => $node instanceof ChatNode ? new AuditedChatNode() : $node,
            parent::nodes(),
        );
    }
}
```

- To add a node instead of replacing one, return `[...parent::nodes(), new AuditNode()]`.
- Construct built-in node subclasses without the provider: in 4.x, agent nodes read it at run time. Guide 26 migrates the node classes themselves (constructors, `inference()` overrides) and the other graph hooks.
- If the override replaced a node only for `chat()` or only for `stream()`, ask the developer whether the replacement should now apply to both.

## Checklist

- [ ] Search 3 finds no `resolveState(` on an Agent or RAG.
- [ ] Search 1 finds no `AgentHandler`, and no `->streamEvents(` outside adapter streams left for guide 36.
- [ ] No `run()`, `start()`, `events()` or `streamEvents()` is called on a value returned by `chat()` or `stream()`, except adapter streams left for guide 36 and calls left for guide 29.
- [ ] Each `chat()` call sits where its 3.x handler first ran (inside the same `try`, branch or closure). No agent configuration call sits between the old `chat()` position and that point.
- [ ] Every stream loop reads the final state with `getReturn()` after the loop. A `stream()` that was never iterated became `chat(..., stream: true)`.
- [ ] No `getChatHistory()` or `setChatHistory()` is called on an `AgentState` outside nodes and middleware.
- [ ] `chat()`, `stream()` and `structured()` overrides in Agent and RAG subclasses use the 4.x signatures and forward `$stream`. No verb override calls `compose()`.
- [ ] These are untouched: `catch (WorkflowInterrupt ...)` blocks, calls that pass an `InterruptRequest`, adapter streams, `$agent->setChatHistory()` and `chatHistory()` hooks, and history reads in nodes and middleware.
- [ ] PHPStan reports no error about `AgentHandler`, about `run()`/`start()`/`events()`/`streamEvents()` on `AgentState` or `Generator`, about `getChatHistory()`/`setChatHistory()` on `AgentState`, or about incompatible `chat()`/`stream()`/`structured()` declarations.
