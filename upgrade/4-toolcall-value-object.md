# Upgrade: Tool calls are ToolCall objects and tool results may be ToolOutput

## Summary

In 3.x, the entries of `ToolCallMessage` and `ToolResultMessage` were tool objects (`ToolInterface`), usually a clone of a registered `Tool` stamped with `setCallId()`, `setInputs()` and `setResult()`, and you could execute them. In 4.x the entries are `NeuronAI\Tools\ToolCall` records. A `ToolCall` is data only and can never execute. The agent runs a call with the tool registered on it under the call's name.

| 3.x | 4.x |
|-----|-----|
| `$message->getTools()` on `ToolCallMessage` / `ToolResultMessage`, returning `ToolInterface[]` | `$message->getToolCalls()`, returning `ToolCall[]` (`$agent->getTools()` is unchanged) |
| Entry built with `Tool::make($name, $description)` or `(clone $tool)`, then `->setCallId()->setInputs()` | `ToolCall::make(name: ..., callId: ..., inputs: ..., description: ...)`. The positional order is `name, callId, inputs, description`, so never copy 3.x positional arguments |
| `$entry->execute()`, `getProperties()`, `getMaxRuns()` and other tool methods on an entry | Not available. Execute the registered tool instead (Case 3) |
| `getResult(): string` (TypeError when unset) | `getResult(): string\|ToolOutput`. It throws `NeuronAI\Exceptions\ToolException` when there is no result, so check `hasResult()` first. Applies to `Tool` and `ToolCall` |
| `toolErrorHandler(fn (Throwable $e, ToolInterface $tool): string)` | `toolErrorHandler(fn (Throwable $e, ToolCall $call): string\|ToolOutput\|null)`. Returning `null` rethrows `$e` |
| With `parallelToolCalls(true)`, the handler, and a `catch` around the agent call, received the tool's own exception class | When two or more calls run in forked child processes, both receive a `ToolException`: `Tool <name> failed with <OriginalClass>: <message>`. A turn with a single call, or a host without `pcntl_fork` or `spatie/fork`, still delivers the original exception |

`ToolCall` offers `getName()`, `getCallId()`, `getDescription()`, `getInputs()`, `getInput($key)`, `setCallId()`, `setInputs()`, `hasResult()`, `getResult()`, `setResult($result)` (a string, an array that gets JSON-encoded, or a `ToolOutput`), `isDeferred()` and `jsonSerialize()`. It also has approval accessors: `getApprovalState()`, `getApprovalReason()`, `getRejectReason()`, `setApprovalState()` (whose second argument sets the reject reason) and `setApprovalReason()`. These are new, so there is nothing to migrate. None of the tool capabilities exist on it: `execute()`, `getProperties()`, `getRequiredProperties()`, `getParameters()`, `getAnnotations()`, `getMaxRuns()`/`setMaxRuns()`, `visible()`/`isVisible()`, `setName()`, `setDescription()`, `addProperty()`.

`NeuronAI\Tools\ToolOutput` is a tool result made of content blocks:
- `getText()` returns the concatenated text blocks, and `(string) $output` gives the same.
- `isError()` tells you whether the output is a failure.
- `getBlocks()` returns the content blocks.
- `ToolOutput::text('...')` and `ToolOutput::error('...')` build one.

**Stored data:** 4.x reads the tool entries of chat histories written by 3.x as-is, as `ToolCall` objects, and ignores the stored `parameters` key. Tool entries need no data migration. Guide 30 migrates the chat history storage itself. Guide 34 covers the serialized shape 4.x writes (`jsonSerialize()`), including code that reads it.

Other guides handle these related changes. Leave them for their guide:
- `ToolCallChunk` / `ToolResultChunk` classes: guide 39.
- `ToolCalling` / `ToolCalled` listeners: guide 46.
- Provider subclasses that build entries with `findTool()->setInputs()->setCallId()`: guide 43.
- `ToolNode` / `ParallelToolNode` subclasses (`executeTools()`, `executeSingleTool()`, `handleError()` overrides): guide 26.
- Whether `toolErrorHandler()` or a `resolveToolErrorHandler()` override wins: guide 25.
- Tests that expect `MissingCallbackParameter` for bad tool arguments: guide 5.

