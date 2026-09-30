# Upgrade: Neuron's HTTP client layer: namespaces, interface and custom clients

## Summary

In 4.x a component that talks HTTP (provider, embeddings provider, vector store, reranker) no longer configures the
client it receives. It stores the client as given and sends every request with an absolute URL and its own headers.
`HttpClientInterface` lost its configuration methods as a result, and the Guzzle and Amp adapters moved to
sub-namespaces.

| 3.x | 4.x |
|---|---|
| `NeuronAI\HttpClient\GuzzleHttpClient`, `NeuronAI\HttpClient\GuzzleStream` | `NeuronAI\HttpClient\Guzzle\GuzzleHttpClient`, `NeuronAI\HttpClient\Guzzle\GuzzleStream` |
| `NeuronAI\HttpClient\AmpHttpClient`, `NeuronAI\HttpClient\AmpStream` | `NeuronAI\HttpClient\Amp\AmpHttpClient`, `NeuronAI\HttpClient\Amp\AmpStream` |
| `HttpClientInterface`: `request()`, `stream()`, `withBaseUri()`, `withHeaders()`, `withTimeout()` | `request()` and `stream()` only. `CurlHttpClient`, `GuzzleHttpClient` and `AmpHttpClient` keep the three `with*()` methods |
| Components called `withBaseUri()`/`withHeaders()` on the injected client and sent relative URIs (`'chat/completions'`) | Components store the client untouched. Each `HttpRequest` has an absolute URL and the component's headers (`protected array $httpHeaders`, declared by `HasHttpClient`) |
| Default client: `GuzzleHttpClient` | Default client: `NeuronAI\HttpClient\Curl\CurlHttpClient` |
| `new GuzzleStream($body)`, `new AmpStream($body)` | `new GuzzleStream($body, $request, $contentLength)`, `new AmpStream($body, $request)` |
| `protected isMultipartData(array $body)` on the Guzzle and Amp clients | `HttpRequest::isMultipart()` |
| `AmpHttpClient` returned 4xx/5xx responses | Throws `NeuronAI\Exceptions\HttpException` |
| `HttpResponse::json()` returned `[]` for an empty or non-JSON body | Throws `JsonException` |
| RAG properties `$host`, `$url`, `$indexUrl`, `$collectionUrl` | `$baseUri` |

This guide changes code only. It does not affect anything the application stored.

Guide 1 has already added `guzzlehttp/guzzle` to the application's requirements if it uses the Guzzle adapter.
Neuron 3.x never installed `amphp/http-client`, so an application that uses `AmpHttpClient` already requires it.

Other guides cover these related changes:
- Web toolkit `getClient()` overrides: guide 6.
- MCP transport HTTP clients: guide 51.
- The Ollama provider's `$url` property and other provider members: guide 43.
- `AzureOpenAI` and its subclasses: guide 44.

## What to Search For

Run from the application root:

