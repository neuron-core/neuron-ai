# Upgrade: Input tokens include the prompt cache

## Summary

No API changed, so nothing fails to compile or run. What changed is the meaning of the token counts that `NeuronAI\Providers\Anthropic\Anthropic`, `NeuronAI\Providers\Anthropic\AnthropicVertex`, `NeuronAI\Providers\AWS\BedrockRuntime` and app subclasses of them report. The values differ only for answers that read from or write to the prompt cache. OpenAI, Azure, Gemini, Mistral, Ollama and the other providers are unchanged.

- **`inputTokens` is the whole prompt.** Anthropic's usage adds `cache_read_input_tokens` and `cache_creation_input_tokens` to `input_tokens`. Bedrock's adds `cacheReadInputTokens` and `cacheWriteInputTokens` to `inputTokens`. In 3.x, `inputTokens` counted neither.
- **`cachedInputTokens` keeps its value (the tokens read from the cache).** On each answer as the provider returns it, it is now the part of `inputTokens` read from the cache, as it already was for OpenAI and Gemini.
- **Anthropic's `cacheWriteTokens` metadata counts each written token once.** 3.x added `cache_creation_input_tokens` to its per-TTL breakdown (`ephemeral_5m_input_tokens`, `ephemeral_1h_input_tokens`), which counts the same tokens. So the 3.x value doubled whenever the response carried the breakdown. Guide 34 covers the metadata's int type and its `__meta` path.
- **`Usage::getTotal()` and `ChatHistory::calculateTotalUsage()` grow accordingly.**
- For these providers with prompt caching, the chat history now trims, and `Summarization` now summarizes, at the real prompt size.

| 3.x | 4.x |
|---|---|
| Anthropic: `input_tokens` 100, `cache_creation_input_tokens` 50, `cache_read_input_tokens` 30 give `inputTokens` 100 | `inputTokens` 180, `cachedInputTokens` 30 |
| Bedrock: `inputTokens` 20, `cacheReadInputTokens` 15, `cacheWriteInputTokens` 5 give `inputTokens` 20 | `inputTokens` 40, `cachedInputTokens` 15 |
| Anthropic `cacheWriteTokens` for 50 written tokens reported with their TTL breakdown: 100 | 50 |

**Stored data:** no stored data needs migrating, and stored values are never rewritten. 4.x reads messages stored by 3.x as-is, and they keep the 3.x meaning: `input_tokens` excludes the cache, and `cacheWriteTokens` may be doubled. 4.x also loads their stored `cached_input_tokens`, which 3.x dropped on load. Code that computes sizes or costs from stored messages must tell the two apart (Cases 4 and 5).

## What to Search For

Run from the application root:

