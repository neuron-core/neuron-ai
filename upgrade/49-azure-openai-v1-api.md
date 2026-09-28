# Upgrade: `AzureOpenAI` calls Azure's v1 API

## Summary

In 3.x `AzureOpenAI` called the deployment API, `https://{resource}/openai/deployments/{deployment}/chat/completions`,
with an `api-version` passed to the constructor, and sent the key as `Authorization: Bearer`. It now calls the v1 API
that Microsoft recommends, `https://{resource}/openai/v1/chat/completions`, which takes the deployment name in the
request body and needs no `api-version`.

- **The `version` constructor parameter is removed.** The endpoint and the deployment name (`model`) are unchanged.
- **The key is sent in the `api-key` header,** as Microsoft documents for resource keys. `Authorization: Bearer` is
  only for Microsoft Entra ID access tokens, which the provider doesn't support.

| Before (3.x) | After |
|---|---|
| `new AzureOpenAI(key: ..., endpoint: ..., model: ..., version: '2024-10-21')` | `new AzureOpenAI(key: ..., endpoint: ..., model: ...)` |
| `POST /openai/deployments/{deployment}/chat/completions?api-version=...` | `POST /openai/v1/chat/completions` with the deployment in `model` |
| `Authorization: Bearer {key}` | `api-key: {key}` |

## How to Refactor

### Case 1: The `version` argument

Before:

```php
new AzureOpenAI(
    key: $_ENV['AZURE_OPENAI_KEY'],
    endpoint: $_ENV['AZURE_OPENAI_ENDPOINT'],
    model: $_ENV['AZURE_OPENAI_DEPLOYMENT'],
    version: $_ENV['AZURE_OPENAI_VERSION'],
);
```

After:

```php
new AzureOpenAI(
    key: $_ENV['AZURE_OPENAI_KEY'],
    endpoint: $_ENV['AZURE_OPENAI_ENDPOINT'],
    model: $_ENV['AZURE_OPENAI_DEPLOYMENT'],
);
```

A positional call drops its fourth argument: `strict_response`, `parameters` and `httpClient` move one position
earlier. A configuration value that only held the API version is no longer read.

### Case 2: A Microsoft Entra ID token passed as the key

The key now travels in the `api-key` header, which Azure accepts only for resource keys. If the application passes an
Entra ID access token as `key`, its requests are rejected. Report it to the developer: switching to a resource key is
their decision.

## What to Search For

```
grep -rn "AzureOpenAI" --include="*.php" .
```

## Checklist

- No `AzureOpenAI` construction passes `version`, by name or as the fourth positional argument.
- The key passed to `AzureOpenAI` is a resource key, or the developer knows an Entra ID token no longer works.