```bash
# 1a. Moved classes, in PHP code and in config/DI files (\\+ also matches escaped strings such as 'NeuronAI\\HttpClient\\GuzzleHttpClient')
grep -rnE 'HttpClient\\+(GuzzleHttpClient|GuzzleStream|AmpHttpClient|AmpStream)\b' --include='*.php' --include='*.yaml' --include='*.yml' --include='*.xml' --include='*.neon' --exclude-dir=vendor .
# 1b. Grouped imports from the HttpClient namespace
grep -rnE 'NeuronAI\\HttpClient\\\{' --include='*.php' --exclude-dir=vendor .

# 2. Configuration methods called on an HTTP client, or implemented by one
grep -rnE 'with(BaseUri|Headers|Timeout)\(' --include='*.php' --exclude-dir=vendor .

# 3. Custom clients, subclasses of the built-in clients and streams, stream construction
grep -rnE 'implements .*HttpClientInterface|extends (GuzzleHttpClient|AmpHttpClient|GuzzleStream|AmpStream)\b|new (GuzzleStream|AmpStream)\(|isMultipartData' --include='*.php' --exclude-dir=vendor .

# 4. Code that sends requests through a Neuron client, or overrides a provider request hook
grep -rnE 'HasHttpClient|getHttpClient\(\)|this->httpClient\b|function (createChatHttpRequest|requestUri)\(|HttpRequest::(get|post|put|patch|delete)\(|new HttpRequest\(' --include='*.php' --exclude-dir=vendor .

# 5. Renamed RAG properties
grep -rnE 'this->(host|url|indexUrl|collectionUrl)\b|protected string \$(host|url|indexUrl|collectionUrl)\b' --include='*.php' --exclude-dir=vendor .

# 6. Status checks and JSON decoding of Neuron responses
grep -rnE '[-]>(json|isSuccessful)\(\)' --include='*.php' --exclude-dir=vendor .

# 7. Handlers that expect a Guzzle exception behind a Neuron HttpException
grep -rnE 'getPrevious\(\)|GuzzleHttp\\Exception' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:

- Search 1b finds grouped imports (`use NeuronAI\HttpClient\{...}`). Check whether a moved class is in the group.
- Search 2 also matches `HttpRequest::withHeaders()` and Laravel's `Http::withHeaders()`, which stay as they are. Sort
  the other hits:
  - A call on a value typed `HttpClientInterface` (the result of `getHttpClient()`, `$this->httpClient` in a class that
    uses `HasHttpClient` or extends a Neuron component, an injected client): Cases 2 to 5.
  - A call on a concrete client the code builds (`(new CurlHttpClient())->withTimeout(30)`) is still valid. When that
    client is handed to a component, apply Case 3 to its `withBaseUri()` and credential headers.
  - A `with*()` method declared in a class that search 3 finds: Case 7.
- Search 4: keep only classes that use `HasHttpClient` or extend a Neuron provider, embeddings provider, vector store or
  reranker, plus calls on a Neuron component's `getHttpClient()`. Check every `HttpRequest` they build (Cases 4 to 6).
- Search 5: keep only hits in subclasses of the classes listed in Case 6. A `$this->url` hit in a subclass of
  `NeuronAI\Providers\Ollama\Ollama` belongs to guide 43.
- Search 6: keep only calls on a `NeuronAI\HttpClient\HttpResponse`, either the result of `request()` or `$e->response`
  on an `HttpException` (Cases 10 and 11).

If nothing is found, this guide does not apply.

## How to Refactor

### Case 1: Imports of the Guzzle and Amp adapters

Rewrite every import, fully qualified name and config string:

- `NeuronAI\HttpClient\GuzzleHttpClient` → `NeuronAI\HttpClient\Guzzle\GuzzleHttpClient`
- `NeuronAI\HttpClient\GuzzleStream` → `NeuronAI\HttpClient\Guzzle\GuzzleStream`
- `NeuronAI\HttpClient\AmpHttpClient` → `NeuronAI\HttpClient\Amp\AmpHttpClient`
- `NeuronAI\HttpClient\AmpStream` → `NeuronAI\HttpClient\Amp\AmpStream`

Before (3.x):

```php
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\OpenAI\OpenAI;

$provider = new OpenAI(key: $key, model: 'gpt-4o', httpClient: new GuzzleHttpClient(timeout: 30));
```

After (4.x):

```php
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\OpenAI\OpenAI;

$provider = new OpenAI(key: $key, model: 'gpt-4o', httpClient: new GuzzleHttpClient(timeout: 30));
```

The constructor arguments did not change: `GuzzleHttpClient(customHeaders, timeout, connectTimeout, handler, options)`
and `AmpHttpClient(customHeaders, timeout)`. Their default `timeout` went from 60 to 120 seconds. Where one of them is
built without `timeout:`, ask the developer whether the 60-second limit matters, and if it does, pass `timeout: 60.0`.
Case 8 covers the stream constructors.

### Case 2: Settings applied to a component's client after construction

`$component->getHttpClient()` returns an `HttpClientInterface`, and that interface no longer declares `withTimeout()`,
`withHeaders()` or `withBaseUri()`. Static analysis reports these calls. At runtime they fail when the client is a
`StoppableHttpClient` or an application client that lacks the methods. Configure the client before you hand it to the
component. If the component used the default client, build a `CurlHttpClient`, which is the 4.x default.

Before (3.x):

```php
use NeuronAI\Providers\OpenAI\OpenAI;

$provider = new OpenAI(key: $key, model: 'gpt-4o');
$provider->getHttpClient()->withTimeout(120)->withHeaders(['X-Tenant' => $tenant]);
```

After (4.x):

```php
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\Providers\OpenAI\OpenAI;

