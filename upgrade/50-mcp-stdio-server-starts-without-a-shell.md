# Upgrade: MCP stdio servers start without a shell

## Summary

In 3.x `StdioTransport` joined `command` and the escaped `args` into one line and ran it through `/bin/sh`. A server
path containing a space failed to start, shell syntax in `command` was executed, and ending the session stopped the
shell rather than the server, so a server that keeps running after its input closes was left behind. The server now
starts directly from `command` and `args`, with no shell in between.

- **`command` is exactly one program.** Its arguments go in `args`. A `command` that bundles arguments fails to start
  with `McpException: Failed to start the MCP server "npx -y server": ... No such file or directory`.
- **Shell syntax is taken literally.** `~`, `$VAR`, `VAR=value` prefixes, `cd ... &&`, pipes and redirections in
  `command` or `args` are no longer interpreted. Environment variables for the server go in `env`.
- **On Windows, npm shims need their extension.** `npx` and `npm` are `.cmd` scripts that only `cmd.exe` resolves, so
  write `npx.cmd`. Programs installed as `.exe` files, such as `node`, `php` or `uvx`, are unaffected.
- **Ending the session stops the server itself.** No change is needed for this.

| Before (3.x) | After |
|---|---|
| `'command' => 'npx -y server'` | `'command' => 'npx', 'args' => ['-y', 'server']` |
| `'command' => 'API_KEY=secret node server.js'` | `'command' => 'node', 'args' => ['server.js'], 'env' => ['API_KEY' => 'secret']` |
| `'command' => '~/bin/server'` | `'command' => getenv('HOME') . '/bin/server'` |
| `'command' => 'npx'` on Windows | `'command' => 'npx.cmd'` |
| A server path containing a space fails to start | Starts |

## How to Refactor

### Case 1: A command that bundles its arguments

Before:

```php
McpConnector::make([
    'command' => 'npx -y @modelcontextprotocol/server-filesystem /data',
]);
```

After:

```php
McpConnector::make([
    'command' => 'npx',
    'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/data'],
]);
```

Split on the spaces the shell used to split on. An argument that was quoted to keep its spaces becomes one element
of `args`, without the quotes.

### Case 2: A command that relies on the shell

Move environment variables to `env` and write paths out in full, as in the table above. When the server really needs
a shell, for example to change directory first or to pipe its output, put the shell line in an executable script the
application owns and point `command` at it. End the script with `exec`, so that stopping the session still stops the
server:

```sh
#!/bin/sh
cd /srv/mcp && exec node server.js
```

```php
McpConnector::make([
    'command' => __DIR__ . '/../bin/mcp-server.sh',
]);
```

### Case 3: npm shims on Windows

On Windows, add the extension to `npx` and `npm`:

```php
McpConnector::make([
    'command' => PHP_OS_FAMILY === 'Windows' ? 'npx.cmd' : 'npx',
    'args' => ['-y', '@modelcontextprotocol/server-everything'],
]);
```

An application that only runs on Linux or macOS needs no change.

## What to Search For

```
grep -rn "McpConnector::make\|new McpClient\|StdioTransport" --include="*.php" .
grep -rn "'command'\s*=>" --include="*.php" .
```

Also check configuration files and environment variables that hold MCP server commands.

## Checklist

- Every stdio MCP `command` names a single program, and its arguments are in `args`.
- No `command` or `args` value relies on shell syntax: `~`, `$VAR`, `VAR=value`, `&&`, pipes or redirections.
- A script used to keep shell behavior ends with `exec`.
- On Windows, npm shims are written with their `.cmd` extension.
