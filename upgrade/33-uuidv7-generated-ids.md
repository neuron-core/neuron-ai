# Upgrade: Generated IDs are UUIDv7

## Summary

`NeuronAI\UniqueIdGenerator` keeps its name and its two public methods. `generateId($prefix)` now returns the prefix followed by a 36-character lowercase UUIDv7, not a 19-digit number. IDs still sort by creation time.

| 3.x | 4.x |
|---|---|
| `generateId('msg_')` → `msg_7511018384420315136` (prefix + 19 digits) | `msg_01a0f1fd-754a-7372-a20f-72463ec67947` (prefix + UUIDv7) |
| `generateId()` → `7511018384420315137`, a numeric string that fits `BIGINT` | `01a0f1fd-754a-71ea-a5f5-a03862de047f` |
| `generateUUID()` → a UUID labelled version 4 | A random UUIDv7 |
| `generateUUID($id)` → the same UUID for the same `$id` | Removed. A positional argument is silently ignored and a random UUIDv7 is returned. A named `id:` throws `Error: Unknown named parameter $id`. Fix every call that passes an argument (Case 1). |
| protected static `getCurrentTimestamp()`, `waitForNextTimestamp()`, `$machineId`, `$sequence`, `$lastTimestamp` | Removed (Case 5) |

These are the IDs Neuron generates that a 3.x app could see:
- Message IDs (`Message::getId()`): `msg_` + digits (23 characters) becomes `msg_` + UUIDv7 (40 characters). Guide 38 covers which ID streamed chunks carry.
- Stream adapter IDs: the AG-UI adapter's `msg_`, `call_`, `run_` and `thread_` + digits, and the Vercel AI adapter's `call_` + digits and unprefixed digit part IDs, become `msg_`, `call_`, `run_`, `text_` and `reasoning_` + UUIDv7. The 4.x AG-UI adapter no longer generates thread IDs (guide 36). Guide 37 covers the other frame changes.
- Default RAG `Document` IDs: still 36-character UUIDs, but the version digit (the first character of the third group) changes from `4` to `7`.

ID values stored with 3.x are read as-is. Neuron treats IDs as opaque strings and never parses them or orders by them, so stored 3.x IDs (messages in chat history, document IDs in vector stores) stay valid next to new ones and their values need no migration. Only columns sized or typed for 3.x IDs change (Case 2). Old and new IDs do not sort together: new message IDs (`msg_01…`) sort before stored 3.x IDs (`msg_75…`).

## What to Search For

Run from the application root:

```bash
# 1. Every use of the generator. Follow each generateId()/generateUUID() result to where the ID is
#    stored, cast, validated or compared (Cases 1-3), and follow each subclass of UniqueIdGenerator (Case 5).
grep -rn 'UniqueIdGenerator' --include='*.php' --exclude-dir=vendor .

# 2. generateUUID() calls: every call with an argument is Case 1 (this also finds calls through a subclass, an alias or static::)
grep -rn 'generateUUID(' --include='*.php' --exclude-dir=vendor .

# 3. Digits expected after an ID prefix, or 19-digit IDs, in regexes, route constraints, validation rules, tests and frontend code (Case 3)
grep -rnE '_\[(0-9|\[:digit:\])\]' --exclude-dir=vendor --exclude-dir=node_modules .
grep -rnF -e '_\d' -e '_\\d' -e '{19}' -e 'digits:19' --exclude-dir=vendor --exclude-dir=node_modules .

# 4. Checks that require UUID version 4 (Case 3)
grep -rniE '4\[[0-9a-f-]+\]\{3\}|uuid:?4|v4_random|uuidv4' --exclude-dir=vendor --exclude-dir=node_modules .

# 5. Ordering by a generated ID (Case 4)
grep -rniE '(orderBy|order by|sortBy|usort|latest|oldest)[^;]*(message_id|run_id|getId)' --include='*.php' --exclude-dir=vendor .

# 6. Removed generator internals (Case 5)
grep -rnE 'getCurrentTimestamp|waitForNextTimestamp|machineId|lastTimestamp' --include='*.php' --exclude-dir=vendor .
```

Also review the migrations of tables that store generated IDs, looking for columns sized for 3.x IDs and integer columns (Case 2). Patterns 3 and 5 only catch Neuron's column names and common regex forms, so also check the columns and checks the application fills from its own `generateId()` calls. If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: `generateUUID()` called with an argument

In 3.x, `generateUUID($id)` returned the same UUID for the same ID, which let re-ingested documents keep their vector-store IDs. In 4.x the call still runs but returns a random UUID, so re-ingestion adds duplicate points instead of replacing the existing ones. PHPStan reports each such call as `Static method NeuronAI\UniqueIdGenerator::generateUUID() invoked with 1 parameter, 0 required.`

1. Add this helper to the application, in its own namespace (for example `App\Support`). Put it either in a file that Composer autoloads through `autoload.files` (then run `composer dump-autoload`), or as a static method on an existing helper class. It returns exactly what 3.x `generateUUID($id)` returned, so stored IDs keep matching.

   ```php
   <?php

   declare(strict_types=1);

   namespace App\Support;

   /** The UUID that 3.x UniqueIdGenerator::generateUUID($id) derived from an ID. */
   function legacyUuid(string|int $id): string
   {
       $hex = md5((string) $id);
       $hex = substr($hex, 0, 12) . '4' . substr($hex, 13);
       $hex = substr($hex, 0, 16) . sprintf('%02x', (hexdec(substr($hex, 16, 2)) & 0x3f) | 0x80) . substr($hex, 18);

       return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
   }
   ```