$provider = new OpenAI(
    key: $key,
    model: 'gpt-4o',
    httpClient: new CurlHttpClient(customHeaders: ['X-Tenant' => $tenant], timeout: 120),
);
```

`setHttpClient(new CurlHttpClient(...))` works the same way. Several components can now share one client instance.
If only one request needs a different timeout, set `timeout:` on that `HttpRequest` instead (Case 4). A `withBaseUri()`
or `withHeaders()` call that sent the component to another endpoint, or replaced one of its headers, is Case 3.

### Case 3: Clients pre-configured with a component's base URI or headers

Components ignore the client's base URI, because their request URLs are absolute. Their own headers override client
headers of the same name (compared case-insensitively). Delete any client configuration that only repeated the
component's URL and credentials.

Before (3.x):

```php
use NeuronAI\HttpClient\GuzzleHttpClient;

$provider->setHttpClient(
    (new GuzzleHttpClient(timeout: 30))
        ->withBaseUri('https://api.openai.com/v1')
        ->withHeaders(['Authorization' => 'Bearer ' . $key])
);
```

After (4.x):

```php
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;

$provider->setHttpClient(new GuzzleHttpClient(timeout: 30));
```

Two 3.x patterns need a replacement rather than a deletion:

1. **A base URI that sent the component to a gateway, proxy or self-hosted endpoint.** Give that URL to the component
   through its own argument:
   - `baseUri:` on `OpenAILike`, `Gemini`, `Cohere`, `ZAI` and `DashScopeOpenAI`. For an `OpenAI` provider, switch to
     `new OpenAILike(baseUri: $url, key: $key, model: $model)`.
   - `url:` on `Ollama` and `OllamaEmbeddingsProvider`.
   - `host:`, `indexUrl:` or `collectionUrl:` on the vector stores and rerankers.

   A component without such an argument needs a subclass that sets `$this->baseUri`:

   Before (3.x):

   ```php
   use NeuronAI\Providers\Anthropic\Anthropic;

   $provider = new Anthropic(key: $key, model: 'claude-sonnet-4-5');
   $provider->getHttpClient()->withBaseUri($gatewayUrl);
   ```

   After (4.x):

   ```php
   use NeuronAI\Providers\Anthropic\Anthropic;

   class GatewayAnthropic extends Anthropic
   {
       public function __construct(string $baseUri, string $key, string $model)
       {
           parent::__construct(key: $key, model: $model);
           $this->baseUri = $baseUri;
       }
   }

   $provider = new GatewayAnthropic(baseUri: $gatewayUrl, key: $key, model: 'claude-sonnet-4-5');
   ```

2. **A header that replaced one the component sends** (`Authorization`, `x-api-key`, `anthropic-version`, ...). A client
   header no longer wins over a request header. If the component has a constructor argument for the value (for example
   Anthropic `version:`), use it. Otherwise set the header in `$this->httpHeaders` in a subclass (Case 5).

### Case 4: Classes that send their own requests through a Neuron client

This case covers three kinds of code:
- Application classes that use the `HasHttpClient` trait.
- Extra requests made inside subclasses of Neuron providers, embeddings providers, vector stores and rerankers.
- Requests sent through `$component->getHttpClient()`.

Each request must carry an absolute URL and every header it needs, credentials included, because the client has
neither.

1. Store the client as given: `$this->httpClient = $httpClient ?? new GuzzleHttpClient();`. Keep the class's own
   default client. `new CurlHttpClient()` is also valid and needs no extra package.
2. Move the headers to `$this->httpHeaders`. `HasHttpClient` declares it as `protected array $httpHeaders = []`. A class
   that declares its own `$httpHeaders` must drop that declaration and assign the value in the constructor. If the two
   declarations differ, PHP raises a fatal error.
3. Build every `HttpRequest` with `rtrim($this->baseUri, '/') . '/path'` and `$this->httpHeaders`. In a subclass of a
   Neuron component, the parent constructor has already set both.

Before (3.x):

```php
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;

class WeatherApi
{
    use HasHttpClient;

    protected string $baseUri = 'https://api.weather.example/v1';

    public function __construct(protected string $key, ?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri($this->baseUri)
            ->withHeaders(['Authorization' => 'Bearer ' . $this->key]);
    }

    public function forecast(string $city): array
    {
        return $this->httpClient->request(HttpRequest::post('forecast', ['city' => $city]))->json();
    }
}
```

After (4.x):

```php
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;

class WeatherApi
{
    use HasHttpClient;

    protected string $baseUri = 'https://api.weather.example/v1';

