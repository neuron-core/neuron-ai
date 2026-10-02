# Upgrade: MCP tool results are ToolOutput

## Summary

In 3.x, `McpConnector::invokeTool()` returned the MCP server's `content` array, or `''` when the result had no `content`. The tool JSON-encoded that array, so an MCP tool's result was a string such as `[{"type":"text","text":"..."}]`. The server's `isError` flag was ignored. In 4.x, `invokeTool()` and every MCP tool return a `NeuronAI\Tools\ToolOutput`:

- `text`, `image` and `audio` items become `TextContent`, `ImageContent` and `AudioContent` blocks (namespace `NeuronAI\Chat\Messages\ContentBlocks`). Images and audio have `SourceType::BASE64`, the base64 data in `->content` and the server's `mimeType` in `->mediaType`.
- Any other item (resource links, embedded resources, items that break the specification) becomes a `TextContent` holding the item's JSON.
- `structuredContent` sent with no content items becomes one `TextContent` holding its JSON. In 3.x the result was `''` (no `content` key) or `"[]"` (`content: []`).
- `isError: true` makes `isError()` return `true`.
- A result with no content is an empty `ToolOutput`: `getBlocks()` is `[]` and `getText()` is `''`.
- `getText()` joins the text blocks with one space, and `(string) $output` returns the same string.
- A JSON-RPC error response still throws `NeuronAI\MCP\McpException`, as in 3.x.

| 3.x | 4.x |
|-----|-----|
| `getResult()` of an MCP tool call is the JSON string of the `content` array. This applies to the `ToolCall` entries of messages (guide 4), the `tool` of the `ToolCalled` event and of `ToolResultChunk`, and an MCP tool executed directly | A `ToolOutput` |
| `McpConnector::invokeTool(array $item, array $arguments): mixed` returns the `content` array | Returns `ToolOutput` |
| A result with `isError: true` is an ordinary result | `isError()` is `true` |
| A result without `content` is `''`, and one with `content: []` is `"[]"` | An empty `ToolOutput` |
| The serialized `result` of an MCP tool call (`jsonSerialize()`, stored messages) is that JSON string | A block list such as `[{"type":"text","content":"...","meta":[]}]`, or `{"is_error":true,"blocks":[...]}` for an error result |
| Vercel AI `tool-output-available.output` and AG-UI `TOOL_CALL_RESULT.content` carry that JSON string | Plain text: the text blocks only, without images or audio. An error result arrives as Vercel `tool-output-error` (`errorText`) or as AG-UI `TOOL_CALL_RESULT` with the error text as `content` |

**Stored data:** MCP results that 3.x wrote to chat history stay JSON strings. 4.x reads them back as-is, as plain string results, never as a `ToolOutput`, and without an error flag, because 3.x never recorded one. Do not rewrite stored rows or `.chat` files. Code that reads history written before the upgrade keeps a decode branch for these strings (Case 1, step 4, and Case 4).

## What to Search For

Run from the application root:

```bash
grep -rnE 'McpConnector|CallableMcpTool|invokeTool\(' --include='*.php' --exclude-dir=vendor .
```

If nothing is found, the application uses no MCP tools and this guide does not apply.

Otherwise run:

```bash
grep -rnE 'getResult\(' --include='*.php' --exclude-dir=vendor .
grep -rnE "\[['\"]result['\"]\]|tools\[[0-9*]*\]\.result" --include='*.php' --include='*.sql' --exclude-dir=vendor .
grep -rnE "output-available|TOOL_CALL_RESULT|JSON\.parse\([^)]*(output|result|content)" --include='*.js' --include='*.jsx' --include='*.ts' --include='*.tsx' --include='*.vue' --include='*.svelte' --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=dist --exclude-dir=build .
```

How to follow the hits:
- **`function invokeTool(`** in a class that extends `McpConnector`: Case 3.
- **Other `invokeTool(` calls**: Case 2.
- **`CallableMcpTool`**, and `createTool()` overrides: leave them for guide 50.
- **`getResult(`**: only tool results matter. Ignore `getResult()` on workflow events (`StopEvent`, `ParallelEvent`). For each hit, check whether the tool can be an MCP tool, meaning it belongs to an agent whose tools include `McpConnector::make(...)->tools()`, or to a listener, stream loop, history reader or test of such an agent. If it can, use Case 1. Guide 4 already touched these reads, and may have added a `(string)` cast.
- **`['result']` / `tools[...].result`**: code or SQL that reads the `result` of serialized tool calls. Use Case 4 when those calls can come from an MCP tool.
- **Frontend hits**: use Case 5 when the stream or history comes from an agent with MCP tools.

## How to Refactor

### Case 1: Code that reads an MCP tool's result