## What to Search For

Run from the application root:

```bash
grep -rnE 'getTools\(' --include='*.php' --exclude-dir=vendor .
grep -rnE 'Tool(Call|Result)Message' --include='*.php' --exclude-dir=vendor .
grep -rnE '(^|[^A-Za-z0-9_])Tool::make\(|new ([A-Za-z0-9_\\]*\\)?Tool\(|setCallId\(' --include='*.php' --exclude-dir=vendor .
grep -rnE 'getResult\(' --include='*.php' --exclude-dir=vendor .
grep -rnE '[tT]oolErrorHandler|new (Parallel)?ToolNode\(|parallelToolCalls\(' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:
- **`getTools(`**: only calls on a `ToolCallMessage` / `ToolResultMessage` (or on a variable holding one) change. Use Case 1, or Case 3 when the loop calls `execute()` on the entries. `$agent->getTools()` and `function getTools()` declarations stay as they are.
- **`Tool(Call|Result)Message`**: for constructors and `::make(` calls, use Case 2 (Case 3 when the code wraps the entries of a provider response). For `instanceof` checks, parameter types and imports, follow the variable to its `getTools()` / `getResult()` reads (Cases 1 and 4).
- **`Tool::make(` / `new Tool(` / `setCallId(`**: guide 3 already turned executable tools into subclasses. What is left are entries built for messages, which Case 2 converts. Until then, `Tool::make()` / `new Tool()` entries fail with `Cannot instantiate abstract class NeuronAI\Tools\Tool`, and cloned-tool entries fail with a `TypeError` when an agent runs them.
- **`getResult(`**: use Case 4 when it is called on a tool, a message entry, `$chunk->tool` or `$event->tool`. `getResult()` on a workflow `StopEvent` or `ParallelEvent` is unrelated.
- **`toolErrorHandler` / `resolveToolErrorHandler` / `new ToolNode(` / `parallelToolCalls(`**: use Case 5. For `parallelToolCalls(` hits, also follow that agent to the `catch` blocks and `expectException()` calls around its runs (Case 5, step 4).

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: Code that reads tool calls from messages

Before (3.x):

```php
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ToolInterface;

if ($message instanceof ToolCallMessage) {
    foreach ($message->getTools() as $tool) {
        $log[] = $tool->getName() . ' ' . json_encode($tool->getInputs());
    }

    $names = array_map(fn (ToolInterface $tool): string => $tool->getName(), $message->getTools());
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ToolCall;

if ($message instanceof ToolCallMessage) {
    foreach ($message->getToolCalls() as $call) {
        $log[] = $call->getName() . ' ' . json_encode($call->getInputs());
    }

    $names = array_map(fn (ToolCall $call): string => $call->getName(), $message->getToolCalls());
}
```

1. On `ToolCallMessage` and `ToolResultMessage`, rename `getTools()` to `getToolCalls()`.
2. Wherever an entry is typed `ToolInterface` or `Tool` (parameters, closures, `@var`/`@param ToolInterface[]`), change the type to `ToolCall`.
3. Remove calls to tool capabilities on entries (see the Summary list). If the code executes the entries, apply Case 3. If it needs the tool's definition (properties, schema, max runs), read it from the tool object the app registered under `$call->getName()`, meaning the object passed to `addTool()` / `setTools()` or returned by `tools()`.
4. For reading results, apply Case 4.

### Case 2: Messages built by hand (tests, `FakeAIProvider` responses, seeded histories)

Before (3.x; `$searchTool` is the tool guide 3 already turned into a subclass):

```php
$provider = new FakeAIProvider(
    new ToolCallMessage(null, [
        (clone $searchTool)->setCallId('call_1')->setInputs(['query' => 'PHP frameworks']),
    ]),
    new AssistantMessage('Based on my search, here are the top PHP frameworks...'),
);

$result = new ToolResultMessage([
    Tool::make('search', 'Search the web')
        ->setCallId('call_1')
        ->setInputs(['query' => 'php'])
        ->setResult('3 results'),
]);
```

After (4.x):

```php
use NeuronAI\Tools\ToolCall;

$provider = new FakeAIProvider(
    new ToolCallMessage(null, [
        ToolCall::make(name: 'search', callId: 'call_1', inputs: ['query' => 'PHP frameworks']),
    ]),
    new AssistantMessage('Based on my search, here are the top PHP frameworks...'),
);

$result = new ToolResultMessage([
    ToolCall::make(name: 'search', callId: 'call_1', inputs: ['query' => 'php'], description: 'Search the web')
        ->setResult('3 results'),
]);
```

1. Replace every tool entry with `ToolCall::make(...)` or `new ToolCall(...)`, using named arguments.
   - `name` is the tool's name. For a cloned tool, use its name or `$searchTool->getName()`.
   - `description` is optional.
   - Chain `setResult()` as before.
   - Apply the same change inside `ToolCallMessage::make(...)` and `ToolResultMessage::make(...)`.
2. Check that the agent running the message has a tool with that name registered through `addTool()` or `tools()`. In 3.x the entry object executed itself, so a test could skip registering the tool. In 4.x the run fails with `ToolException: The tool search is not registered on this agent: the call cannot be executed.` If a `toolErrorHandler` is set, it receives that exception.
3. Remove `Tool` imports and tool variables that only served as entries.

### Case 3: Standalone loops that execute the calls a provider returns

This case covers code that calls a provider directly with `setTools()` and then executes the entries of the returned `ToolCallMessage`. In 4.x you execute a clone of the tool registered under the call's name, then store its result on the call. Leave the `$provider->chat(...)` lines alone: guide 41 migrates them.

Before (3.x):

```php
foreach ($message->getTools() as $tool) {
    $tool->execute();
}

$messages[] = $message;
$messages[] = new ToolResultMessage($message->getTools());
```

After (4.x):

```php
use NeuronAI\Tools\ToolInterface;

$toolsByName = [];
foreach ($tools as $tool) { // the array passed to $provider->setTools()
    if ($tool instanceof ToolInterface) {
        $toolsByName[$tool->getName()] = $tool;
    }
}

foreach ($message->getToolCalls() as $call) {
    $tool = (clone $toolsByName[$call->getName()])->setInputs($call->getInputs());
    $tool->execute();
    $call->setResult($tool->getResult());
}

$messages[] = $message;
$messages[] = new ToolResultMessage($message->getToolCalls());
```

The provider still rejects unknown tool names with a `ProviderException`, as in 3.x, so the lookup always finds the tool. Clone the tool for each call, so calls never share inputs or results.

### Case 4: Code that reads tool results

A result can be a `ToolOutput` even when the tool's `__invoke()` returns a string:
- Arguments the model sent wrong or left out settle as `ToolOutput::error('Parameter "x" ...')` (guide 5).
- Built-in toolkits (guides 6-9), MCP tools (guide 49) and error handlers can return one.

Under `strict_types`, passing a `ToolOutput` to a `string` parameter is a `TypeError`. PHPStan below level 7 does not report it.

Before (3.x):

```php
$tool = new SearchTool();
$tool->setInputs(['query' => 'php']);
$tool->execute();
$data = json_decode($tool->getResult(), true);

foreach ($message->getTools() as $entry) {
    $text = $entry->getResult();
}
```

After (4.x):

```php
use NeuronAI\Tools\ToolOutput;

$tool = new SearchTool();
$tool->setInputs(['query' => 'php']);
$tool->execute();
$data = json_decode((string) $tool->getResult(), true);

foreach ($message->getToolCalls() as $call) {
    if (!$call->hasResult()) {
        continue;
    }

    $result = $call->getResult();
    $text = (string) $result;
    $failed = $result instanceof ToolOutput && $result->isError();
}
```

1. Where a string is needed, cast with `(string)`. A `ToolOutput` casts to its text.
2. Where the code must tell failures apart, check `$result instanceof ToolOutput && $result->isError()`. For images or files, read `$result->getBlocks()`.
3. Guard `getResult()` with `hasResult()` wherever the call may not have run. For example, entries of a `ToolCallMessage` loaded from history carry no result.
4. Test assertions such as `assertSame('...', $tool->getResult())` still pass for string results. When the result is a `ToolOutput`, assert on `(string)` or `isError()` instead.

### Case 5: Tool error handler

Before (3.x):

```php
use NeuronAI\Tools\ToolInterface;

$agent->toolErrorHandler(
    fn (Throwable $e, ToolInterface $tool): string => "Tool {$tool->getName()} failed: {$e->getMessage()}"
);

// or in an Agent subclass
protected function resolveToolErrorHandler(): ?callable
{
    return function (Throwable $e, ToolInterface $tool): ?string {
        if ($e instanceof RateLimitException) {
            return null;
        }

        return "Tool {$tool->getName()} failed: {$e->getMessage()}";
    };
}
```

After (4.x):

```php
use NeuronAI\Tools\ToolCall;

$agent->toolErrorHandler(
    fn (Throwable $e, ToolCall $call): string => "Tool {$call->getName()} failed: {$e->getMessage()}"
);

// or in an Agent subclass
protected function resolveToolErrorHandler(): ?callable
{
    return function (Throwable $e, ToolCall $call): ?string {
        if ($e instanceof RateLimitException) {
            return null; // rethrows $e
        }

        return "Tool {$call->getName()} failed: {$e->getMessage()}";
    };
}
```

1. Change the second parameter from `ToolInterface`/`Tool` to `ToolCall`. This covers closures, invokable classes, callables returned by `resolveToolErrorHandler()` overrides, and handlers passed to `new ToolNode(...)` / `new ParallelToolNode(...)`. A handler still typed `ToolInterface` fails with a `TypeError` as soon as a tool throws.
2. On `$call`, use only data accessors: `getName()`, `getCallId()`, `getInputs()`, `getInput()`.
3. The handler may return:
   - a string, as before;
   - `ToolOutput::error('...')`, which marks the result as a failure;
   - `null`, which rethrows the original exception. In 3.x, `null` left the call without a result and the run then died with a `TypeError` from `Tool::getResult()`.

   Return a string or a `ToolOutput` on every path where the run should continue.
4. If the agent uses `parallelToolCalls(true)` and code matches a tool's own exception class (an `$e instanceof ...` branch in the handler, or a `catch (...)` around `chat()`, `stream()` or `structured()` that the exception reaches because no handler settles it), that match misses whenever the turn's calls run in forked child processes: the failure arrives as a `NeuronAI\Exceptions\ToolException` with the message `Tool <name> failed with <OriginalClass>: <message>`. A turn with a single call, and a host without `pcntl_fork` or `spatie/fork`, still delivers the original exception, so keep the existing match. Ask the developer which fix they want:
   - add a second match next to the existing one: `$e instanceof ToolException && str_contains($e->getMessage(), ' failed with ' . RateLimitException::class . ': ')`;
   - or have the tool return `ToolOutput::error('...')` for that failure instead of throwing.

## Checklist

- No `getTools()` call remains on a `ToolCallMessage` or `ToolResultMessage`.
- Every entry passed to `ToolCallMessage` / `ToolResultMessage` (constructor or `make()`) is a `ToolCall`. None is a `Tool`, a clone of a tool, or a `Tool::make()` result.
- Every tool named by a hand-built call is registered on the agent that runs it.
- No message entry has `execute()` or another tool capability called on it. Standalone loops execute a clone of the registered tool and call `setResult()` on the call.
- Every `getResult()` read on a tool, a call, `$chunk->tool` or `$event->tool` handles a `ToolOutput`, and is guarded by `hasResult()` where the call may not have run.
- Every tool error handler takes `ToolCall` as its second parameter and returns `null` only where the exception should propagate.
- With `parallelToolCalls(true)`, every handler branch or `catch` that matches a tool's own exception class also matches the forked `ToolException`, or the tool returns `ToolOutput::error()` for that failure, as the developer chose.
- PHPStan reports no error about these message entries. The message constructors do not check entry types at runtime, so a leftover tool entry only fails later with a `TypeError` when the message is serialized or executed. PHPStan (level 5 and above) reports it at the constructor.
