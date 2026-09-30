# Upgrade: Message serialization and content API

## Summary

The serialized form of a message changed. That is what `Message::jsonSerialize()` and `json_encode($message)` return, what the message stores write to rows and files, and what every payload built from them carries. A few message APIs changed as well.

| 3.x | 4.x |
|-----|-----|
| `{"__id": "msg_…", "source": "web", "role": "user", "content": […]}`: metadata beside the message's own keys | `{"__id": "msg_…", "role": "user", "content": […], "__meta": {"source": "web"}}` |
| `$message->jsonSerialize()['source']` | `$message->getMetadata('source')` |
| `JSON_EXTRACT(meta, '$.source')` on the messages table | `$.__meta.source` in rows written by 4.x and `$.source` in rows written by 3.x: match both |
| Tool entries in `tools`: `{callId, name, description, parameters, inputs, result}`, where `result` is a string | `{callId, name, description, deferred, inputs, result, approval, approvalReason, rejectReason}`. There is no `parameters`, and `result` is a string, a content-block array or `{"is_error": true, "blocks": […]}` |
| `addMetadata(string $key, string\|array\|null $value): self` | `addMetadata(string $key, mixed $value): self` |
| Anthropic `cacheWriteTokens` / `cacheReadTokens` metadata are strings (`'30'`) | They are integers (`30`) |
| `MediaType::TEXT` | `MediaType::TXT`. The value `'text/plain'` is unchanged |
| `$message->setContents([...])` appended the blocks to the existing ones | It replaces them, as the string and single-block forms already did |

The new message shape:
- `__id`, `role`, `content`, `usage` and, on tool messages, `type` and `tools` stay at the top level. All other metadata is under `__meta`.
- `__meta` is always present. It is `[]` (a JSON array, not an object) when the message has no metadata.
- `SQLMessageStore` and `EloquentMessageStore` write this shape without `role` and `content` to the `meta` column. `FileMessageStore` writes it to the `.chat` file.

**Stored data:** 4.x reads what 3.x stored as-is. A message in the 3.x shape loads with the same metadata, and a 3.x tool entry loads with its `parameters` ignored. One thread may mix both shapes, and the rows guide 30 migrated from the 3.x `chat_history` table keep the 3.x shape. Do not rewrite stored `meta` values or `.chat` files. Queries and code that read raw stored data must match both shapes instead (Cases 4 and 5). When 4.x loads a 3.x message and serializes it again, the output uses the 4.x shape. Anthropic cache counts stored by 3.x stay strings.

## What to Search For

Run from the application root:

```bash
grep -rnE 'jsonSerialize\(\)' --include='*.php' --exclude-dir=vendor .
grep -rnE '(add|set)Metadata\(' --include='*.php' --exclude-dir=vendor .
grep -rniE "json_(extract|value|unquote|contains|search|query|exists) *\(|->>|#>|meta->|whereJson" --include='*.php' --include='*.sql' --exclude-dir=vendor .
grep -rnE "(->meta|\['meta'\])\[|json_decode\([^)]*meta" --include='*.php' --exclude-dir=vendor .
grep -rnE "[\"']tool_call(_result)?[\"']|\[.parameters.\]|\.parameters\b" --include='*.php' --include='*.js' --include='*.jsx' --include='*.ts' --include='*.tsx' --include='*.vue' --exclude-dir=vendor --exclude-dir=node_modules .
grep -rnE 'cache(Write|Read)Tokens' --include='*.php' --exclude-dir=vendor .
grep -rnE 'MediaType::TEXT\b' --include='*.php' --exclude-dir=vendor .
grep -rnE 'setContents\(' --include='*.php' --exclude-dir=vendor .
```

Also search the code that receives serialized messages (frontend, API consumers, log processing) for metadata keys. Add every key the `(add|set)Metadata\(` search shows the application writing to the list of framework keys:

```bash
grep -rnE '\b(citations|stop_reason|cacheReadTokens|cacheWriteTokens)\b' --include='*.js' --include='*.jsx' --include='*.ts' --include='*.tsx' --include='*.vue' --exclude-dir=vendor --exclude-dir=node_modules .
```