This covers middleware, listeners, stream loops, evaluation assertions, logging, history readers and tests. The result is found with `getResult()` on a message's `ToolCall`, on `$event->tool`, on `$chunk->tool` or on an MCP tool executed directly.

Before (3.x, or with the `(string)` cast guide 4 added):

```php
$content = json_decode((string) $call->getResult(), true);
$text = $content[0]['text'] ?? '';

foreach (json_decode($call->getResult(), true) as $item) {
    if ($item['type'] === 'image') {
        $storage->put(base64_decode($item['data']), $item['mimeType']);
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Tools\ToolOutput;

$result = $call->getResult();
$text = (string) $result;
$failed = $result instanceof ToolOutput && $result->isError();

foreach ($result instanceof ToolOutput ? $result->getBlocks() : [] as $block) {
    if ($block instanceof ImageContent) {
        $storage->put(base64_decode($block->content), $block->mediaType);
    }
}
```

1. Remove every `json_decode()` of an MCP result and every index into the MCP `content` array (`[0]['text']`, `['data']`, `['mimeType']`). The code fails without an error:
   - With guide 4's `(string)` cast, `json_decode()` receives the plain text and returns `null`, so `$text` silently becomes `''`.
   - Without the cast, `json_decode()` of a `ToolOutput` throws a `TypeError` under `strict_types`, and returns the same silent `null` without it.

   PHPStan level 5 reports neither, so rely on the search.
2. For text, use `(string) $result`, which works for string and `ToolOutput` results alike and needs no import. It joins all text blocks, whereas the 3.x `[0]['text']` read only the first item. To read items one by one, iterate `getBlocks()`.
3. For error or media handling, add `use NeuronAI\Tools\ToolOutput;` (and `use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;` or `AudioContent` as needed) to every file that names them. No 3.x file imports `ToolOutput`. Without the import, the name resolves in the application's namespace, and `instanceof` is always `false`.
4. If the code reads messages from a chat history that holds entries written before the upgrade, keep a decode branch for those string results:

   ```php
   use NeuronAI\Tools\ToolOutput;

   $result = $call->getResult();
   $text = $result instanceof ToolOutput
       ? $result->getText()
       : (json_decode($result, true)[0]['text'] ?? $result);
   ```

5. `isError` results are returned, not thrown, so they never reach the `toolErrorHandler`. Code that must react to failures reported by the server checks `isError()` as above.
6. In tests, assert on the text: `assertSame('[{"type":"text","text":"Found 3 results"}]', $tool->getResult())` becomes `assertSame('Found 3 results', (string) $tool->getResult())`.

### Case 2: Direct `invokeTool()` calls

Before (3.x):

```php
$content = $connector->invokeTool(['name' => 'search'], ['query' => 'php']);
$text = $content[0]['text'] ?? '';
$empty = $content === '';
```

After (4.x):

```php
$output = $connector->invokeTool(['name' => 'search'], ['query' => 'php']);
$text = $output->getText();
$empty = $output->getBlocks() === [];
$failed = $output->isError();
```

- A result the server flags with `isError` looked like a success in 3.x, and `getText()` still returns its text. If the code should handle failures differently, ask the developer what it should do when `isError()` is `true`.
- `McpException` for JSON-RPC errors is unchanged, so keep existing `catch` blocks.

### Case 3: McpConnector subclasses that override `invokeTool()`

If the override keeps the 3.x `: mixed` return type, the class fails to load with the fatal error `Declaration of ...::invokeTool(array $item, array $arguments): mixed must be compatible with NeuronAI\MCP\McpConnector::invokeTool(array $item, array $arguments): NeuronAI\Tools\ToolOutput`. The override must also return a `ToolOutput`, because the MCP tool hands the returned value to the agent as its result.

Before (3.x):

```php
use NeuronAI\MCP\McpConnector;

class RedactingMcpConnector extends McpConnector
{
    public function invokeTool(array $item, array $arguments): mixed
    {
        $content = parent::invokeTool($item, $arguments);

        if (is_array($content)) {
            foreach ($content as $i => $part) {
                if (($part['type'] ?? null) === 'text') {
                    $content[$i]['text'] = $this->redact($part['text']);
                }
            }
        }

        return $content;
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ToolOutput;

class RedactingMcpConnector extends McpConnector
{
    public function invokeTool(array $item, array $arguments): ToolOutput
    {
        $output = parent::invokeTool($item, $arguments);

        $blocks = array_map(
            fn ($block) => $block instanceof TextContent ? new TextContent($this->redact($block->content)) : $block,
            $output->getBlocks(),
        );

        return new ToolOutput($blocks, $output->isError());
    }
}
```

