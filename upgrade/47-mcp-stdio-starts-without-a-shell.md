# Upgrade: MCP stdio servers start without a shell

## Summary

A stdio MCP server is any config with a `command` key, passed to `McpConnector`, `McpClient` or `StdioTransport`.

- 3.x joined `command` with the escaped `args` and ran the line through `/bin/sh -c` (`cmd.exe /c` on Windows).
- 4.x starts `command` as the program and passes `args` as its arguments, with no shell.

Only `command` changes meaning. It must be exactly one program path, and shell syntax in it is taken literally: spaces
between arguments, `~`, `$VAR`, `VAR=value` prefixes, quotes, backslash escapes, `cd ... &&`, `;`, pipes and
redirections. 3.x already escaped every `args` element, so shell syntax in `args` was never interpreted and needs no
change.

| 3.x | 4.x |
|---|---|
| `'command' => 'npx -y server'` | `'command' => 'npx', 'args' => ['-y', 'server']` |
| `'command' => '"/opt/My Server/srv"'` or `escapeshellarg($path)` | `'command' => '/opt/My Server/srv'` or `$path` |
| `'command' => 'API_KEY=secret node server.js'` | `'command' => 'node', 'args' => ['server.js'], 'env' => ['API_KEY' => 'secret']` |
| `'command' => '~/bin/server'` | `'command' => '/home/deploy/bin/server'` |
| `'command' => 'cd /srv/mcp && node server.js'` | a wrapper script, or `'command' => '/bin/sh', 'args' => ['-c', 'cd /srv/mcp && exec node server.js']` |
| `'command' => 'npx'` on Windows | `'command' => 'npx.cmd'` |

A leftover shell-style `command` throws `NeuronAI\MCP\McpException` when the server starts: on the first `->tools()`
call of an `McpConnector`, or in `new McpClient(...)`. The message depends on the PHP version:

- `Failed to start the MCP server "<command>": ...`, for example `proc_open(): posix_spawn() failed: No such file or
  directory`
- `Process failed to start: ...`
- `MCP server process is not running`
- `MCP server process has terminated unexpectedly.`

Stored data: nothing is stored by the transport. `McpConnector` objects the application serialized itself (cache,
session) are unserialized by 4.x with their 3.x config, so a stored shell-style `command` fails the same way: clear
those entries after deploying.

Guide 48 narrows the environment variables a stdio server inherits. Leave `env` contents to it, apart from Case 3.

## What to Search For

```bash
grep -rnE 'McpConnector|McpClient|StdioTransport' --include='*.php' --exclude-dir=vendor .
grep -rnE "['\"]command['\"]]?[[:space:]]*=" --include='*.php' --exclude-dir=vendor .
grep -rnE 'escapeshellarg|escapeshellcmd' --include='*.php' --exclude-dir=vendor .
```

- Follow every config array to the call that receives it: `McpConnector::make(...)`, `new McpConnector(...)`, a
  `McpConnector` subclass constructor, `new McpClient(...)` or `new StdioTransport(...)` (also when passed as
  `'transport' => new StdioTransport([...])`). Drop `command` hits that never reach these classes.
- Follow each `command` value back to its source. When it comes from `env()`, `getenv()`, `$_ENV`, a JSON or YAML
  file or a database row, check that source too (Case 7).

If no stdio config is found, this guide does not apply.

## How to Refactor

Leave alone:

- A `command` that is already a single program path (`'npx'`, `'node'`, `PHP_BINARY`, `'/usr/bin/uvx'`). On Linux and
  macOS it needs no change, except a bare name started from PHP-FPM (Case 8).
- `args` elements containing `$`, `~`, `*`, spaces or quotes. 3.x escaped them, so they were never interpreted.
- `escapeshellarg()` applied to an `args` element. The server received the quotes in 3.x and still does: leave it and
  mention it to the developer.
- Configs with `url`, or with a `transport` object that is not a `StdioTransport`.

### Case 1: A `command` that bundles its arguments

Before (3.x):

```php
use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpConnector;

McpConnector::make([
    'command' => 'npx -y @modelcontextprotocol/server-filesystem',
    'args' => ['/data'],
]);

new McpClient([
    'command' => 'node ' . escapeshellarg($serverScript) . ' --name "Docs server"',
]);
```

After (4.x):

```php
use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpConnector;

McpConnector::make([
    'command' => 'npx',
    'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/data'],
]);

new McpClient([
    'command' => 'node',
    'args' => [$serverScript, '--name', 'Docs server'],
]);
```

1. Keep the program in `command`.
2. Split the rest where the shell split it, on unquoted whitespace.
3. A quoted, backslash-escaped or `escapeshellarg()`-wrapped argument becomes one `args` element, without its quotes,
   backslashes or `escapeshellarg()` call.
4. Put the split-off arguments in front of any existing `args` elements.

### Case 2: A quoted or escaped program path

Before (3.x):

```php
McpConnector::make(['command' => '"/opt/My Server/srv"']);
McpConnector::make(['command' => '/opt/My\ Server/srv']);
McpConnector::make(['command' => escapeshellarg($serverPath)]);
```

After (4.x):

```php
McpConnector::make(['command' => '/opt/My Server/srv']);
McpConnector::make(['command' => '/opt/My Server/srv']);
McpConnector::make(['command' => $serverPath]);
```

Remove the quotes, backslashes and `escapeshellarg()`/`escapeshellcmd()` calls from `command` only (see "Leave alone"
for `args`).

### Case 3: An environment assignment in front of the program

Before (3.x):