    public function __construct(protected string $key, ?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new GuzzleHttpClient();
        $this->httpHeaders = ['Authorization' => 'Bearer ' . $this->key];
    }

    public function forecast(string $city): array
    {
        return $this->httpClient->request(
            HttpRequest::post(rtrim($this->baseUri, '/') . '/forecast', ['city' => $city], $this->httpHeaders)
        )->json();
    }
}
```

Code outside a component cannot read the component's protected headers. Build the URL and credentials in the calling
code:

Before (3.x):

```php
$models = $provider->getHttpClient()->request(HttpRequest::get('models'))->json();
```

After (4.x):

```php
$models = $provider->getHttpClient()->request(
    HttpRequest::get('https://api.openai.com/v1/models', ['Authorization' => 'Bearer ' . $key])
)->json();
```

To set a timeout for one request, put it on the request:
`new HttpRequest(HttpMethod::POST, $url, $headers, $body, timeout: 120.0)`, with
`use NeuronAI\HttpClient\HttpMethod;`.

### Case 5: Provider subclasses: request hooks and extra headers

- Two protected hooks must now return an absolute URI:
  - `createChatHttpRequest(array $payload): HttpRequest` on OpenAI and its subclasses, Cohere included. It must also
    pass `headers: $this->httpHeaders`. An override that only decorates `parent::createChatHttpRequest($payload)` needs
    no change.
  - `requestUri(bool $stream): string` on `Anthropic` and `AnthropicVertex`.
- Put headers the subclass adds into `$this->httpHeaders` after `parent::__construct()`. This works for every provider.
  For a header whose value is computed on each request (Anthropic, Gemini and their Vertex variants only), override
  `protected function requestHeaders(): array` and merge `parent::requestHeaders()`.
- Leave subclasses of `AzureOpenAI` for guide 44.

Before (3.x):

```php
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAILike;

class BetaAnthropic extends Anthropic
{
    public function __construct(string $key, string $model)
    {
        parent::__construct(key: $key, model: $model);
        $this->httpClient->withHeaders(['anthropic-beta' => 'context-1m-2025-08-07']);
    }

    protected function requestUri(bool $stream): string
    {
        return 'messages?beta=true';
    }
}

class LocalLlm extends OpenAILike
{
    protected function createChatHttpRequest(array $payload): HttpRequest
    {
        return HttpRequest::post(uri: 'v1/chat/completions', body: $payload);
    }
}
```

After (4.x):

```php
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAILike;

class BetaAnthropic extends Anthropic
{
    public function __construct(string $key, string $model)
    {
        parent::__construct(key: $key, model: $model);
        $this->httpHeaders['anthropic-beta'] = 'context-1m-2025-08-07';
    }

    protected function requestUri(bool $stream): string
    {
        return rtrim($this->baseUri, '/') . '/messages?beta=true';
    }
}

class LocalLlm extends OpenAILike
{
    protected function createChatHttpRequest(array $payload): HttpRequest
    {
        return HttpRequest::post(
            uri: rtrim($this->baseUri, '/') . '/v1/chat/completions',
            body: $payload,
            headers: $this->httpHeaders,
        );
    }
}
```

### Case 6: Subclasses of RAG HTTP classes

Each of these classes now keeps its base URL in `protected string $baseUri`. The constructor argument names did not
change.

| Class | 3.x property | Value of the 4.x `$baseUri` |
|---|---|---|
| `JinaRerankerPostProcessor` | `$host` | Unchanged (default `https://api.jina.ai/v1`) |
| `LocalAIRerankerPostProcessor` | `$host` (the `host:` argument) | `host:` argument + `/v1` |
| `ChromaVectorStore` | `$host` (the `host:` argument) | `{host}/api/v2/tenants/{tenant}/databases/{database}/collections/` |
| `MeilisearchVectorStore`, `WeaviateVectorStore` | `$host` | The `host:` argument |
| `PineconeVectorStore` | `$indexUrl` | The `indexUrl:` argument |
| `QdrantVectorStore` | `$collectionUrl` | The `collectionUrl:` argument |
| `OllamaEmbeddingsProvider` | `$url` | The `url:` argument |

1. Replace reads of the old property with `$this->baseUri`. For LocalAI and Chroma the value now includes a path, so
   adjust any path the subclass appended itself.
2. A Jina subclass that redeclared `protected string $host` to change the endpoint now silently calls `api.jina.ai`.
   Redeclare `protected string $baseUri` instead.