1. Change the return type to `ToolOutput` and add `use NeuronAI\Tools\ToolOutput;`.
2. Rewrite the post-processing against the blocks of `parent::invokeTool()` instead of the `content` array.
3. Return one of these:
   - the parent's output;
   - `new ToolOutput($blocks, $output->isError())`, which keeps the error flag;
   - `ToolOutput::text('...')`;
   - `ToolOutput::error('...')`.

   Code that returned `''` returns `new ToolOutput([])`.
4. Other members of the same subclass (`createTool()`, `createToolProperty()`, `createArrayProperty()`, `createObjectProperty()`) belong to guide 50.

### Case 4: Consumers of serialized MCP results (PHP and SQL)

`ToolCall::jsonSerialize()['result']` is the shape that stored messages, payloads and the `ToolCalled` log record carry. Guide 34 already made these readers accept array results. The MCP-specific part is the following:
- New MCP entries are never the 3.x JSON string. They are a block list, with the text under `content` (not `text`) and images as `{"type":"image","content":"<base64>","source_type":"base64","media_type":"image/png"}`, or `{"is_error":true,"blocks":[...]}` for an error result.
- Entries stored by 3.x keep the JSON string.

In decoded JSON (stored rows, `.chat` files, payloads) a block's `type` is a string. In an array taken in PHP from `jsonSerialize()` or `ToolCalled::toArray()` (for example in a `LogListener::context()` override), it is the `ContentBlockType` enum. Where the `ToolCall` object is at hand, read it as in Case 1 instead.

Before (3.x):

```php
foreach ($data['tools'] as $entry) {
    $rows[] = json_decode($entry['result'], true)[0]['text'] ?? '';
}
```

After (4.x):

```php
use NeuronAI\Chat\Enums\ContentBlockType;

foreach ($data['tools'] as $entry) {
    $result = $entry['result'];

    if (is_array($result)) {
        $blocks = $result['blocks'] ?? $result;
        $text = implode(' ', array_column(array_filter(
            $blocks,
            fn (array $block): bool => in_array($block['type'], [ContentBlockType::TEXT, 'text'], true),
        ), 'content'));
    } else {
        $text = json_decode($result, true)[0]['text'] ?? $result;
    }

    $rows[] = $text;
}
```

Keep the `json_decode()` branch only for strings, which are entries written before the upgrade.

SQL over the stored message JSON changes the same way. In 3.x, `result` was a string that had to be parsed a second time, for example `json_extract(json_extract(meta, '$.tools[0].result'), '$[0].text')` in SQLite. In rows written by 4.x, `result` is JSON itself:
- the text of a normal result is at `$.tools[0].result[0].content`;
- the text of an error result is at `$.tools[0].result.blocks[0].content`.

Keep the 3.x expression as a fallback only for rows written before the upgrade.

### Case 5: Frontends that parse MCP tool output

With the Vercel AI adapter (`tool-output-available` frames, or AI SDK tool parts in state `output-available`) or the AG-UI adapter (`TOOL_CALL_RESULT`), the output of an MCP tool used to be the JSON string of the `content` array. It is now plain text.

Before (3.x):

```js
const text = JSON.parse(part.output)[0].text;
```

After (4.x):

```js
const text = part.output;
```

1. Remove `JSON.parse` of MCP tool output: Vercel `output`, AG-UI `content`, and the `result` of tool entries read from serialized history. For history, read blocks as in Case 4. Guide 34 shows the client version.
2. Handle error results in the frames that carry them: Vercel `tool-output-error` with `errorText` (tool part state `output-error`), or AG-UI `TOOL_CALL_RESULT`, whose `content` is the error text. Guide 37 covers the other frame changes.
3. The adapters no longer send MCP images or audio. If the frontend displayed them, tell the developer. The blocks are still in the `ToolResultMessage` and its serialized form (Case 4). Ask how the frontend should receive them.

## Checklist

- No code runs `json_decode()` on an MCP tool result, indexes it as the MCP `content` array, or compares it with `''`. The only exception is the explicit branch for string results written to history by 3.x.
- Every file that names `ToolOutput`, `ImageContent`, `AudioContent` or `TextContent` imports it from `NeuronAI\Tools` or `NeuronAI\Chat\Messages\ContentBlocks`.
- Code that must react to failures reported by the server checks `isError()`. No code expects them in the `toolErrorHandler`.
- Every `invokeTool()` override declares `: ToolOutput` and returns a `ToolOutput`.
- Readers of serialized tool entries and SQL over stored messages handle MCP results as block lists or `is_error` wrappers, and handle 3.x strings only where 3.x rows remain.
- No stored message row or `.chat` file was rewritten.
- Frontend code no longer runs `JSON.parse` on MCP tool output and handles the error frames.
- Static analysis reports no error about the symbols this guide migrates.