How to follow the hits:
- **`jsonSerialize()` calls** on a message: follow the array. Reads of a metadata key go to Case 1. Code that unsets, renames or filters keys goes to Case 3.
- **`function jsonSerialize()` declarations**: only classes that extend a Neuron message class matter. If the override adds keys, use Case 7. If it removes or renames keys, use Case 3.
- **`addMetadata(` / `setMetadata(` calls** list the metadata keys the application writes. Search for their readers (Cases 1 to 4). `function addMetadata(` declarations go to Case 6.
- **JSON query and `meta` read patterns**: only the `meta` column of the chat messages table matters. That is the `SQLMessageStore` table (`chat_messages` by default) or the table of the model passed to `EloquentMessageStore`. Use Case 4. Queries on `$.__id`, `$.usage`, `$.type` and `$.tools` stay as they are.
- **`tool_call` / `parameters`**: code that reads the `tools` entries of serialized tool messages goes to Case 5. `parameters` in tool definitions, JSON schemas or unrelated code is not affected.
- **`cacheWriteTokens` / `cacheReadTokens`**: Case 8. **`MediaType::TEXT`**: Case 9.
- **`setContents(`** with an array argument (literal or variable): Case 10. A string or single-block argument needs no change.
- **Client code** reading metadata keys from a message object: Case 2. Reading tool entries: Case 5.

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: PHP code reads metadata from a serialized message

Before (3.x):

```php
$data = $message->jsonSerialize();
$source = $data['source'] ?? null;

$reason = $message->jsonSerialize()['stop_reason'] ?? null;
```

After (4.x):

```php
$source = $message->getMetadata('source');

$reason = $message->getMetadata('stop_reason');
```

When the code holds only the array (a payload it received), read `$data['__meta']['source'] ?? null`. Add a fallback to `$data['source']` if the array may have been produced by 3.x.

### Case 2: Clients of serialized messages

Serialized messages reach clients in several ways:
- API responses (`json_encode($message)`, a serialized chat history) and queued payloads.
- Log records and event payloads: the `message` entry of `InferenceStart`, `InferenceStop`, `MessageSaving`, `MessageSaved`, `Extracting` and `Extracted`, the `response` entry of `InferenceStop`, and the `original`, `processed` and `question` entries of the RAG events `PreProcessing`, `PreProcessed`, `Retrieving`, `Retrieved`, `PostProcessing` and `PostProcessed`. Guide 46 migrates the event classes themselves.

Before (3.x):

```js
const source = message.source;
```

After (4.x):

```js
const source = message.__meta?.source;
```

- Use `message.__meta?.source ?? message.source` only where the client also reads data written before the upgrade: raw `meta` values, `.chat` files, or queued or cached payloads produced by 3.x.
- `__meta` is `[]` when a message has no metadata. Typed decoders (TypeScript types and guards, JSON Schema, DTOs in other languages) must accept an empty array as well as an object.

### Case 3: Code that removes, renames or filters metadata keys in a serialized message

This covers `jsonSerialize()` overrides in message subclasses, API resources and transformers, and log-context redaction. In 4.x a top-level `unset($data['secret'])` does nothing, so the value is still sent or logged. Address the key under `__meta`.

Before (3.x):

```php
$data = $message->jsonSerialize();
unset($data['internal_note']);
```

After (4.x):

```php
$data = $message->jsonSerialize();
unset($data['__meta']['internal_note']);
```

Renames work the same way (`$data['__meta']['new'] = $data['__meta']['old']`). A filter that keeps a list of top-level keys must keep `__meta` and filter inside it. If the redaction lives in an override of the 3.x log observer, change the key path now. Guide 46 moves that code later.

### Case 4: Queries and reads on the `meta` column

Rows written by 4.x hold metadata at `$.__meta.<key>`. Rows written by 3.x, including the rows guide 30 migrated, hold it at `$.<key>`. Match both paths for as long as the older rows matter.

Before (3.x):

```sql
-- MySQL / MariaDB
SELECT * FROM chat_messages WHERE JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source')) = 'web';
-- SQLite
SELECT * FROM chat_messages WHERE json_extract(meta, '$.source') = 'web';
-- PostgreSQL
SELECT * FROM chat_messages WHERE meta::jsonb ->> 'source' = 'web';
```

After (4.x):

```sql
-- MySQL / MariaDB
SELECT * FROM chat_messages WHERE JSON_UNQUOTE(COALESCE(JSON_EXTRACT(meta, '$.__meta.source'), JSON_EXTRACT(meta, '$.source'))) = 'web';
-- SQLite
SELECT * FROM chat_messages WHERE COALESCE(json_extract(meta, '$.__meta.source'), json_extract(meta, '$.source')) = 'web';
-- PostgreSQL
SELECT * FROM chat_messages WHERE COALESCE(meta::jsonb #>> '{__meta,source}', meta::jsonb ->> 'source') = 'web';
```

