# Upgrade: MCP stdio servers inherit only basic environment variables

## Summary

In 3.x `StdioTransport` started every stdio MCP server with the whole environment of the PHP process, plus the
configured `env`. Provider API keys, database passwords, cloud credentials and, with Laravel, the whole `.env` reached
the server, often a third-party `npx` or `uvx` package, and many servers read variables such as `OPENAI_API_KEY` or
`GITHUB_TOKEN` on their own. A server now inherits only the variables the official MCP SDKs pass on, with the
configured `env` on top.

- **Inherited on Linux and macOS:** `HOME`, `LOGNAME`, `PATH`, `SHELL`, `TERM`, `USER`.
- **Inherited on Windows:** `APPDATA`, `HOMEDRIVE`, `HOMEPATH`, `LOCALAPPDATA`, `PATH`, `PROCESSOR_ARCHITECTURE`,
  `PROGRAMFILES`, `SYSTEMDRIVE`, `SYSTEMROOT`, `TEMP`, `USERNAME`, `USERPROFILE`.
- **Everything else goes through `env`:** the tokens and API keys a server reads, `HTTP_PROXY`, `HTTPS_PROXY` and
  `NO_PROXY` for `npx` or `uvx` downloading packages behind a proxy, `LANG` and `LC_*`, `TMPDIR`, `NODE_OPTIONS`.

| Before (3.x) | After |
|---|---|
| A server reads `GITHUB_TOKEN` from the application's environment | `'env' => ['GITHUB_TOKEN' => getenv('GITHUB_TOKEN')]` |
| `npx` downloads through the proxy set in `HTTPS_PROXY` | `'env' => ['HTTPS_PROXY' => getenv('HTTPS_PROXY')]` |
| Every server receives every variable | `'env' => getenv()` passes them all to one server, deliberately |

## How to Refactor

### Case 1: A server that reads a variable from the environment

Each server's documentation lists the variables it reads: API keys, tokens, endpoints. Pass each one explicitly.

Before:

```php
McpConnector::make([
    'command' => 'npx',
    'args' => ['-y', '@modelcontextprotocol/server-github'],
]);
```

After:

```php
McpConnector::make([
    'command' => 'npx',
    'args' => ['-y', '@modelcontextprotocol/server-github'],
    'env' => ['GITHUB_PERSONAL_ACCESS_TOKEN' => getenv('GITHUB_PERSONAL_ACCESS_TOKEN')],
]);
```

A server started without a variable it needs usually fails at startup or on its first tool call with an
authentication error.

### Case 2: Proxy, locale or temporary directory settings

A server that downloads packages behind a proxy, or that depends on the locale or on a temporary directory, receives
those variables the same way:

```php
'env' => [
    'HTTPS_PROXY' => getenv('HTTPS_PROXY'),
    'NO_PROXY' => getenv('NO_PROXY'),
],
```

### Case 3: The whole environment for one trusted server

`'env' => getenv()` hands the server every variable again, secrets included. Use it only when the developer decides
that server may see them all, and report it to them rather than choosing it on their behalf.

## What to Search For

```
grep -rn "McpConnector::make\|new McpClient\|StdioTransport" --include="*.php" .
```

For each configuration with a `command`, check which environment variables that server reads.

## Checklist

- Every stdio MCP server receives through `env` each variable it reads: tokens, API keys, proxy settings.
- No server receives `getenv()` as a whole unless the developer chose it.
