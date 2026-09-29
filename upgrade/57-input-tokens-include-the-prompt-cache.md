# Upgrade: Input tokens include the prompt cache

## Summary

In 3.x `Usage::$inputTokens` meant a different thing depending on the provider. OpenAI and Gemini report the whole
prompt, the part served from the prompt cache included. Anthropic (and `AnthropicVertex`) and Amazon Bedrock report
only the tokens the cache neither read nor wrote, so with prompt caching their `inputTokens` left out the cached
instructions and tools, or most of the conversation. The chat history measures its size from that number, so it kept a
context larger than the window, and the `Summarization` middleware started late or never.

- **`inputTokens` is the whole prompt for every provider.** Anthropic's usage adds `cache_read_input_tokens` and
  `cache_creation_input_tokens` to `input_tokens`; Bedrock's adds `cacheReadInputTokens` and `cacheWriteInputTokens` to
  `inputTokens`.
- **`cachedInputTokens` is the part of `inputTokens` read from the cache**, for every provider. Its value is unchanged.
- **Anthropic's `cacheWriteTokens` metadata counts each written token once.** In 3.x it added
  `cache_creation_input_tokens` to its per-TTL breakdown (`ephemeral_5m_input_tokens`, `ephemeral_1h_input_tokens`),
  which counts the same tokens, so the value doubled.
- **Stored messages keep the values they were stored with.** The history takes its size from the latest answer, so it is
  measured correctly from the first answer after the upgrade.

| Before (3.x) | After |
|---|---|
| Anthropic: `input_tokens` 100, `cache_creation_input_tokens` 50, `cache_read_input_tokens` 30 give `inputTokens` 100 | `inputTokens` 180, `cachedInputTokens` 30 |
| Bedrock: `inputTokens` 20, `cacheReadInputTokens` 15, `cacheWriteInputTokens` 5 give `inputTokens` 20 | `inputTokens` 40, `cachedInputTokens` 15 |
| Anthropic `cacheWriteTokens` for 50 written tokens reported with their TTL breakdown: 100 | 50 |

## How to Refactor

Nothing fails. Code reading the usage of Anthropic or Bedrock answers served partly from the prompt cache sees larger
`inputTokens`, and code that compensated for the difference now counts the cache twice.

### Case 1: Rebuilding the prompt size

Code that added the cached tokens back to get the prompt size must use `inputTokens` alone.

Before:

```php
$promptTokens = $usage->inputTokens + $usage->cachedInputTokens + (int) $message->getMetadata('cacheWriteTokens');
```

After:

```php
$promptTokens = $usage->inputTokens;
```

### Case 2: Pricing input tokens

A cost estimate that priced Anthropic or Bedrock `inputTokens` at the full input rate now includes the cached tokens.
Price the cached part apart, as OpenAI and Gemini already required:

Before:

```php
$cost = $usage->inputTokens * $inputRate + $usage->cachedInputTokens * $cacheReadRate;
```

After:

```php
$cost = ($usage->inputTokens - $usage->cachedInputTokens) * $inputRate + $usage->cachedInputTokens * $cacheReadRate;
```

The tokens written to the cache stay in the first part. To price them at the cache-write rate, subtract Anthropic's
`cacheWriteTokens` metadata as well.

### Case 3: Totals of `cacheWriteTokens`

Anthropic answers received after the upgrade report half the written tokens of comparable answers stored before, when
the API reported both forms, as it does today. Keep this in mind when comparing new totals with historical ones; the
stored values are not rewritten.

## What to Search For

```
grep -rnE "inputTokens|input_tokens" --include="*.php" .
grep -rnE "cachedInputTokens|cached_input_tokens|cache(Write|Read)Tokens" --include="*.php" .
```

A match matters when it reads the usage of an Anthropic, `AnthropicVertex` or Bedrock answer.

## Checklist

- No code adds `cachedInputTokens` or `cacheWriteTokens` to `inputTokens` to get the prompt size.
- Cost estimates price `inputTokens - cachedInputTokens` at the input rate, for every provider.
- Reports that compare `cacheWriteTokens` over time account for the halved values of new answers.