Eloquent, where `ChatMessage` is the model passed to `EloquentMessageStore`. Before (3.x):

```php
$messages = ChatMessage::where('meta->source', 'web')->get();
```

After (4.x):

```php
$messages = ChatMessage::where(
    fn ($query) => $query->where('meta->__meta->source', 'web')->orWhere('meta->source', 'web')
)->get();
```

`whereJsonContains()` and other JSON-path helpers get the same `meta->__meta->` path with a fallback to the old path.

PHP reads of the column. Before (3.x):

```php
$source = $record->meta['source'] ?? null;
```

After (4.x):

```php
$source = $record->meta['__meta']['source'] ?? $record->meta['source'] ?? null;
```

A `meta` value read from the `SQLMessageStore` table with PDO is a JSON string. Decode it, then apply the same fallback.

### Case 5: Consumers of serialized tool entries

This covers code that reads the `tools` array of `tool_call` / `tool_call_result` messages, whether it comes from payloads, the `meta` column or `.chat` files. The `tool` entry of the `ToolCalling` / `ToolCalled` payloads and log records has the same shape.

1. `parameters` is no longer written. It held the provider-specific options set on the registered tool (`setParameters()`), not the input schema. If the consumer needs the value, take it from the tool the agent registers under the entry's `name` (`getParameters()` on that `ToolInterface`). Otherwise drop the read.
2. `result` can be an array: a list of content blocks for a multimodal tool result, or `{"is_error": true, "blocks": [...]}` for a failed call. Handle both, as below.
3. The new keys `deferred`, `approval` (`null`, `"pending"`, `"approved"` or `"rejected"`), `approvalReason` and `rejectReason` are present on every entry. Strict decoders must accept them.
4. Stored 3.x entries load unchanged. Do not migrate them.

Before (3.x):

```php
foreach ($data['tools'] as $entry) {
    $rows[] = [$entry['name'], json_encode($entry['parameters']), $entry['result']];
}
```

PHP code that holds the objects (`$message->getToolCalls()`, `$event->tool`, `$chunk->tool`) reads the `ToolCall` directly: `$text = $call->hasResult() ? (string) $call->getResult() : null;` and `$failed = $call->hasResult() && $call->getResult() instanceof ToolOutput && $call->getResult()->isError();` (`use NeuronAI\Tools\ToolOutput;`). For arrays, the block `type` is the `ContentBlockType` enum before JSON encoding and the string `'text'` after it, so match both.

After (4.x):

```php
use NeuronAI\Chat\Enums\ContentBlockType;

foreach ($data['tools'] as $entry) {
    $result = $entry['result'];

    if (is_array($result)) {
        $blocks = $result['blocks'] ?? $result;
        $result = implode(' ', array_column(array_filter($blocks, fn (array $block): bool => in_array($block['type'], [ContentBlockType::TEXT, 'text'], true)), 'content'));
    }

    $rows[] = [$entry['name'], $result];
}
```

The same in a client:

```js
const blocks = Array.isArray(entry.result) ? entry.result : entry.result?.blocks;
const text = blocks ? blocks.filter((block) => block.type === 'text').map((block) => block.content).join(' ') : entry.result;
const failed = entry.result?.is_error === true;
```

### Case 6: Overriding `addMetadata()`

`addMetadata()` now takes `mixed`. An override with the 3.x parameter type fails when the class loads ("Declaration ... must be compatible"). This applies to subclasses of any message class and any content block class (`TextContent`, `ImageContent`, ...). Overrides in `NeuronAI\RAG\Document` subclasses belong to guide 20.

Before (3.x):

```php
use NeuronAI\Chat\Messages\UserMessage;

class AuditedMessage extends UserMessage
{
    /** @var string[] */
    protected array $metadataKeys = [];

    public function addMetadata(string $key, string|array|null $value): self
    {
        $this->metadataKeys[] = $key;
        parent::addMetadata($key, $value);

        return $this;
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\UserMessage;

class AuditedMessage extends UserMessage
{
    /** @var string[] */
    protected array $metadataKeys = [];

    public function addMetadata(string $key, mixed $value): self
    {
        $this->metadataKeys[] = $key;
        parent::addMetadata($key, $value);

        return $this;
    }
}
```

### Case 7: A message subclass that adds keys in `jsonSerialize()`