3. Extra requests in these subclasses follow Case 4: absolute URL and `$this->httpHeaders`.

Before (3.x):

```php
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\PostProcessor\JinaRerankerPostProcessor;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;

class EuJinaReranker extends JinaRerankerPostProcessor
{
    protected string $host = 'https://eu.api.jina.ai/v1';
}

class CountingQdrantVectorStore extends QdrantVectorStore
{
    public function countPoints(): int
    {
        return $this->httpClient->request(HttpRequest::post('points/count', ['exact' => true]))->json()['result']['count'];
    }
}
```

After (4.x):

```php
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\PostProcessor\JinaRerankerPostProcessor;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;

class EuJinaReranker extends JinaRerankerPostProcessor
{
    protected string $baseUri = 'https://eu.api.jina.ai/v1';
}

class CountingQdrantVectorStore extends QdrantVectorStore
{
    public function countPoints(): int
    {
        return $this->httpClient->request(
            HttpRequest::post(rtrim($this->baseUri, '/') . '/points/count', ['exact' => true], $this->httpHeaders)
        )->json()['result']['count'];
    }
}
```

### Case 7: Classes implementing HttpClientInterface, including test fakes

The framework never calls `withBaseUri()`, `withHeaders()` or `withTimeout()` on a client, so a custom client gets
everything it needs from the `HttpRequest`:

1. Send `$request->uri` as it is. Components always send absolute URLs. Keep base-URI resolution only for relative URIs
   that the application sends itself.
2. Send `$request->headers`, which carry the credentials. They override the client's default headers by
   case-insensitive name.
3. When `$request->timeout` (seconds) is not null, use it for that request. MCP transports set it.
4. Throw `HttpException::statusError($request, $response)` for status >= 400 and
   `HttpException::networkError($request, $reason, $previous)` for transport failures, in both `request()` and
   `stream()`. If the client returns error responses instead, providers parse the error bodies as answers. In
   `stream()`, check the status before returning the stream. Throw `networkError` from `read()`/`readLine()` when the
   connection drops before the body ends.
5. Delete the `with*()` methods, unless application code calls them on this class directly. A decorator must not
   forward them to the client it wraps, because the interface no longer declares them.
6. Use `$request->isMultipart()` to tell whether an array body must be sent as multipart.
7. Tests that record requests through a fake client now see the absolute URL and the component's headers on the
   `HttpRequest`. Update assertions such as `'chat/completions'` to `'https://api.openai.com/v1/chat/completions'`.

In the example, `GatewayTransport`, `GatewayTransportException` and `GatewayStream` (a `StreamInterface` with a
`statusCode` property and a `drain()` method) stand for the application's own transport code.

Before (3.x):

```php
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

class GatewayHttpClient implements HttpClientInterface
{
    protected string $baseUri = '';

    public function __construct(
        protected GatewayTransport $transport,
        protected array $headers = [],
        protected float $timeout = 60.0,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        return $this->transport->send(...$this->arguments($request));
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        return $this->transport->open(...$this->arguments($request));
    }

    public function withBaseUri(string $baseUri): HttpClientInterface
    {
        $this->baseUri = $baseUri;
        return $this;
    }

    public function withHeaders(array $headers): HttpClientInterface
    {
        $this->headers = [...$this->headers, ...$headers];
        return $this;
    }

    public function withTimeout(float $timeout): HttpClientInterface
    {
        $this->timeout = $timeout;
        return $this;
    }

    protected function arguments(HttpRequest $request): array
    {
        return [
            $request->method->value,
            rtrim($this->baseUri, '/') . '/' . ltrim($request->uri, '/'),
            [...$this->headers, ...$request->headers],
            $request->body,
            $this->timeout,
        ];
    }
}
```

After (4.x):

