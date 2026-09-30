# Upgrade: AzureOpenAI calls the v1 API

## Summary

`NeuronAI\Providers\OpenAI\AzureOpenAI` now calls Azure OpenAI's v1 API. That API takes the deployment name as `model`
and has no API version. What changes for application code:

- The `version` constructor parameter and the protected `$version` property are removed. `strict_response`,
  `parameters` and `httpClient` each move one position earlier.
- The key is sent in the `api-key` header instead of `Authorization: Bearer`. It must be a resource key: a Microsoft
  Entra ID access token no longer works (Case 5).
- `endpoint` is still the resource URL or host (for example `https://my-resource.openai.azure.com`), and `model` is
  still the deployment name. The provider appends `/openai/v1` to the endpoint itself.

| 3.x | 4.x |
|---|---|
| `__construct(string $key, string $endpoint, string $model, string $version, bool $strict_response = false, array $parameters = [], ?HttpClientInterface $httpClient = null)` | `__construct(string $key, string $endpoint, string $model, bool $strict_response = false, array $parameters = [], ?HttpClientInterface $httpClient = null)` |
| Key sent as `Authorization: Bearer {key}` | Key sent as `api-key: {key}` |

Neuron 3.15.27 and later 3.x releases throw an `Error` when they construct `AzureOpenAI`. If the application's Azure
calls worked on 3.x, it has a workaround, usually a subclass of `AzureOpenAI` (Case 3).

This guide changes code and configuration only. Nothing the application stored with 3.x is affected.

## What to Search For

Run from the application root:

```bash
# 1. Constructions, imports, subclasses and container definitions
grep -rnE 'AzureOpenAI' --include='*.php' --include='*.yaml' --include='*.yml' --include='*.xml' --include='*.neon' --exclude-dir=vendor .

# 2. Where the API version came from, and requests or tests built on the 3.x deployments URL
grep -rniE 'azure[a-z_.-]*version|api[-_]?version|openai/deployments' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .

# 3. Microsoft Entra ID token acquisition (Case 5)
grep -rniE 'login\.microsoftonline\.com|identity/oauth2/token|IDENTITY_ENDPOINT|cognitiveservices\.azure\.com/\.default|DefaultAzureCredential|ClientSecretCredential|azure_?ad_?token' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git .
```

How to follow the hits:

- Search 1: each `new AzureOpenAI(` is Case 1 (named arguments) or Case 2 (positional arguments). Each
  `AzureOpenAI::class` binding or service definition is Case 2. Each `extends AzureOpenAI` is Case 3: grep for the
  subclass name too, and migrate its own subclasses and every place that constructs it.
- For every construction, trace the expression passed as `version` back to its config key or environment variable
  (Case 4), and the expression passed as `key` back to its source (Case 5).
- Search 2: keep only hits that fed `version` to `AzureOpenAI`, lines inside an `AzureOpenAI` subclass, and tests of
  Azure requests (Case 6). Other Azure services have their own `api-version` and stay as they are.
- Search 3: keep only hits whose token reaches the `key` argument of `AzureOpenAI` (Case 5).

If search 1 finds nothing, this guide does not apply.

Guide 2 has already migrated the HTTP client classes passed as `httpClient:`, and guide 41 has already migrated what
`chat()`, `stream()` and `structured()` return.

## How to Refactor

### Case 1: Named arguments

Delete the `version:` argument. A leftover `version:` fails with `Error: Unknown named parameter $version`.

Before (3.x):

```php
use NeuronAI\Providers\OpenAI\AzureOpenAI;

$provider = new AzureOpenAI(
    key: $_ENV['AZURE_OPENAI_KEY'],
    endpoint: $_ENV['AZURE_OPENAI_ENDPOINT'],
    model: $_ENV['AZURE_OPENAI_DEPLOYMENT'],
    version: $_ENV['AZURE_OPENAI_VERSION'],
);
```

After (4.x):

```php
use NeuronAI\Providers\OpenAI\AzureOpenAI;

$provider = new AzureOpenAI(
    key: $_ENV['AZURE_OPENAI_KEY'],
    endpoint: $_ENV['AZURE_OPENAI_ENDPOINT'],
    model: $_ENV['AZURE_OPENAI_DEPLOYMENT'],
);
```

Then apply Case 4 to the value that fed `version`.

### Case 2: Positional arguments and container definitions

Delete the fourth argument.

Before (3.x):

```php
use NeuronAI\Providers\OpenAI\AzureOpenAI;

$provider = new AzureOpenAI($key, $endpoint, $deployment, '2024-10-21', true, ['temperature' => 0]);
```

After (4.x):

```php
use NeuronAI\Providers\OpenAI\AzureOpenAI;

$provider = new AzureOpenAI($key, $endpoint, $deployment, true, ['temperature' => 0]);
```

A missed call does not always fail. A call with exactly four arguments, in a file without `declare(strict_types=1)`,
runs without an error: the version string is converted to `strict_response: true`, which turns on strict JSON schema
for structured output. Check every positional call.

Container definitions pass the same arguments. Delete the `$version` key of a named-argument definition (Symfony YAML
or XML), or the fourth entry of a positional `arguments:` list.