When 4.x loads a message it wrote, it reads metadata only from `__meta`, so top-level keys added by a `jsonSerialize()` override are lost. Keep the class and store the value as metadata. A message loaded from a store is a plain `UserMessage` / `AssistantMessage` in both versions, so code that reads the value must use `getMetadata()`.

Before (3.x):

```php
use NeuronAI\Chat\Messages\UserMessage;

class TicketMessage extends UserMessage
{
    public function __construct(string $content, protected string $ticket)
    {
        parent::__construct($content);
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'ticket' => $this->ticket];
    }
}
```

After (4.x):

```php
use NeuronAI\Chat\Messages\UserMessage;

class TicketMessage extends UserMessage
{
    public function __construct(string $content, protected string $ticket)
    {
        parent::__construct($content);
        $this->addMetadata('ticket', $ticket);
    }
}

$ticket = $message->getMetadata('ticket');
```

Clients that read `message.ticket` switch to `message.__meta?.ticket` (Case 2).

### Case 8: Reading Anthropic's cache token counts

New messages hold `cacheWriteTokens` and `cacheReadTokens` as integers. Messages stored by 3.x hold strings. Casting to `int` works for both.

Before (3.x):

```php
$cacheRead = $message->getMetadata('cacheReadTokens');

if ($cacheRead !== null && $cacheRead !== '0') {
    $metrics->increment('anthropic.cache_hits');
}
```

After (4.x):

```php
$cacheRead = (int) $message->getMetadata('cacheReadTokens');

if ($cacheRead > 0) {
    $metrics->increment('anthropic.cache_hits');
}
```

Guide 45 revisits the same matches, because the meaning of these counts and of `inputTokens` also changed.

### Case 9: `MediaType::TEXT`

The case was renamed. Its value `'text/plain'` did not change, so `MediaType::from('text/plain')` and stored content blocks are unaffected.

Before (3.x):

```php
use NeuronAI\Chat\Enums\MediaType;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;

$block = new FileContent(base64_encode($notes), SourceType::BASE64, MediaType::TEXT, 'notes.txt');
```

After (4.x):

```php
use NeuronAI\Chat\Enums\MediaType;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;

$block = new FileContent(base64_encode($notes), SourceType::BASE64, MediaType::TXT, 'notes.txt');
```

### Case 10: `setContents()` with an array

In 3.x, `setContents(array)` appended the blocks to the message's existing blocks. In 4.x it replaces them. Keep the 3.x behaviour where the message may already hold content, such as a message received from a provider, loaded from a store or built earlier with content:

Before (3.x):

```php
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;

$message->setContents([new TextContent($disclaimer), new ImageContent($chartUrl, SourceType::URL)]);

$message->setContents($extraBlocks);
```

After (4.x):

```php
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;

$message->addContent(new TextContent($disclaimer))
    ->addContent(new ImageContent($chartUrl, SourceType::URL));

foreach ($extraBlocks as $block) {
    $message->addContent($block);
}
```

Leave a call alone when the message has no content at that point (built without content just before), because both versions give the same result. If the surrounding code shows that replacement was intended, for example code that rebuilds the whole content from the old blocks and so duplicated them in 3.x, ask the developer whether to keep the 3.x result or replace.

## Checklist

- PHP code reads metadata with `getMetadata()`, never from `jsonSerialize()` output. Code that holds only a serialized array reads `['__meta'][<key>]`.
- Clients of serialized messages read metadata under `__meta`, with a fallback to the top level only where they read data written before the upgrade. Typed clients accept `__meta: []`.
- Code that unsets, renames or filters metadata keys in serialized messages addresses `['__meta'][<key>]`.
- Queries on metadata keys in the `meta` column (MySQL/MariaDB, SQLite, PostgreSQL, Eloquent) match both `$.__meta.<key>` and `$.<key>`.
- PHP reads of the `meta` column fall back from `['__meta'][<key>]` to `[<key>]`.
- Consumers of tool entries do not read `parameters`, accept array results and accept the new keys.
- No stored `meta` value or `.chat` file was rewritten, and no data migration was added for this guide.
- `addMetadata()` overrides in message and content block subclasses accept `mixed`.
- No message subclass adds keys in `jsonSerialize()`. That data is stored with `addMetadata()` and read with `getMetadata()`.
- Code reading `cacheWriteTokens` or `cacheReadTokens` casts the value to `int`.
- No `MediaType::TEXT` remains.
- Every `setContents()` call with an array runs on a message without content, was replaced with `addContent()` to keep the 3.x append behaviour, or was kept after the developer confirmed replacement.