```php
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

class GatewayHttpClient implements HttpClientInterface
{
    public function __construct(
        protected GatewayTransport $transport,
        protected array $headers = [],
        protected float $timeout = 60.0,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        try {
            $response = $this->transport->send(...$this->arguments($request));
        } catch (GatewayTransportException $e) {
            throw HttpException::networkError($request, $e->getMessage(), $e);
        }

        if ($response->statusCode >= 400) {
            throw HttpException::statusError($request, $response);
        }

        return $response;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        try {
            $stream = $this->transport->open(...$this->arguments($request));
        } catch (GatewayTransportException $e) {
            throw HttpException::networkError($request, $e->getMessage(), $e);
        }

        if ($stream->statusCode >= 400) {
            throw HttpException::statusError($request, new HttpResponse($stream->statusCode, $stream->drain()));
        }

        return $stream;
    }

    protected function arguments(HttpRequest $request): array
    {
        return [
            $request->method->value,
            $request->uri,
            [...array_change_key_case($this->headers), ...array_change_key_case($request->headers)],
            $request->body,
            $request->timeout ?? $this->timeout,
        ];
    }
}
```

### Case 8: Code that builds a GuzzleStream or AmpStream

Both constructors now require the `HttpRequest` being answered, so that a cut connection raises an `HttpException`
naming that request. You usually find these calls in custom clients and test fakes. A subclass of either stream must
accept the request and pass it to the parent constructor.

Before (3.x):

```php
use GuzzleHttp\Psr7\Utils;
use NeuronAI\HttpClient\GuzzleStream;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StreamInterface;

// in a test fake or custom client
public function stream(HttpRequest $request): StreamInterface
{
    return new GuzzleStream(Utils::streamFor($this->fixture));
}
```

After (4.x):

```php
use GuzzleHttp\Psr7\Utils;
use NeuronAI\HttpClient\Guzzle\GuzzleStream;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StreamInterface;

// in a test fake or custom client
public function stream(HttpRequest $request): StreamInterface
{
    return new GuzzleStream(Utils::streamFor($this->fixture), $request);
}
```

When you wrap a real PSR-7 response, also pass its declared length, so that a truncated body throws:
`new GuzzleStream($response->getBody(), $request, $response->hasHeader('Content-Length') ? (int) $response->getHeaderLine('Content-Length') : null)`.
For Amp: `new AmpStream($response->getBody(), $request)`.

### Case 9: Subclasses of GuzzleHttpClient or AmpHttpClient

The clients now call `$request->isMultipart()`. An `isMultipartData()` override is never called, and its
`parent::isMultipartData()` call no longer resolves, so delete the override. A body is multipart when it holds a
resource, either bare or as a part's `contents`. To upload in-memory data, pass it as a stream. If the subclass
contained nothing else, delete it and use the parent client.

Before (3.x):

```php
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpRequest;

class UploadingHttpClient extends GuzzleHttpClient
{
    protected function isMultipartData(array $body): bool
    {
        return isset($body['file']) || parent::isMultipartData($body);
    }
}

$client = new UploadingHttpClient();
$client->request(HttpRequest::post($url, ['file' => ['contents' => $csv, 'filename' => 'data.csv']]));
```

After (4.x):

```php
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpRequest;

$file = fopen('php://temp', 'r+');
fwrite($file, $csv);
rewind($file);

$client = new GuzzleHttpClient();
$client->request(HttpRequest::post($url, ['file' => ['contents' => $file, 'filename' => 'data.csv']]));
```

On `GuzzleHttpClient`, the `$client` property is now `protected ?\GuzzleHttp\Client $client = null`. A subclass that
redeclares it must use that type.

A subclass that assigns `$this->client` itself (for example in its constructor, to inject a preconfigured
`GuzzleHttp\Client` or `Amp\Http\Client\HttpClient`) is silently bypassed in 4.x: on first use the client replaces a
client it did not build with a default one. Return the preconfigured client from an override of
`protected function createClient(): \GuzzleHttp\Client` (Guzzle) or `protected function getClient(): \Amp\Http\Client\HttpClient`
(Amp) instead.

Before (3.x):

```php
use GuzzleHttp\Client;
use NeuronAI\HttpClient\GuzzleHttpClient;

class ProxiedHttpClient extends GuzzleHttpClient
{
    public function __construct(Client $client)
    {
        parent::__construct();
        $this->client = $client;
    }
}
```

After (4.x):

```php
use GuzzleHttp\Client;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;

class ProxiedHttpClient extends GuzzleHttpClient
{
    public function __construct(protected Client $preconfigured)
    {
        parent::__construct();
    }

    protected function createClient(): Client
    {
        return $this->preconfigured;
    }
}
```

### Case 10: Direct AmpHttpClient calls that check the status

`AmpHttpClient::request()` and `stream()` now throw `HttpException` for status >= 400, like the other clients. Move the
error branch into a `catch` block. Network errors threw in 3.x too, so rethrow them.