```bash
# 1. Does the app use an affected provider?
grep -rnE '\b(Anthropic(Vertex)?|BedrockRuntime)\b' --include='*.php' --exclude-dir=vendor .

# 2. Code that reads token usage, in PHP, SQL and client code
grep -rnE 'inputTokens|input_tokens|cachedInputTokens|cached_input_tokens|cache(Write|Read)Tokens|getTotal\(|calculateTotalUsage\(|getUsage\(' --include='*.php' --include='*.sql' --include='*.js' --include='*.jsx' --include='*.ts' --include='*.tsx' --include='*.vue' --exclude-dir=vendor --exclude-dir=node_modules .

# 3. Token limits for the chat history and Summarization
grep -rnE '[cC]ontextWindow|new ChatHistory\(|Summarization|setMaxTokens\(' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:
- **Search 1** lists the provider classes. Grep for `extends <Class>` on every app class it finds, so subclasses count too, and note which agents and workflows use these providers (`provider()` hooks, `setAiProvider()` calls, factories). If search 1 finds nothing, this guide does not apply.
- **Search 2**: a match matters when the usage can come from one of those providers, including code that handles every provider the same way. Ignore `getTotal(` on objects that are not a `Usage`. Then route each match:
  - code that adds cached tokens back to get the prompt size or total: Case 1
  - cost code: Case 2
  - budgets, quotas and alerts: Case 3
  - queries, row reads and exports of stored messages: Case 4
  - totals of `cacheWriteTokens`: Case 5
  - code that copies usage values into the application's own tables, logs, metrics or external services (e.g. `UsageLog::create(['prompt_tokens' => $usage->inputTokens])`): note the columns or fields it writes and grep those names in PHP, SQL and dashboard code, because search 2 does not find them. Route each reader: prompt size to Case 1, costs to Case 2, thresholds to Case 3. Rows written before the upgrade keep the 3.x meaning, so split them by the upgrade time, as Case 4 says for data without a serialized message.
- **Search 3**: only the agents and workflows found through search 1 matter. Use Case 3.

## How to Refactor

### Case 1: Code that adds the cache back to the prompt size or the total

Remove every term that adds `cachedInputTokens` or `cacheWriteTokens` to `inputTokens` or `getTotal()`. Also remove provider branches that existed only for that. Client code that adds `cached_input_tokens` to `input_tokens` in serialized usage changes the same way.

Before (3.x):

```php
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\AWS\BedrockRuntime;

$usage = $message->getUsage();

if ($provider instanceof Anthropic || $provider instanceof BedrockRuntime) {
    $promptTokens = $usage->inputTokens + $usage->cachedInputTokens + (int) $message->getMetadata('cacheWriteTokens');
    $totalTokens = $usage->getTotal() + $usage->cachedInputTokens + (int) $message->getMetadata('cacheWriteTokens');
} else {
    $promptTokens = $usage->inputTokens;
    $totalTokens = $usage->getTotal();
}
```

After (4.x):

```php
$usage = $message->getUsage();

$promptTokens = $usage->inputTokens;
$totalTokens = $usage->getTotal();
```

Remove the `use` imports this leaves unused.

### Case 2: Pricing answers

1. Price the cached part apart. One formula now fits every provider. If the 3.x code had one branch for OpenAI and Gemini (which subtracted) and another for Anthropic and Bedrock (which did not), merge them into this one.

   Before (3.x, Anthropic, AnthropicVertex and BedrockRuntime):

   ```php
   $cost = $usage->inputTokens * $inputRate
       + $usage->cachedInputTokens * $cacheReadRate;
   ```

   After (4.x, every provider):

   ```php
   $cost = ($usage->inputTokens - $usage->cachedInputTokens) * $inputRate
       + $usage->cachedInputTokens * $cacheReadRate;
   ```

2. If the app prices Anthropic cache writes at their own rate, subtract them from the input part as well.

   Before (3.x):

   ```php
   $cacheWrite = (int) $message->getMetadata('cacheWriteTokens');
   $cost = $usage->inputTokens * $inputRate
       + $usage->cachedInputTokens * $cacheReadRate
       + $cacheWrite * $cacheWriteRate;
   ```

   After (4.x, Anthropic and AnthropicVertex):

   ```php
   $cacheWrite = (int) $message->getMetadata('cacheWriteTokens');
   $cost = ($usage->inputTokens - $usage->cachedInputTokens - $cacheWrite) * $inputRate
       + $usage->cachedInputTokens * $cacheReadRate
       + $cacheWrite * $cacheWriteRate;
   ```

   `BedrockRuntime` exposes no separate write count, so its written tokens cannot be split out. They stay in the input-rate part. In 3.x they were not counted at all.

3. Check where the priced `Usage` comes from. The formula holds for an answer as the provider returned it, and that is what an observer of the inference-stop event receives (guide 46 migrates observers and that event). It does not hold for the message a run returns, or for messages read from the chat history. When the history trims, or `Summarization` runs, `inputTokens` is lowered on the messages it keeps but `cachedInputTokens` is not, so the result can drop below zero. If the app prices those messages, apply steps 1 and 2 anyway, then ask the developer whether to move the pricing into an inference-stop observer. Pricing from stored rows is Case 4.

### Case 3: Budgets, quotas and limits tuned on 3.x values

For these providers with prompt caching, every token count below now includes the tokens read from and written to the cache, so it reaches a given number sooner than in 3.x:
- budgets, quotas and alerts compared against `getTotal()`, `calculateTotalUsage()` or `inputTokens`
- the agent's context window: the `contextWindow()` hook, `setContextWindow()`, or the third argument of `new ChatHistory(...)`
- the `Summarization` threshold: its `maxTokens` constructor argument, or `setMaxTokens()`

1. Remove any compensation that added the cache back (Case 1).
2. List every hard-coded value you found, with its file and line. Ask the developer whether each one was tuned on 3.x values, for example a window lowered below the model's limit so that trimming or summarizing started at all. Under 4.x such a value now trims, summarizes or alerts too early.
3. Change a value only to the number the developer gives. Otherwise leave it as it is and list it in your report.

### Case 4: Size or cost computed from stored messages

This covers queries over the chat messages table (`meta` → `$.usage.input_tokens`), code that reads its rows or the `.chat` files, and exports. Messages stored by 3.x from Anthropic, AnthropicVertex or BedrockRuntime keep the 3.x meaning: their `input_tokens` excludes the cache, so the Case 2 formula must not be applied to them. Keep the 3.x formula for those rows and apply the 4.x one to rows written after the upgrade. A message stored by 3.x has no `__meta` key, and every message 4.x writes has one (guide 34). Do not rewrite stored values.

Before (3.x, MySQL / MariaDB):

```sql
SELECT SUM(JSON_EXTRACT(meta, '$.usage.input_tokens')) AS input_tokens,
       SUM(COALESCE(JSON_EXTRACT(meta, '$.usage.cached_input_tokens'), 0)) AS cached_input_tokens
FROM chat_messages;
```

After (4.x). `uncached_input_tokens` is what the report prices at the input rate:

```sql
SELECT SUM(CASE WHEN JSON_EXTRACT(meta, '$.__meta') IS NULL
                THEN JSON_EXTRACT(meta, '$.usage.input_tokens')
                ELSE JSON_EXTRACT(meta, '$.usage.input_tokens') - COALESCE(JSON_EXTRACT(meta, '$.usage.cached_input_tokens'), 0)
           END) AS uncached_input_tokens,
       SUM(COALESCE(JSON_EXTRACT(meta, '$.usage.cached_input_tokens'), 0)) AS cached_input_tokens
FROM chat_messages;
```

SQLite accepts the same query. On PostgreSQL, test `meta::jsonb -> '__meta' IS NULL` and read `(meta::jsonb #>> '{usage,input_tokens}')::int`.

PHP code over decoded rows. Before (3.x):

```php
$usage = $record->meta['usage'];
$cost = $usage['input_tokens'] * $inputRate + ($usage['cached_input_tokens'] ?? 0) * $cacheReadRate;
```

After (4.x):

```php
$meta = $record->meta;
$usage = $meta['usage'];
$cached = $usage['cached_input_tokens'] ?? 0;

// Rows stored by 3.x have no __meta key: their input_tokens excludes the cache
$uncached = array_key_exists('__meta', $meta) ? $usage['input_tokens'] - $cached : $usage['input_tokens'];
$cost = $uncached * $inputRate + $cached * $cacheReadRate;
```

- If the report also prices Anthropic cache writes, subtract `$.__meta.cacheWriteTokens` from the input part of 4.x rows, as in Case 2 step 2. The input tokens of 3.x rows never included the writes, and their `cacheWriteTokens` may be doubled (Case 5).
- If the same table also holds answers from other providers, their 3.x rows already included the cache, and stored rows do not record the provider. Ask the developer how the report tells them apart.
- If the data has no serialized message to test (aggregates, exports without `meta`), split it by the time the application was upgraded.
- Code that computes sizes or costs from `Message` objects loaded through the chat history cannot tell 3.x messages apart. Report it to the developer, and propose an inference-stop observer (Case 2, step 3) or a query over the raw rows as above.
- Stored `input_tokens` may already be lowered by trimming or summarizing (Case 2, step 3), so a report over stored rows is an estimate. If the app bills customers from it, tell the developer.

### Case 5: Totals of `cacheWriteTokens` over stored messages

This applies to Anthropic and AnthropicVertex only. Bedrock never stored this metadata. Find the code and queries that sum `cacheWriteTokens` across stored messages. After guide 34 they read `$.__meta.cacheWriteTokens` for rows written by 4.x and `$.cacheWriteTokens` for rows written by 3.x. Values stored by 3.x are doubled whenever the response carried the per-TTL breakdown, as current Anthropic responses do. Ask the developer whether to halve the 3.x values in the report, or to report pre-upgrade and post-upgrade totals separately. Never update stored rows.

Before (as guide 34 left it, MySQL / MariaDB):

```sql
SELECT SUM(JSON_UNQUOTE(COALESCE(JSON_EXTRACT(meta, '$.__meta.cacheWriteTokens'), JSON_EXTRACT(meta, '$.cacheWriteTokens')))) AS cache_write_tokens
FROM chat_messages;
```

After (4.x, halving the 3.x values):

```sql
SELECT SUM(CASE WHEN JSON_EXTRACT(meta, '$.__meta') IS NULL
                THEN JSON_UNQUOTE(JSON_EXTRACT(meta, '$.cacheWriteTokens')) / 2
                ELSE JSON_UNQUOTE(JSON_EXTRACT(meta, '$.__meta.cacheWriteTokens'))
           END) AS cache_write_tokens
FROM chat_messages;
```

On SQLite, drop `JSON_UNQUOTE`. PHP code applies the same test: `array_key_exists('__meta', $meta) ? (int) ($meta['__meta']['cacheWriteTokens'] ?? 0) : intdiv((int) ($meta['cacheWriteTokens'] ?? 0), 2)`.

## Checklist

- Readers of usage the app copied into its own tables, logs or metrics were found by their column names and handled like Cases 1-4, with rows written before the upgrade split by the upgrade time.
- No code or client adds `cachedInputTokens`, `cached_input_tokens` or `cacheWriteTokens` to `inputTokens` or `getTotal()`, and no provider branch remains that existed only for that.
- Cost code prices `inputTokens - cachedInputTokens` at the input rate for every provider. Anthropic code with a cache-write rate also subtracts `cacheWriteTokens`.
- Cost code that reads the message a run returns, or messages from the chat history, has been reported to the developer.
- Hard-coded budgets, quotas, context windows and `Summarization` thresholds for these providers were listed, and changed only as the developer answered.
- Queries and code over stored messages apply the 3.x formula to rows without `__meta` and the 4.x formula to the others. `cacheWriteTokens` totals halve or split the 3.x values, as the developer chose.
- No stored row, `.chat` file or migration was written for this guide.
