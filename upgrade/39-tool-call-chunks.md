# Upgrade: ToolCallChunk and ToolResultChunk hold a ToolCall

## Summary

`NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk` and `ToolResultChunk` keep their namespace. In 3.x their `$tool` property held the tool object (`ToolInterface`, usually the app's own tool class). In 4.x it holds a `NeuronAI\Tools\ToolCall`, the data record guide 4 introduced. `ToolCallChunk` also takes, as its first constructor argument, the ID of the `ToolCallMessage` that holds the call.

| 3.x | 4.x |
|---|---|
| `new ToolCallChunk(ToolInterface $tool)`, and `$chunk->messageId` is always `null` | `new ToolCallChunk(string $messageId, ToolCall $tool)`. `$chunk->messageId` is the ID of the `ToolCallMessage`, the ID it is stored under |
| `new ToolResultChunk(ToolInterface $tool)` | `new ToolResultChunk(ToolCall $tool)`. `$chunk->messageId` is still `null` |
| `$chunk->tool` is the tool object: `instanceof WeatherTool` works, and so do `getProperties()`, `getParameters()`, `getMaxRuns()` and `execute()` | `$chunk->tool` is a `ToolCall`. `instanceof` any tool class is always `false`. Only the call's data is available: `getName()`, `getCallId()`, `getDescription()`, `getInputs()`, `getInput()`, `hasResult()`, `getResult()`, the setters `setCallId()`, `setInputs()` and `setResult()`, plus the new `isDeferred()` and approval accessors |
| `ToolCallChunk::toArray()`: `['messageId' => null, 'tool' => [...]]` | `['messageId' => '<ToolCallMessage ID>', 'tool' => [...]]` |
| `ToolResultChunk::toArray()`: `['messageId' => null, 'tools' => [...]]` | `['messageId' => null, 'tool' => [...]]` |
| The `tool` entry has the keys `callId`, `name`, `description`, `parameters`, `inputs` and `result` (a string) | It has the `ToolCall::jsonSerialize()` shape: no `parameters`, a `result` that can be an array, and new keys (guide 34, Case 5) |

**Stored data:** Neuron never stored stream chunks, so there is nothing to migrate. If the application saved 3.x `ToolResultChunk::toArray()` payloads itself (logs, replay buffers), code that reads them must accept both keys: `$payload['tool'] ?? $payload['tools']`.

Other guides cover the related changes:
- `getResult()` on `$chunk->tool` returns `string|ToolOutput` and throws without a result. Guide 4 (Case 4) already migrated these reads.
- `ToolNode` / `ParallelToolNode` hooks (`executeTools()` became `executeLocalTools()`): guide 26, already applied.
- Subclasses of `AGUIAdapter` / `VercelAIAdapter` (`handleToolCall()`, `handleToolResult()`, `resolveToolCallId()`) and custom adapters: guide 35. The frames the built-in adapters send for tool calls, and AG-UI message IDs: guide 37.

## What to Search For

Run from the application root:

```bash
grep -rnE 'ToolCallChunk|ToolResultChunk' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:
- **`new ToolCallChunk(` / `new ToolResultChunk(`**, and classes that extend either one: use Case 1.
- **`instanceof ToolCallChunk` / `instanceof ToolResultChunk`, `match` arms and parameters typed with either class** (in stream loops, custom adapters, listeners and tests): follow the chunk variable. Reads of `->tool` go to Case 2. `->toArray()` calls, and the code that receives their output, go to Case 3.
- **A `use` import only**: follow the class through the file.

If Case 3 applies and the payloads reach a client (SSE, WebSocket, API response), also search the client:

```bash
grep -rnE '\.tools\b|\[.tools.\]' --include='*.js' --include='*.jsx' --include='*.ts' --include='*.tsx' --include='*.vue' --exclude-dir=node_modules .
```

Only reads of a `ToolResultChunk` payload change. The `tools` array of serialized tool messages keeps its name (guide 34).

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: Code that builds tool chunks (nodes, custom workflows, tests)

Before (3.x):

```php
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\Tool;

foreach ($toolCallMessage->getTools() as $tool) {
    yield new ToolCallChunk($tool);
}

// test fixture
$tool = Tool::make('search', 'Search the web')->setCallId('call_1');
$tool->setInputs(['query' => 'php']);

$callChunk = new ToolCallChunk($tool);
$resultChunk = new ToolResultChunk($tool->setResult('3 results'));
```

After (4.x):

```php
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ToolCall;

foreach ($toolCallMessage->getToolCalls() as $call) {
    yield new ToolCallChunk($toolCallMessage->getId(), $call);
}

// test fixture
$call = ToolCall::make(name: 'search', callId: 'call_1', inputs: ['query' => 'php'], description: 'Search the web');

$callChunk = new ToolCallChunk('msg_1', $call);
$resultChunk = new ToolResultChunk($call->setResult('3 results'));
```

1. Pass the ID of the `ToolCallMessage` that holds the call as the first argument of `ToolCallChunk`: `$toolCallMessage->getId()`. Inside an `executeLocalTools()` override (guide 26), use its `$messageId` parameter. In a test, any string works, unless the code under test matches the chunk to a message. In that case, use that message's `getId()`.
2. Pass a `ToolCall` as the chunk's tool:
   - Take it from `$toolCallMessage->getToolCalls()`.
   - In fixtures, build it with `ToolCall::make(...)` using named arguments, and chain `setResult()` for a `ToolResultChunk`.
   - When the code builds the chunk from a tool object, convert it: `$call = ToolCall::make(name: $tool->getName(), callId: $tool->getCallId(), inputs: $tool->getInputs(), description: $tool->getDescription());`. For a `ToolResultChunk`, also copy the result: `new ToolResultChunk($call->setResult($tool->getResult()))`. `AGUIAdapter` and `VercelAIAdapter` call `getResult()` on every `ToolResultChunk`, and it throws `ToolException` on a call without a result.
   - If guide 4 already turned the argument into a `ToolCall`, only add the message ID.
3. With named arguments, write `new ToolCallChunk(messageId: ..., tool: $call)`. `ToolResultChunk` takes no message ID.
4. A subclass constructor calls `parent::__construct($messageId, $call)` for `ToolCallChunk`, or `parent::__construct($call)` for `ToolResultChunk`.

### Case 2: Code that reads `$chunk->tool`

Here `$stream` is `$agent->stream(...)`, as guide 23 left it.

Before (3.x):

```php
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Tools\ToolInterface;

foreach ($stream as $chunk) {
    if ($chunk instanceof ToolCallChunk && $chunk->tool instanceof WeatherTool) {
        $this->progress->show('Checking the weather in ' . $chunk->tool->getInput('city'));
    }

    if ($chunk instanceof ToolCallChunk) {
        $this->audit($chunk->tool);
    }
}

protected function audit(ToolInterface $tool): void
{
    $this->logger->info('Tool call', ['name' => $tool->getName(), 'inputs' => $tool->getInputs()]);
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Tools\ToolCall;

foreach ($stream as $chunk) {
    if ($chunk instanceof ToolCallChunk && $chunk->tool->getName() === 'get_weather') {
        $this->progress->show('Checking the weather in ' . $chunk->tool->getInput('city'));
    }

    if ($chunk instanceof ToolCallChunk) {
        $this->audit($chunk->tool);
    }
}

protected function audit(ToolCall $call): void
{
    $this->logger->info('Tool call', ['name' => $call->getName(), 'inputs' => $call->getInputs()]);
}
```

1. Replace every `$chunk->tool instanceof <ToolClass>` with a comparison of `$chunk->tool->getName()` against that tool's name (`get_weather` stands for the name `WeatherTool` declares). The old check still compiles but is always `false`.
2. Change the types that receive `$chunk->tool` (parameters, closures, `@var`/`@param`) from `ToolInterface` or `Tool` to `ToolCall`. Otherwise the code fails with a `TypeError`.
3. Remove calls to tool-definition methods on `$chunk->tool`: `getProperties()`, `getRequiredProperties()`, `getParameters()`, `getMaxRuns()`, `isVisible()`, `execute()`, `setName()`, `setDescription()`, `addProperty()`, `setMaxRuns()`, `visible()` and `setCallable()`. If the code needs that data, read it from the tool the app registers under `$chunk->tool->getName()`, meaning the object passed to `addTool()` / `setTools()` or returned by `tools()`.

### Case 3: Code that serializes tool chunks, and its consumers

Before (3.x):

```php
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;

if ($chunk instanceof ToolResultChunk) {
    $payload = $chunk->toArray();
    $this->emit('tool-result', ['name' => $payload['tools']['name'], 'result' => $payload['tools']['result']]);
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;

if ($chunk instanceof ToolResultChunk) {
    $payload = $chunk->toArray();
    $this->emit('tool-result', ['name' => $payload['tool']['name'], 'result' => $payload['tool']['result']]);
}
```

A client that received the whole `toArray()` payload changes in the same way.

Before (3.x):

```js
const { name, result } = payload.tools;
```

After (4.x):

```js
const { name, result } = payload.tool;
```

1. Where a `ToolResultChunk::toArray()` payload is read, in PHP or in a client, replace the `tools` key with `tool`. `json_encode($chunk)` already produced `tool` in 3.x, so only `toArray()` output changes key.
2. Apply guide 34, Case 5 to every reader of the `tool` entry, whether it comes from `toArray()`, `json_encode($chunk)` or `json_encode($chunk->tool)`: drop `parameters`, handle an array `result`, and accept the new keys.

## Checklist

- Every `new ToolCallChunk(...)` passes the ID of the `ToolCallMessage` holding the call first and a `ToolCall` second.
- Every `new ToolResultChunk(...)` passes a `ToolCall`.
- No `$chunk->tool instanceof <ToolClass>` remains. Tool checks compare `getName()`.
- No tool-definition method (`getProperties()`, `getParameters()`, `getMaxRuns()`, `execute()`, ...) is called on `$chunk->tool`. Values taken from it are typed `ToolCall`.
- No PHP or client code reads `tools` from a `ToolResultChunk` payload. Readers of the `tool` entry follow guide 34, Case 5.
- PHPStan (level 5) passes on the touched files. It reports the old constructor calls, wrong parameter types and the always-false `instanceof` checks. It cannot see the `tools` key in array payloads or client code.