```php
McpConnector::make([
    'command' => 'API_KEY=secret node server.js',
    'env' => ['DEBUG' => 'true'],
]);
```

After (4.x):

```php
McpConnector::make([
    'command' => 'node',
    'args' => ['server.js'],
    'env' => ['DEBUG' => 'true', 'API_KEY' => 'secret'],
]);
```

Move each `VAR=value` prefix into `env`, keeping the entries already there. On a name clash the prefix value wins, as
it did in 3.x. A `$VAR` inside a prefix value is resolved in PHP, as in Case 4.

### Case 4: `~` or `$VAR` in the program path

Before (3.x):

```php
McpConnector::make(['command' => '~/bin/mcp-server']);
McpConnector::make(['command' => '$MCP_HOME/bin/mcp-server']);
```

After (4.x):

```php
McpConnector::make(['command' => '/home/deploy/bin/mcp-server']);
McpConnector::make(['command' => '/opt/mcp/bin/mcp-server']);
```

- Write the resolved absolute path, or build it with the application's own path helper or config.
- When the path differs between machines (developer laptops, CI, production), ask the developer which config value or
  environment variable should hold it.
- Use `getenv('HOME') . '/bin/mcp-server'` only when the variable is set in every environment the app runs in, such as
  a CLI-only worker. Under PHP-FPM, `getenv()` often returns `false` and the path silently becomes `/bin/mcp-server`.

### Case 5: Shell logic: `cd`, `&&`, `;`, pipes, redirections

Before (3.x):

```php
McpConnector::make([
    'command' => 'cd /srv/mcp && node server.js 2>/dev/null',
]);
```

After (4.x), with an executable wrapper script the application owns (`chmod +x bin/mcp-server.sh`; on Windows, a
`.cmd` file):

```sh
#!/bin/sh
cd /srv/mcp && exec node server.js
```

```php
McpConnector::make([
    'command' => __DIR__ . '/../bin/mcp-server.sh',
]);
```

Or call the shell explicitly:

```php
McpConnector::make([
    'command' => '/bin/sh',
    'args' => ['-c', 'cd /srv/mcp && exec node server.js'],
]);
```

- Start the server with `exec`, so stopping the session stops the server and not only the shell.
- Drop a `2>/dev/null` redirect: 4.x reads and discards the server's stderr. Keep other redirections in the script.
- The script runs with the reduced environment described in guide 48.

### Case 6: npm shims on Windows

Before (3.x):

```php
McpConnector::make([
    'command' => 'npx',
    'args' => ['-y', '@modelcontextprotocol/server-everything'],
]);
```

After (4.x):

```php
McpConnector::make([
    'command' => PHP_OS_FAMILY === 'Windows' ? 'npx.cmd' : 'npx',
    'args' => ['-y', '@modelcontextprotocol/server-everything'],
]);
```

`npx`, `npm` and CLIs installed with npm (`pnpm`, `yarn`, ...) are `.cmd` shims that only `cmd.exe` resolves without
the extension. `.exe` programs (`node`, `php`, `python`, `uvx`) are unaffected. Apply this when the app runs on Windows,
developer machines included; ask the developer if unsure. An app that runs only on Linux or macOS skips this case.

### Case 7: A `command` read from the environment or a config file

When a shell-style value lives outside PHP code (`.env`, JSON, YAML, a database row):

1. Ask the developer where the arguments should live: in the PHP config next to `command`, or in a second setting read
   into `args`. Do not split the value at runtime with `explode()`: it breaks quoted arguments and paths with spaces.
2. Apply Cases 1-6 to the committed files (`.env.example`, config files).
3. Report to the developer that deployed values (production `.env`, secrets managers, database rows) need the same
   change before 4.x is deployed.

### Case 8: A bare program name started from a PHP-FPM request

A `command` without a `/` is looked up in the PATH of the PHP process. PHP-FPM clears its workers' environment by default
(`clear_env`), so a web request has no PATH. 3.x's `/bin/sh` then searched its built-in path, which includes
`/usr/local/bin`. 4.x searches only `/bin` and `/usr/bin`, so a program installed elsewhere fails to start, or another
copy in `/usr/bin` runs.

1. Find the stdio servers that start during web requests (controllers and other code that is not a console command or
   queue worker). If unsure, ask the developer.
2. For each bare `command` among them, ask the developer where the program is installed on the web servers. If it is not
   in `/bin` or `/usr/bin`, write the absolute path, or read it from a config value the developer names.
3. Do not add `PATH` to `env` for this: whether it affects the lookup depends on the PHP version. Report to the developer
   that setting `env[PATH]` in the FPM pool is the infrastructure alternative.

## Checklist

- Every stdio `command` is one program path, and its arguments are in `args`, ahead of the elements that were already
  there.
- No `command` value relies on shell syntax: `~`, `$VAR`, `VAR=value`, quotes, backslash escapes, `&&`, `;`, pipes or
  redirections.
- No `escapeshellarg()`/`escapeshellcmd()` call wraps a `command` value or an argument split out of it.
- Former `VAR=value` prefixes are entries in `env`.
- Every wrapper script is executable and starts the server with `exec`.
- On Windows, npm shims are written with their `.cmd` extension.
- Deployed configuration that holds MCP commands was reported to the developer.
- Each stdio server starts: where the server is available locally, call `->tools()` once, in a test or console
  command, and no `McpException` is thrown.
- Every bare `command` started from a PHP-FPM request resolves in `/bin` or `/usr/bin`, or was replaced by an absolute
  path. The `->tools()` check from a console command does not cover this, because the CLI has PATH.