2. Replace every call that passes an ID, including named `generateUUID(id: ...)` calls.

   Before (guide 20 already turned 3.x `$document->id = ...` into `setId()`):

   ```php
   use NeuronAI\UniqueIdGenerator;

   $document->setId(UniqueIdGenerator::generateUUID($record->id));
   $pointId = UniqueIdGenerator::generateUUID($record->id);
   ```

   After:

   ```php
   use function App\Support\legacyUuid;

   $document->setId(legacyUuid($record->id));
   $pointId = legacyUuid($record->id);
   ```

3. Replace `generateUUID(null)` with `generateUUID()`. When the argument can be null, keep the 3.x behaviour:

   ```php
   $uuid = $id === null ? UniqueIdGenerator::generateUUID() : legacyUuid($id);
   ```

### Case 2: Generated IDs stored as numbers or in narrow columns

Follow every `generateId(` hit from search 1 to where its result is stored. Do the same for the message, run and document IDs the application stores.

1. In 3.x an unprefixed `generateId()` returned a numeric string. Remove the `(int)`/`intval()` casts, `int` types, `is_numeric()`/`ctype_digit()` checks and integer validation rules that apply to it, and keep the value as a string.

   Before:

   ```php
   $order->reference = (int) UniqueIdGenerator::generateId();
   ```

   After:

   ```php
   $order->reference = UniqueIdGenerator::generateId();
   ```

2. Size every string column that stores generated IDs to the prefix length + 36: 36 without a prefix, 40 for `msg_` and `run_`, 41 for `call_` and `text_`, 46 for `reasoning_`. The same rule applies to the prefixes the application passes itself, including the workflow and thread IDs that guide 13 generates with `generateId()`. A column sized for 3.x IDs (23 characters for `msg_` + digits, 22 for a 3.x `uniqid()` workflow ID) rejects or truncates the new ones.
3. Convert an integer (`BIGINT`) column that holds `generateId()` output to a string column of at least 36 characters. Its stored values stay valid as the digit strings 3.x returned.
4. Generate these migrations in the application's own migration system. Neuron's own tables need no change: `chat_messages` holds `thread_id` up to 255 bytes and `message_id` up to 64 bytes, and workflow persistence keys hold up to 255 bytes.

### Case 3: Format checks in validation, routes, tests and frontends

1. In assertions on IDs that 4.x has just generated, replace digits after the prefix with the UUID form.

   Before:

   ```php
   $this->assertMatchesRegularExpression('/^msg_\d+$/', $message->getId());
   ```

   After:

   ```php
   $this->assertMatchesRegularExpression('/^msg_[0-9a-f-]{36}$/', $message->getId());
   ```

2. Checks on IDs that can come back from storage or from a client, such as route constraints and request validation, must accept both forms, because stored 3.x IDs stay in use. Put `[0-9a-f-]+` where the digits were: a `msg_[0-9]+` route constraint becomes `msg_[0-9a-f-]+`, and an unprefixed `\d{19}` becomes `[0-9a-f-]+`.
3. A check that requires UUID version 4 for document IDs (a `4` fixed at the start of the third group in a regex, or a validation rule or library call limited to version 4) must also accept version 7. Documents stored with 3.x keep their version-4 IDs.

   Before:

   ```php
   $valid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $documentId) === 1;
   ```

   After:

   ```php
   $valid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[47][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $documentId) === 1;
   ```

### Case 4: Ordering by a generated ID

For each hit of search 5, and for any query that orders by a column the application fills from `generateId()`, order by the auto-increment key or `created_at` instead. As strings, new IDs sort before stored 3.x IDs (`msg_01…` < `msg_75…`), so a list that mixes them comes out in the wrong order.

### Case 5: Code that reaches into the generator

Delete subclass overrides of `getCurrentTimestamp()` and `waitForNextTimestamp()`: 3.x called them through `self::`, so they never took effect. Delete reflection that reads or resets `$machineId`, `$sequence` or `$lastTimestamp`: it throws `ReflectionException` in 4.x.

## Checklist

- No `generateUUID(` call passes an argument, and PHPStan reports no `generateUUID() invoked with 1 parameter, 0 required`. UUIDs that must stay stable come from `legacyUuid()`.
- String columns that store generated IDs hold the prefix length + 36 characters. No integer column, `(int)` cast, `int` type or `is_numeric()`/`ctype_digit()` check applies to `generateId()` output. The migrations were generated in the application's migration system.
- No validation rule, route constraint, test or frontend check expects digits after an ID prefix. Checks on stored or client-sent IDs accept both the 3.x and the 4.x form. No check requires UUID version 4 for document IDs.
- Nothing orders records by a generated ID.
- No code overrides or reflects on `getCurrentTimestamp()`, `waitForNextTimestamp()`, `$machineId`, `$sequence` or `$lastTimestamp`.
