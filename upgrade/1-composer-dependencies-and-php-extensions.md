# Upgrade: Neuron no longer installs Guzzle, PSR-7 or Inspector, and requires ext-curl

## Summary

Neuron's own requirements changed:

| | 3.x `require` | 4.x `require` |
|---|---|---|
| PHP | `^8.1` | `^8.1` (unchanged) |
| Extensions | none | `ext-curl` |
| Packages | `guzzlehttp/guzzle`, `psr/http-message`, `inspector-apm/inspector-php` | `psr/event-dispatcher` (installed automatically, no action) |

What this means for the application:

- Composer removes the packages the app got through Neuron 3.x, unless another dependency still
  requires them. These are `guzzlehttp/guzzle` and the packages Guzzle brought in:
  `guzzlehttp/psr7`, `guzzlehttp/promises`, `psr/http-client`, `psr/http-factory` and
  `psr/http-message`. App code that uses them fails with `Class "..." not found`, or works only
  because some other package happens to install them. The app must require what its own code uses
  (Case 1).
- `EloquentPersistence` needs `illuminate/database` 10.21 or later (Case 2).
- `ext-curl` is required everywhere the app is installed or runs (Case 3).
- `inspector-apm/inspector-php` is no longer installed either. Guide 46 migrates Inspector
  monitoring and adds that requirement, so leave `Inspector\` references alone here.

Other guides own related changes:

- Guide 2 moves Neuron's Guzzle adapter (`NeuronAI\HttpClient\GuzzleHttpClient`,
  `GuzzleStream`) to its 4.x namespace and covers HTTP clients the app constructs or implements.
- Guide 6 migrates Guzzle exceptions caught around the Tavily, Jina, Zep and Supadata toolkits.

This guide changes no PHP code and no stored data.

## What to Search For

Run these from the application root.

1. Guzzle, including Neuron's Guzzle adapter under its 3.x or 4.x namespace. The second command
   covers container and service definitions:

   ```
   grep -rnE 'GuzzleHttp\\|GuzzleHttpClient|GuzzleStream' --include='*.php' --exclude-dir=vendor .
   grep -rnE 'GuzzleHttp\\' --include='*.yaml' --include='*.yml' --include='*.xml' --exclude-dir=vendor --exclude-dir=node_modules .
   ```

2. PSR HTTP interfaces:

   ```
   grep -rnE 'Psr\\Http\\(Message|Client)\\' --include='*.php' --exclude-dir=vendor .
   ```

3. Packages that `composer.json` already lists:

   ```
   grep -nE '"(guzzlehttp/(guzzle|psr7|promises)|psr/http-(message|client|factory))"' composer.json
   ```

4. `EloquentPersistence`, and the installed Eloquent version:

   ```
   grep -rn 'EloquentPersistence' --include='*.php' --exclude-dir=vendor .
   composer show | grep -E '^(laravel/framework|illuminate/database) '
   ```

5. ext-curl (always applies):

   ```
   php -r 'echo extension_loaded("curl") ? "curl: loaded\n" : "curl: MISSING\n";'
   composer check-platform-reqs --no-dev
   grep -rnE '^FROM |docker-php-ext-install|docker-php-ext-enable|install-php-extensions|apt-get install|apk add|setup-php|extensions:' --include='Dockerfile*' --include='*.dockerfile' --include='*.yml' --include='*.yaml' --exclude-dir=vendor --exclude-dir=node_modules .
   ```

How to follow the hits:

- For each hit of searches 1 and 2, note the namespace (see the table in Case 1). Also note whether
  the file is test code, meaning it lives under a path mapped in `autoload-dev`, such as `tests/`.
- A reference that appears only in a docblock, a `catch` or an `instanceof` still counts. Static
  analysis needs the class.
- If searches 1, 2 and 4 find nothing, Cases 1 and 2 do not apply. Case 3 always applies.

## How to Refactor

### Case 1: App code uses a package that came with Neuron 3.x

Map every namespace found by searches 1 and 2 to its package:

| Referenced namespace | Package to require |
|---|---|
| `GuzzleHttp\Psr7\` | `guzzlehttp/psr7` |
| `GuzzleHttp\Promise\` | `guzzlehttp/promises` |
| Any other `GuzzleHttp\` (`Client`, `HandlerStack`, `RequestOptions`, `Exception\`, `Handler\MockHandler`, ...), or Neuron's `GuzzleHttpClient` / `GuzzleStream` | `guzzlehttp/guzzle` |
| `Psr\Http\Message\` interfaces whose name ends in `FactoryInterface` | `psr/http-factory` |
| Any other `Psr\Http\Message\` | `psr/http-message` |
| `Psr\Http\Client\` | `psr/http-client` |

Require every package the app's code references, even when another required package would also
install it.

Steps:

1. Skip the packages that search 3 already found in `composer.json`.
2. For each remaining package, find the version the app used with Neuron 3.x. Read it from the last
   committed lock file that still locks Neuron 3.x:

   ```
   git show HEAD:composer.lock | grep -A1 -E '"name": "(neuron-core/neuron-ai|guzzlehttp/(guzzle|psr7|promises)|psr/http-(message|client|factory))"'
   ```

   - If `neuron-core/neuron-ai` shows a 4.x version, repeat the command with an earlier revision.
     `git log --format=%h -- composer.lock` lists them.
   - If no 3.x lock file exists, ask the developer which major version to require.
3. Require each package with a caret constraint on the version from step 2 (7.10.0 becomes
   `^7.10`). This keeps the major version the app's code was written for, and Guzzle 7 and 8 differ.
   Add `--dev` when every reference to the package is in test code.
4. Leave the PHP code unchanged.
5. List the packages you added in your report. Guides 6 and 51 remove `guzzlehttp/guzzle` again if
   their changes leave no Guzzle reference.

Before: at this point `composer.json` already requires Neuron 4.x, but not Guzzle. The app's own
code still uses Guzzle, which Neuron 3.x used to install:

```json
"require": {
    "php": "^8.2",
    "neuron-core/neuron-ai": "^4.0"
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;

class GitHubClient
{
    protected Client $http;

    public function __construct()
    {
        $this->http = new Client(['base_uri' => 'https://api.github.com']);
    }

    /** @return array<string, mixed> */
    public function searchRepositories(string $query): array
    {
        $response = $this->http->get('/search/repositories', ['query' => ['q' => $query]]);

        return json_decode((string) $response->getBody(), true);
    }
}
```

After: the 3.x lock file had `guzzlehttp/guzzle` 7.10.0, so run:

```
composer require "guzzlehttp/guzzle:^7.10"
```

```json
"require": {
    "php": "^8.2",
    "guzzlehttp/guzzle": "^7.10",
    "neuron-core/neuron-ai": "^4.0"
}
```

`App\Services\GitHubClient` stays exactly as it is.

### Case 2: The app uses EloquentPersistence with illuminate/database below 10.21

This case applies only when search 4 finds `EloquentPersistence` and `laravel/framework` or
`illuminate/database` is below 10.21. This guide is the only one that handles this floor.

In 4.x, `EloquentPersistence` calls Eloquent's `createOrFirst()`, and Neuron 4.x supports
`illuminate/database` from 10.21. Composer does not enforce this floor for the app. On an older
release, a workflow run fails at runtime.

1. If the version constraint in `composer.json` already allows 10.21 (for example
   `"laravel/framework": "^10.10"`), run
   `composer update laravel/framework --with-all-dependencies`.
2. If the constraint excludes 10.21 (for example `"laravel/framework": "10.15.*"`), or the installed version is 9.x
   or older (`createOrFirst()` does not exist there), change nothing. Ask the developer to approve
   `composer require "laravel/framework:^10.21" --with-all-dependencies`, which from 9.x or older is a major Laravel
   upgrade. If they decline, report that `EloquentPersistence` fails on the first workflow run.
3. For standalone Eloquent, where `laravel/framework` is not installed, do the same with
   `illuminate/database`.
4. Report the framework update to the developer.

Before:

```
$ composer show | grep -E '^(laravel/framework|illuminate/database) '
laravel/framework   10.15.0 The Laravel Framework.
```

After:

```
$ composer show | grep -E '^(laravel/framework|illuminate/database) '
laravel/framework   10.50.3 The Laravel Framework.
```

### Case 3: ext-curl in every environment

Without ext-curl, Composer refuses to install Neuron 4.x unless platform requirements are ignored.
Neuron's default HTTP client is also built on ext-curl. Its HTTP-based providers, embeddings
providers, vector stores, rerankers, MCP transports and toolkits use that client whenever no other
client is injected.

Check the environments and report what you find. Do not edit Dockerfiles, CI workflows, server
provisioning or `php.ini`: changing infrastructure is the developer's decision.

1. Local check: the `php -r` command from search 5 prints `curl: loaded`, and
   `composer check-platform-reqs --no-dev` prints `ext-curl ... success`. The command-line `php`
   can load a different configuration from the web server's PHP (FPM or an Apache module). The
   report must say that those need ext-curl too.
2. Environment definitions: open each file that the last command of search 5 found (Dockerfiles,
   docker-compose files, CI workflows) and check whether the PHP it sets up has curl.
   - Official `php` Docker images include curl.
   - Distribution packages have names like `php8.3-curl` (Debian/Ubuntu) or `php83-curl` (Alpine).
   - `shivammathur/setup-php` lists extensions under `extensions:`.
3. Report to the developer that ext-curl is now required wherever the app is installed or runs
   (CLI, web server, queue workers, CI). Name the environment files that do not clearly provide it,
   and mention servers and platforms that are not defined in the repository.

Example report:

```
ext-curl: loaded in the local CLI. Neuron 4.x requires it everywhere the app is installed or runs.
Please check: docker/php/Dockerfile (FROM alpine:3.20, installs php83 without php83-curl),
the production PHP-FPM pool, and the queue worker servers.
```

## Checklist

- Every namespace found by searches 1 and 2 maps to a package listed in `composer.json`. It is in
  `require-dev` only when all references to it are in test code, and `composer show <package>`
  succeeds for each one.
- Each added package uses the major version the app had locked with Neuron 3.x, or the version the
  developer chose.
- The app's static analysis and test suite report no unknown `GuzzleHttp\` or `Psr\Http\` class.
- No PHP file was changed by this guide, and `Inspector\` references are left for guide 46.
- If the app uses `EloquentPersistence`, `composer show | grep -E '^(laravel/framework|illuminate/database) '`
  shows 10.21 or later.
- `php -r 'echo extension_loaded("curl") ? "curl: loaded\n" : "curl: MISSING\n";'` prints
  `curl: loaded`, and `composer check-platform-reqs --no-dev` passes.
- The report to the developer lists the packages added and covers ext-curl in every environment.
  No Dockerfile, CI or server configuration was edited.
