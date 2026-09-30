# Upgrade: MCP stdio servers inherit only basic environment variables

## Summary

In 3.x a stdio MCP server (a configuration with `command`) received the whole environment of the PHP process
(`getenv()`) plus the configured `env`. In 4.x it receives only the variables listed below, when the application has
them set, plus the configured `env`, which still wins on a name clash.

- **Inherited, on every OS:** `HOME`, `LOGNAME`, `PATH`, `SHELL`, `TERM`, `USER`, `APPDATA`, `HOMEDRIVE`, `HOMEPATH`,
  `LOCALAPPDATA`, `PROCESSOR_ARCHITECTURE`, `PROGRAMFILES`, `SYSTEMDRIVE`, `SYSTEMROOT`, `TEMP`, `USERNAME`,
  `USERPROFILE`.
- **Everything else goes through `env`:** tokens and API keys; cloud credentials (`AWS_*`,
  `GOOGLE_APPLICATION_CREDENTIALS`); `HTTP_PROXY`, `HTTPS_PROXY`, `NO_PROXY` and their lowercase forms; custom CA
  certificates (`NODE_EXTRA_CA_CERTS`, `SSL_CERT_FILE`); `LANG` and `LC_*`; `TMPDIR`; `NODE_OPTIONS`; settings the
  launcher reads (`npm_config_*`/`NPM_CONFIG_*` for `npx`, `UV_*` for `uvx`, `DOCKER_HOST` for `docker`).

| 3.x | 4.x |
|---|---|
| A server reads `GITHUB_PERSONAL_ACCESS_TOKEN` from the application's environment | `'env' => ['GITHUB_PERSONAL_ACCESS_TOKEN' => getenv('GITHUB_PERSONAL_ACCESS_TOKEN')]` |
| `npx` downloads through the proxy set in `HTTPS_PROXY` | `'env' => ['HTTPS_PROXY' => getenv('HTTPS_PROXY')]` |
| Every server receives every variable | `'env' => getenv()` for one server, only when the developer decides it |

`env` keeps its 3.x shape (guide 47 already migrated `command`). `url` servers (HTTP/SSE) are not affected.

Stored data: no stored format changes. An `McpConnector` serialized under 3.x unserializes with the configuration it
had then, so build connectors from the updated configuration instead of reusing stored copies.

## What to Search For

```bash
grep -rnE 'McpConnector|McpClient|StdioTransport' --include='*.php' --exclude-dir=vendor .
grep -rnE "[\"']command[\"'][[:space:]]*=>" --include='*.php' --exclude-dir=vendor .
```

- Follow each configuration array to where it is built: a variable, a method, `McpConnector::make(config('mcp.github'))`
  → `config/mcp.php`, a Symfony parameter, or a JSON/YAML file the application decodes. Check that non-PHP
  configuration too.
- Only configurations with a `command` key are affected, whether they reach `McpConnector`, `McpClient` or a
  `StdioTransport` passed as `transport`.
- For each one, note whether it already has `env` (Case 2) and whether `command` is a wrapper script (guide 47). A
  wrapper script runs with the same reduced environment, so every variable the script or the server it starts reads
  must go through `env` too.
- If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: A server reads variables from the application's environment

1. Collect the candidate variables:
   - Names in `args` or in a wrapper script: `docker run -e NAME` or `--env NAME` without `=value` copies `NAME` from
     docker's own environment; `$NAME` in a script.
   - The application's environment sources: `.env`, `.env.example`, `docker-compose*.yml`, `Dockerfile`, deployment
     manifests and CI config. Look for names tied to the server (`GITHUB_*`, `SLACK_*`, ...), `*_API_KEY`, `*_TOKEN`,
     `*_SECRET`, and the settings listed in the Summary.
   - The server's README when it is available (e.g. `node_modules/<package>/README.md`). For a server that is the
     application's own code, its source: `getenv(`, `env(`, `$_ENV`, `$_SERVER`. A server that boots the application's
     framework (an `artisan` or `bin/console` command) reads every variable the framework configuration reads,
     including those the deployment sets outside `.env`.
2. Keep the variables that the README or the code names, or whose name ties them to that server without doubt. Anything
   still uncertain goes to Case 5.
3. Add each one to the configuration's `env`, read with `getenv('NAME')`.

`getenv('NAME')` reproduces 3.x exactly: the server gets the value the PHP process has. When the variable is unset,
`getenv()` returns `false`, which `proc_open()` leaves out, so the server sees it as absent, as in 3.x. No guard is
needed. If the application already keeps the value in its own configuration (e.g. `config('services.github.token')`),
reading it from there is also correct.

Before (3.x):

