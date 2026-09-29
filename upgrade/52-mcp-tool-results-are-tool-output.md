# Upgrade: MCP tool results are `ToolOutput`

## Summary

In 3.x an MCP tool returned the server's raw `content` array, which the tool JSON-encoded into a string: the model read
`[{"type":"text","text":"..."},{"type":"image","data":"...","mimeType":"image/png"}]`. A result the server flagged with
`isError` looked like a success, and images and audio arrived as base64 text. The result is now a `ToolOutput`, the
framework's multimodal tool result, which each provider maps natively.

- **`text`, `image` and `audio` items become `TextContent`, `ImageContent` and `AudioContent` blocks,** images and
  audio with a base64 source and the server's `mimeType`.
- **Any other item becomes a `TextContent` holding its JSON:** resource links, embedded resources, and items that break
  the specification. The model reads them as before.
- **`structuredContent` sent without any content item becomes a `TextContent` holding its JSON.** In 3.x the result was
  empty.
- **`isError: true` makes the output an error output** (`ToolOutput::isError()`), sent with the provider's native error
  flag where one exists (`is_error` on Anthropic, `status: "error"` on Bedrock).
- **`McpConnector::invokeTool()` returns a `ToolOutput`** instead of the `content` array or an empty string.

| Before (3.x) | After |
|---|---|
| `$toolCall->getResult()` is the JSON string of the MCP content | `$toolCall->getResult()` is a `ToolOutput` |
| `McpConnector::invokeTool()` returns `[['type' => 'text', 'text' => '...']]` | returns `ToolOutput::text('...')` |
| A result with `isError: true` is an ordinary result | `ToolOutput::isError()` is `true` |
| A result with no content is `''` | An empty `ToolOutput`, whose `getText()` is `''` |

## How to Refactor

### Case 1: Code that reads an MCP tool's result

Middleware, observers, tool error handlers, evaluation assertions or logging code that treats the result of an MCP tool
as a JSON string must handle a `ToolOutput`. Read the text with `getText()`, or `(string) $result`, and the blocks with
`getBlocks()`:

Before:

```php
$content = json_decode($toolCall->getResult(), true);
$text = $content[0]['text'];
```

After:

```php
$result = $toolCall->getResult();
$text = $result instanceof ToolOutput ? $result->getText() : $result;
```

### Case 2: Code that calls `McpConnector::invokeTool()` directly

Read the returned `ToolOutput` with `getBlocks()`, `getText()` and `isError()` instead of indexing the `content` array.

## What to Search For

```
grep -rn "invokeTool\|McpConnector\|McpTool" --include="*.php" .
grep -rn "getResult()" --include="*.php" .
```

For each `getResult()` found, check whether the tool can be an MCP tool.

## Checklist

- No code decodes an MCP tool's result as JSON or indexes it as the MCP `content` array.
- Code that reads tool results handles `ToolOutput`, including `isError()`.