Before (3.x):

```php
use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\HttpClient\HttpRequest;

function fetchItems(string $url): ?array
{
    $response = (new AmpHttpClient())->request(HttpRequest::get($url));
    if (!$response->isSuccessful()) {
        return null;
    }
    return $response->json();
}
```

After (4.x):

```php
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\HttpRequest;

function fetchItems(string $url): ?array
{
    try {
        $response = (new AmpHttpClient())->request(HttpRequest::get($url));
    } catch (HttpException $e) {
        if ($e->response === null) {
            throw $e;
        }
        return null;
    }
    return $response->json();
}
```

### Case 11: HttpResponse::json() on a body that may be empty or not JSON

`json()` now throws `JsonException` unless the body is a JSON object or array. Guard the calls whose body may be empty
(204, DELETE) or not JSON (HTML error pages), especially `$e->response?->json()` in `HttpException` handlers. Code that
needs only the text can read `$response->body`.

Before (3.x):

```php
$data = $response->json();
```

After (4.x):

```php
use JsonException;

try {
    $data = $response->json();
} catch (JsonException) {
    $data = [];
}
```

### Other behaviour to check

- `CurlHttpClient` and `GuzzleHttpClient` refuse a redirect to another scheme, host or port, and throw `HttpException`
  ("refused a redirect to another origin"). If a configured URL redirects that way (for example from `http://` to
  `https://`), ask the developer for the final URL and configure it. `GuzzleHttpClient` still follows whatever an
  explicit `options: [\GuzzleHttp\RequestOptions::ALLOW_REDIRECTS => ...]` allows.
- A stream cut before its body ends now throws `HttpException` from `read()`/`readLine()` instead of reporting EOF. Code
  that reads `$client->stream()` itself must catch it wherever a truncated body used to be accepted.
- `CurlHttpClient` and `AmpHttpClient` upload a multipart part given as `['contents' => 'text']` as a file. Send plain
  form fields as scalar values (`'purpose' => 'batch'`).
- Components built without `httpClient:` now use `CurlHttpClient`, whose `HttpException` has no previous exception. In
  3.x the default Guzzle client put the Guzzle exception there. For search 7 hits in handlers around Neuron calls, replace
  `$e->getPrevious() instanceof ConnectException` with `$e->response === null`, and Guzzle status/response reads with
  `$e->response?->statusCode` / `$e->response?->body`. Only a component given a `GuzzleHttpClient` still reports the
  Guzzle exception as previous. Toolkit and MCP handlers belong to guides 6 and 51.

## Checklist

- Search 1a finds nothing, and no grouped import from search 1b names a moved class: no `GuzzleHttpClient`,
  `GuzzleStream`, `AmpHttpClient` or `AmpStream` remains in the old namespace, in PHP code or in config files.
- No code calls `withBaseUri()`, `withHeaders()` or `withTimeout()` on a value typed `HttpClientInterface`.
- No client is configured only to repeat a component's base URL or credentials. Gateway URLs go to the component, as an
  argument or through `$baseUri`. Header overrides live in `$this->httpHeaders`.
- Every `HttpRequest` sent through a Neuron component's client, or from a class that uses `HasHttpClient`, has an
  absolute URL and the headers it needs. Every `createChatHttpRequest()` and `requestUri()` override returns an
  absolute URI.
- No class that uses `HasHttpClient` declares its own `$httpHeaders` property.
- No subclass of a class listed in Case 6 reads or redeclares `$host`, `$url`, `$indexUrl` or `$collectionUrl`.
- Every `HttpClientInterface` implementation meets the Case 7 rules:
  - It sends `$request->uri` as is and sends `$request->headers`.
  - It honours `$request->timeout`.
  - It throws `HttpException` for status >= 400 and for network errors.
  - It does not forward `with*()` calls to a wrapped client.
- Every `new GuzzleStream(...)` and `new AmpStream(...)` passes the `HttpRequest`.
- No `isMultipartData` remains.
- No direct `AmpHttpClient` call expects a 4xx/5xx response back. Every `json()` call on a body that may be empty or not
  JSON handles `JsonException`.
- No subclass of `GuzzleHttpClient` or `AmpHttpClient` assigns `$this->client` outside `createClient()`/`getClient()`.
- No handler of a Neuron `HttpException` expects a Guzzle exception from `getPrevious()`, unless that component is given a
  `GuzzleHttpClient`.