```php
use NeuronAI\MCP\McpConnector;

McpConnector::make([
    'command' => 'npx',
    'args' => ['-y', '@modelcontextprotocol/server-github'],
]);
```

After (4.x):

```php
use NeuronAI\MCP\McpConnector;

McpConnector::make([
    'command' => 'npx',
    'args' => ['-y', '@modelcontextprotocol/server-github'],
    'env' => ['GITHUB_PERSONAL_ACCESS_TOKEN' => getenv('GITHUB_PERSONAL_ACCESS_TOKEN')],
]);
```

### Case 2: The configuration already has `env`

Add the new keys to the existing array, including one that guide 47 created, and keep every entry already there with
its value. Do not replace the array or add a second `env` key.

Before (3.x):

```php
McpConnector::make([
    'command' => 'docker',
    'args' => ['run', '-i', '--rm', '-e', 'GITHUB_PERSONAL_ACCESS_TOKEN', '-e', 'GITHUB_TOOLSETS', 'ghcr.io/github/github-mcp-server'],
    'env' => ['GITHUB_TOOLSETS' => 'repos,issues'],
]);
```

After (4.x):

```php
McpConnector::make([
    'command' => 'docker',
    'args' => ['run', '-i', '--rm', '-e', 'GITHUB_PERSONAL_ACCESS_TOKEN', '-e', 'GITHUB_TOOLSETS', 'ghcr.io/github/github-mcp-server'],
    'env' => [
        'GITHUB_TOOLSETS' => 'repos,issues',
        'GITHUB_PERSONAL_ACCESS_TOKEN' => getenv('GITHUB_PERSONAL_ACCESS_TOKEN'),
    ],
]);
```

### Case 3: Proxy, locale, temporary directory or launcher settings

A server that downloads packages behind a proxy (`npx`, `uvx`, `docker`), or that depends on the locale, a temporary
directory or launcher settings, receives them the same way. Pass the ones the application's environment sources set. Hosts often set these outside the repository (systemd unit,
container runtime, `/etc/environment`, PHP-FPM pool), so ask the developer: "Do the production, staging or CI hosts
set a proxy (`HTTP_PROXY`, `HTTPS_PROXY`, `NO_PROXY`), a CA bundle (`NODE_EXTRA_CA_CERTS`, `SSL_CERT_FILE`) or a locale
(`LANG`, `LC_*`) for the application?" Add each name they confirm with `getenv('NAME')`. A name that is unset on some
hosts is dropped there, so this is safe.

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
    'command' => 'npx',
    'args' => ['-y', '@modelcontextprotocol/server-everything'],
    'env' => [
        'HTTPS_PROXY' => getenv('HTTPS_PROXY'),
        'NO_PROXY' => getenv('NO_PROXY'),
        'LANG' => getenv('LANG'),
    ],
]);
```

### Case 4: The developer wants one server to receive the whole environment

Passing `getenv()` as a whole hands the server every variable again, secrets included. Never choose it on the
developer's behalf: report the server and apply this only when the developer decides it. Write it so the configured
values still win, as in 3.x: `'env' => getenv()` when there is no `env`, or the spread form below when there is one.

Before (3.x):

```php
McpConnector::make([
    'command' => 'php',
    'args' => [__DIR__ . '/../bin/mcp-server.php'],
    'env' => ['MCP_LOG_LEVEL' => 'debug'],
]);
```

After (4.x), only when the developer chose it:

```php
McpConnector::make([
    'command' => 'php',
    'args' => [__DIR__ . '/../bin/mcp-server.php'],
    'env' => [...getenv(), 'MCP_LOG_LEVEL' => 'debug'],
]);
```

### Case 5: The variables a server reads cannot be determined

Do not fall back to `getenv()`. List the server in the step report with the candidates found, and ask the developer:
"Which environment variables does the MCP server started with `<command> <args>` read (tokens, API keys, proxy,
locale)? Or should it receive the whole environment, as in 3.x?" Apply Case 1 or Case 4 with the answer.

## Checklist

- Every configuration with `command` has in `env` each variable its server, and any wrapper script, reads, taken
  with `getenv('NAME')` or from the application's configuration.
- The `env` entries a configuration already had are still present, with their values.
- No configuration passes `getenv()` as a whole unless the developer chose it. Every server whose variables could not
  be determined is listed in the step report.
- Each server starts and answers one tool call, through the application's tests or a connector's `tools()` followed
  by one call. A missing credential shows up as a startup failure or an authentication error on the first call. When
  the local environment lacks the credentials, list the server for the developer to check.
- Re-running the search patterns shows no stdio configuration left unreviewed.
