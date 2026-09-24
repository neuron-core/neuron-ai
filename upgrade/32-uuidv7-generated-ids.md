# Upgrade: Generated IDs are UUIDv7

## Summary

- **`UniqueIdGenerator::generateId($prefix)` returns the prefix followed by an RFC 9562 UUIDv7**, such as
  `msg_01a0d32e-6627-7642-9213-7ca5b945523d`. It used to append a 64-bit integer whose only per-process part
  was a random 10-bit number. Long-running workers could draw the same number, and forked processes inherited
  it, so they could generate the same ID: two new conversations could receive one address, and a RAG document
  could overwrite another in a vector store. The generator now keeps no state and each ID carries 74 random
  bits. IDs still sort by creation time to the millisecond.
- **Every ID Neuron generates changes format:** messages, conversations (`workflow_`), runs, RAG documents,
  and the IDs stream adapters fill in. A generated workflow ID is 45 characters long, a message ID 40.
- **`generateUUID()` takes no argument.** It returns a UUIDv7. It used to hash a given ID into a UUID labelled
  version 4.
- **Stored IDs stay valid.** Neuron treats IDs as opaque strings and never parses or orders them, so old and
  new IDs coexist. They do not sort together: as strings, new IDs sort before old ones.

| Before | After |
|---|---|
| `generateId('msg_')` → `msg_7508849740466544640` | `msg_01a0d32e-6627-7642-9213-7ca5b945523d` |
| `generateUUID()` → a hash labelled version 4 | A random UUIDv7 |
| `generateUUID($id)` | Removed: derive the UUID in your application (Case 1) |
| `getCurrentTimestamp()`, `waitForNextTimestamp()`, static `$machineId`, `$sequence`, `$lastTimestamp` | Removed |

## How to Refactor

### Case 1: Code calling `generateUUID($id)`

Derive the UUID in your application. When the UUIDs must stay the same as before, for example because they
identify points already stored in a vector store, reproduce the previous derivation:

```php
/** The UUID that UniqueIdGenerator::generateUUID($id) derived from an ID. */
function legacyUuid(string|int $id): string
{
    $hex = md5((string) $id);
    $hex = substr($hex, 0, 12) . '4' . substr($hex, 13);
    $hex = substr($hex, 0, 16) . sprintf('%02x', (hexdec(substr($hex, 16, 2)) & 0x3f) | 0x80) . substr($hex, 18);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
}
```

Before:

```php
$document->setId(UniqueIdGenerator::generateUUID($record->id));
```

After:

```php
$document->setId(legacyUuid($record->id));
```

### Case 2: Columns, validation and tests expecting digits

Widen columns that store generated IDs to at least 45 characters. Neuron's own tables already fit:
`thread_id VARCHAR(255)`, `message_id VARCHAR(64)`, and workflow persistence keys up to 255 bytes. Replace
validation rules, route constraints and test assertions that expect digits after the prefix.

Before:

```php
$this->assertMatchesRegularExpression('/^msg_\d+$/', $message->getId());
```

After:

```php
$this->assertMatchesRegularExpression('/^msg_[0-9a-f-]{36}$/', $message->getId());
```

Order records by their storage key or creation time, never by generated ID: old and new IDs do not sort
together.

### Case 3: Code reaching into the generator

Subclasses overriding `getCurrentTimestamp()` or `waitForNextTimestamp()`, and tests resetting the static
`$machineId`, `$sequence` or `$lastTimestamp` through reflection, have nothing left to act on. Remove them: the
generator keeps no state between calls.

## What to Search For

```
grep -rn "generateUUID(" --include="*.php" .
grep -rnE "getCurrentTimestamp|waitForNextTimestamp|machineId|lastTimestamp" --include="*.php" .
grep -rnE "(msg|workflow|run)_(\[0-9\]|\\\\d)" .
```

Also review the migrations of tables that store generated IDs.

## Checklist

- No code passes an argument to `generateUUID()`; UUIDs that must stay stable use the previous derivation.
- Columns storing generated IDs hold at least 45 characters.
- No validation rule, route constraint or test expects digits after an ID prefix.
- Nothing orders records by generated ID.
- No code overrides or resets the generator's removed methods and properties.
