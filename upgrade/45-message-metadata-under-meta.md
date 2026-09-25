# Upgrade: Message metadata serializes under `__meta`

## Summary

In 3.x `Message::jsonSerialize()` merged the metadata into the same level as the message's own fields. A metadata
key named like a field (`role`, `content`, `usage`, `type`, `tools`) was lost when the message was stored or misread
when it was loaded, and a `type` of `tool_call` made the whole thread fail to load. The metadata now has a level of
its own.

- **Metadata serializes under `__meta`.** The message's own fields stay at the top level: `__id`, `role`, `content`,
  `usage`, and `type` and `tools` on tool messages. Every store writes this shape: `FileMessageStore` entries and the
  `meta` column of `SQLMessageStore` and `EloquentMessageStore` tables.
- **Stored histories need no migration.** Rows and files written before keep their shape and load as they always did,
  and a thread can mix both shapes, so never rewrite stored `meta` values. A thread that 3.x failed to load because a
  message carried a number or a boolean in its metadata now loads.
- **`addMetadata()` accepts any value.** It took `string|array|null`; it now takes `mixed`, like `setMetadata()`. A
  stored value comes back as JSON decodes it: an object returns as an array, except `citations`.
- **Anthropic's cache token counts are integers.** The `cacheWriteTokens` and `cacheReadTokens` metadata were
  strings. A message stored before keeps strings when it's loaded.

| Before (3.x) | After |
|---|---|
| `{"__id": "msg_…", "role": "user", "content": […], "source": "web"}` | `{"__id": "msg_…", "role": "user", "content": […], "__meta": {"source": "web"}}` |
| `$message->jsonSerialize()['source']` | `$message->getMetadata('source')` |
| `JSON_EXTRACT(meta, '$.source')` | `JSON_EXTRACT(meta, '$.__meta.source')` in rows written by 4.x, `'$.source'` in older rows |
| `addMetadata(string $key, string\|array\|null $value)` | `addMetadata(string $key, mixed $value)` |
| `$message->getMetadata('cacheReadTokens')` returns `'30'` | Returns `30`; a message stored before still returns `'30'` |

## How to Refactor

### Case 1: Reading metadata from a serialized message

`jsonSerialize()` no longer returns the metadata at its top level. In PHP, read it from the message.

Before:

```php
$reason = $message->jsonSerialize()['stop_reason'] ?? null;
```

After:

```php
$reason = $message->getMetadata('stop_reason');
```

A client that receives a serialized message, such as an API response or a queued payload, finds the metadata under
`__meta`. The same applies to the `message` entry that `InferenceStart`, `InferenceStop`, `MessageSaving`,
`MessageSaved`, `Extracting` and `Extracted` return from `toArray()`, and so to their log records.

Before:

```js
const source = message.source;
```

After:

```js
const source = message.__meta?.source;
```

### Case 2: Querying metadata in the messages table

Rows written by 4.x keep the metadata under `__meta` inside the `meta` column; rows written before keep it at the top
level. A query on a metadata key must match both paths for as long as the older rows matter. Queries on `__id`,
`usage`, `type` or `tools` don't change.

Before (MySQL):

```sql
SELECT * FROM chat_messages
WHERE JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source')) = 'web';
```

After:

```sql
SELECT * FROM chat_messages
WHERE JSON_UNQUOTE(COALESCE(JSON_EXTRACT(meta, '$.__meta.source'), JSON_EXTRACT(meta, '$.source'))) = 'web';
```

With Eloquent, before:

```php
ChatMessage::where('meta->source', 'web')->get();
```

After:

```php
ChatMessage::where(
    fn ($query) => $query->where('meta->__meta->source', 'web')->orWhere('meta->source', 'web')
)->get();
```

### Case 3: Overriding `addMetadata()`

Messages and content blocks share `addMetadata()`. PHP rejects an override whose parameter is narrower than the
method it overrides, so widen it.

Before:

```php
public function addMetadata(string $key, string|array|null $value): self
```

After:

```php
public function addMetadata(string $key, mixed $value): self
```

### Case 4: A message subclass that adds keys in `jsonSerialize()`

Keys added by a `jsonSerialize()` override came back as metadata when the message was loaded. In a message written
by 4.x they are ignored: only `__meta` holds metadata. Store the data as metadata instead.

Before:

```php
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

After:

```php
$message = (new UserMessage($content))->addMetadata('ticket', $ticket);

$ticket = $message->getMetadata('ticket');
```

### Case 5: Reading Anthropic's cache token counts

New messages hold `cacheWriteTokens` and `cacheReadTokens` as integers, while messages stored before hold strings.
Cast the value when you read it, which works for both.

Before:

```php
$cacheRead = $message->getMetadata('cacheReadTokens');

if ($cacheRead !== null && $cacheRead !== '0') {
    $metrics->recordCacheHit($message->getId());
}
```

After:

```php
$cacheRead = (int) $message->getMetadata('cacheReadTokens');

if ($cacheRead > 0) {
    $metrics->recordCacheHit($message->getId());
}
```

## What to Search For

```
grep -rn "jsonSerialize()\[" --include="*.php" .
grep -rn "function addMetadata(" --include="*.php" .
grep -rn "function jsonSerialize(" --include="*.php" .
grep -rniE "json_(extract|value)|meta->" --include="*.php" --include="*.sql" .
grep -rnE "cache(Write|Read)Tokens" --include="*.php" .
```

A `function jsonSerialize(` match matters only in a class that extends a message class. Also search the client code
that consumes serialized messages for the metadata keys your application sets.

## Checklist

- PHP code reads metadata with `getMetadata()`, never from `jsonSerialize()` output.
- Clients of serialized messages read metadata under `__meta`.
- Queries on metadata in the `meta` column match both `$.__meta.<key>` and `$.<key>`.
- `addMetadata()` overrides accept `mixed`.
- No message subclass adds keys in `jsonSerialize()`; that data lives in metadata.
- Code reading `cacheWriteTokens` or `cacheReadTokens` casts the value to `int`.