Then apply Case 4 to the value that fed `version`.

### Case 3: A subclass of `AzureOpenAI`

Before (3.x):

```php
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\OpenAI\AzureOpenAI;

class TenantAzureOpenAI extends AzureOpenAI
{
    protected string $baseUri = 'https://%s/openai/deployments/%s';

    public function __construct(
        string $key,
        string $endpoint,
        string $model,
        string $version,
        array $parameters = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct($key, $endpoint, $model, $version, false, $parameters, $httpClient);

        $this->httpClient = $this->httpClient
            ->withBaseUri("https://{$this->endpoint}/openai/deployments/{$this->model}")
            ->withHeaders(['api-key' => $this->key, 'X-Tenant' => 'acme']);
    }

    protected function createChatHttpRequest(array $payload): HttpRequest
    {
        return HttpRequest::post(uri: 'chat/completions?api-version=' . $this->version, body: $payload);
    }
}
```

After (4.x):

```php
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\OpenAI\AzureOpenAI;

class TenantAzureOpenAI extends AzureOpenAI
{
    public function __construct(
        string $key,
        string $endpoint,
        string $model,
        array $parameters = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct($key, $endpoint, $model, false, $parameters, $httpClient);

        $this->httpHeaders['X-Tenant'] = 'acme';
    }
}
```

1. Remove `version` from the subclass constructor and from its `parent::__construct()` call. Remove every read of
   `$this->version`. Migrate the code that constructs the subclass as in Cases 1 and 2.
2. Delete what only rebuilt Azure's deployments URL or authentication, because 4.x builds the v1 URL and the `api-key`
   header itself:
   - a redeclared `$baseUri` (the 4.x constructor overwrites it);
   - a `createChatHttpRequest()` override that builds the deployments path or adds `api-version`;
   - `withBaseUri()` and `withHeaders()` calls that set Azure's URL, `Authorization` or `api-key`, whether in the
     subclass or on a client passed as `httpClient:`.

   A constructor that set up the client itself instead of calling the parent must call
   `parent::__construct($key, $endpoint, $model, $strict_response, $parameters, $httpClient)`.
3. Set any other header the subclass added in `$this->httpHeaders` after `parent::__construct()`, as in the After.
4. If the subclass sent requests to a host other than the Azure resource (an API gateway or proxy), ask the developer
   whether that host serves Azure's v1 routes (`/openai/v1/chat/completions`). If it does, pass the gateway URL as
   `endpoint`: 4.x keeps its path, always uses `https` and appends `/openai/v1`. If it does not, keep a
   `createChatHttpRequest()` override that returns the absolute gateway URL with `headers: $this->httpHeaders` (the
   4.x form is in guide 2, Case 5).
5. `$this->endpoint` now holds the argument exactly as passed. 3.x reduced `https://host/` to `host`. Code that still
   reads it must not assume a bare host.
6. Keep the subclass even if only a forwarding constructor remains: its callers and container bindings use it.

### Case 4: Configuration that only held the API version

1. Remove the expression that fed `version` (for example `$_ENV['AZURE_OPENAI_VERSION']` or
   `config('services.azure_openai.version')`).
2. If a config key or container parameter exists only for it, remove that key from the config file.
3. Do not edit `.env*` files or deployment configuration (CI variables, Docker Compose files, secrets). List the
   now-unused environment variable (for example `AZURE_OPENAI_VERSION`) in your report, so the developer removes it
   where it is deployed.

### Case 5: A Microsoft Entra ID token passed as `key`

The value passed as `key` is an Entra ID access token when it comes from a token request (search 3), a managed
identity endpoint, or a setting that holds a token rather than a resource key.

Do not change the credential or the provider code. Report each such construction and where its token comes from to the
developer: 4.x sends `key` in the `api-key` header, which accepts only a resource key, so these requests will be rejected
until the developer changes how the application authenticates.

### Case 6: Tests that assert the outgoing request

A test that checks the URL or headers of a request sent by `AzureOpenAI` must expect:

- `POST https://{resource}/openai/v1/chat/completions`, with no `api-version`;
- the deployment name in the JSON body's `model` field;
- an `api-key` header and no `Authorization` header.

Tests that only fake the response body need no change.

## Checklist

- No `AzureOpenAI` construction, subclass construction or container definition passes `version`, either by name or as
  a fourth positional string.
- No subclass declares or passes `version`, reads `$this->version`, redeclares `$baseUri`, overrides
  `createChatHttpRequest()` to add `api-version`, or sets up its own URL or auth header on the HTTP client.
- Headers a subclass added for other purposes are set in `$this->httpHeaders` after `parent::__construct()`.
- Config keys that only held the API version are removed, and unused environment variables are reported, not edited.
- The value passed as `key` is a resource key, or the developer has been told that an Entra ID token no longer works.
- Tests of Azure requests expect the v1 URL and the `api-key` header.
- Searches 1 and 2 show no remaining `version` argument, `api-version` or `openai/deployments` in code that builds or
  tests `AzureOpenAI`.
