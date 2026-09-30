# Upgrade: Tavily, Jina, Zep and Supadata toolkits use Neuron's HTTP client

## Summary

The web toolkits under `NeuronAI\Tools\Toolkits\{Tavily,Jina,Zep,Supadata}` no longer use Guzzle. Each tool now sends requests through a `NeuronAI\HttpClient\HttpClientInterface` that you can pass to its constructor.

| 3.x | 4.x |
|-----|-----|
| `protected GuzzleHttp\Client $client` and `protected getClient(): Client` on `TavilySearchTool`, `TavilyExtractTool`, `TavilyCrawlTool`, `JinaWebSearch`, `JinaUrlReader` and the `Zep\HandleZepClient` trait | Removed. The tools hold `protected HttpClientInterface $httpClient`, which comes from a new last constructor argument |
| Public `getClient(string $key): Client` on the `Supadata\HttpClient` trait (all Supadata tools) | Removed. Subclasses get a protected `get(string $endpoint, array $query): HttpResponse` |
| Toolkits take only the key (Zep: key and user ID) | They take an extra optional `?HttpClientInterface $httpClient` and hand it to every tool they provide |
| HTTP failures throw `GuzzleHttp\Exception\*` | They throw `NeuronAI\Exceptions\HttpException` |
| An invalid URL passed to `JinaUrlReader`, `TavilyExtractTool` or `TavilyCrawlTool` throws `ToolException('Invalid URL.')` | The tool returns `ToolOutput::error('Invalid URL: an absolute http or https URL is required.')` and the run continues |

4.x constructors. `make()` forwards the same arguments. When `$httpClient` is `null`, the tool creates a `CurlHttpClient`:

| Class | Arguments |
|-------|-----------|
| `TavilyToolkit`, `TavilyExtractTool`, `TavilyCrawlTool`, `JinaToolkit`, `JinaUrlReader`, `SupadataYouTubeToolkit`, `SupadataVideoMetadataTool`, `SupadataVideoTranscriptTool`, `SupadataYoutubeChannelTool`, `SupadataYoutubePlaylistTool` | `(string $key, ?HttpClientInterface $httpClient = null)` |
| `TavilySearchTool`, `JinaWebSearch` | `(string $key, array $topics = [], ?HttpClientInterface $httpClient = null)` |
| `ZepLongTermMemoryToolkit`, `ZepSearchGraphTool`, `ZepAddToGraphTool` | `(string $key, string $user_id, ?HttpClientInterface $httpClient = null)` |

To pass a client to `TavilySearchTool`, use `new TavilySearchTool(...)` rather than `make()`. Its `make()` works at runtime, but static analysis rejects the extra argument.

Code that only registers these toolkits or tools with their key, such as `TavilyToolkit::make($key)`, keeps working unchanged. The Zep and Supadata toolkits are deprecated in 4.x but still work, so do not replace them as part of this upgrade. No data the app stored with 3.x is involved.

Other guides own these related changes:
- Guide 2: the HTTP client classes themselves.
- Guide 4: the `toolErrorHandler` signature.
- Guide 11: registering `TavilyToolkit` and `JinaToolkit` on the same agent now fails, because both provide tools named `web_search` and `url_reader`.

## What to Search For

Run from the application root:

```bash
# 1. Every file that references these toolkits, their tools or their traits
grep -rnE 'Toolkits\\(Tavily|Jina|Zep|Supadata)\b' --include='*.php' --exclude-dir=vendor .

# 2. Use of the removed Guzzle client
grep -rnE 'getClient[(]|RequestOptions::|\$this->client\b' --include='*.php' --exclude-dir=vendor .

# 3. Guzzle exception types
grep -rnE 'GuzzleHttp\\Exception|GuzzleException|(Client|Server|Connect|Request|BadResponse|Transfer)Exception\b' --include='*.php' --exclude-dir=vendor .

# 4. Handling of the old invalid-URL exception
grep -rnE 'Invalid URL|ToolException' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:
- **Search 1** finds the imports. In each file, look for:
  - classes that `extend` one of these tools or toolkits, and the subclasses of those classes;
  - classes that `use` the `HandleZepClient` or `Supadata\HttpClient` trait;
  - agents that register these tools. Remember which agents they are for search 3;
  - code that builds one of these tools and calls it directly (`$tool(...)`, `->__invoke(`, `->execute()`), including tests (Cases 2 and 6), and code that sets a tool's protected `client` property (a subclass constructor, `ReflectionProperty`, `Closure::bind`), which Cases 1 and 2 treat like a `getClient()` override.
- **Search 2**: keep only the hits inside the classes found by search 1, plus calls to `->getClient($key)` on a Supadata tool. Other hits are the app's own Guzzle code, which this guide does not touch.
- **Search 3**: migrate a hit if it is in a `toolErrorHandler(...)` callback or `resolveToolErrorHandler()` override of an agent from search 1, or in a `try`/`catch` around a run of an agent from search 1 or around a direct call to one of these tools. Leave hits that only guard the app's own Guzzle calls.
- **Search 4**: migrate the hits that handle the invalid-URL failure of `JinaUrlReader`, `TavilyExtractTool` or `TavilyCrawlTool`: a check of the `'Invalid URL.'` message, or `expectException(ToolException::class)` / `catch (ToolException ...)` around a direct call to one of these tools (Case 6).

If none of the searches finds a hit that this guide migrates, this guide does not apply.

## How to Refactor

### Case 1: A `getClient()` override, or an assignment to the protected `$client`, that configures the client

4.x never calls `getClient()`, so a leftover override stops applying without any error.

1. Delete the `getClient()` override or the `$client` assignment. Also delete the `Client`/`RequestOptions` imports it used.
2. Build an `HttpClientInterface` with the same settings. Use the mapping table below.
3. Pass that client as the last constructor argument. In a subclass, add a constructor that forwards the client through `parent::__construct()`, so call sites stay unchanged. Keep `parent::__construct($key, ...)` in every subclass of these tools, because the tools still take their key (Zep: and the user ID).

Before (3.x):

```php
use GuzzleHttp\Client;
use NeuronAI\Tools\Toolkits\Tavily\TavilySearchTool;

class ProxiedTavilySearchTool extends TavilySearchTool
{
    protected function getClient(): Client
    {
        return $this->client ??= new Client([
            'base_uri' => trim($this->url, '/').'/',
            'timeout' => 30,
            'connect_timeout' => 5,
            'proxy' => 'http://proxy.internal:3128',
            'headers' => [
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Team' => 'research',
            ],
        ]);
    }
}
```

After (4.x):

```php
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\Tools\Toolkits\Tavily\TavilySearchTool;

use const CURLOPT_PROXY;

class ProxiedTavilySearchTool extends TavilySearchTool
{
    public function __construct(string $key, array $topics = [])
    {
        parent::__construct($key, $topics, new CurlHttpClient(
            customHeaders: ['X-Team' => 'research'],
            timeout: 30.0,
            connectTimeout: 5.0,
            curlOptions: [CURLOPT_PROXY => 'http://proxy.internal:3128'],
        ));
    }
}
```

To give all the tools of a toolkit one client, pass it to the toolkit: `TavilyToolkit::make($key, $httpClient)`, `JinaToolkit::make($key, $httpClient)`, `ZepLongTermMemoryToolkit::make($key, $userId, $httpClient)` or `SupadataYouTubeToolkit::make($key, $httpClient)`. Only the built-in `provide()` hands it to the tools. A toolkit subclass that overrides `provide()`, or a `with()` callback that returns a new tool, must pass `$this->httpClient` as the last constructor argument of every tool it creates (for example `new TavilySearchTool($this->key, ['research'], $this->httpClient)`). Otherwise those tools silently use a default `CurlHttpClient`.

How each setting from the 3.x `new Client([...])` carries over:

| 3.x Guzzle setting | 4.x |
|--------------------|-----|
| `timeout`, `connect_timeout` | `new CurlHttpClient(timeout: 30.0, connectTimeout: 5.0)` |
| `proxy` | `curlOptions: [CURLOPT_PROXY => '...']` |
| `verify` with a CA bundle path | `curlOptions: [CURLOPT_CAINFO => '/path/ca.pem']` |
| `verify => false` | `curlOptions: [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]` |
| Extra `headers` | `customHeaders: [...]`. The headers the tool sets itself (`Authorization`, `Content-Type`, `Accept`, Jina's `X-*`) win over them. If the override changed one of those, ask the developer how to proceed. |
| `base_uri` on a Tavily or Zep tool | Declare `protected string $url = '...';` in the subclass. The property still exists and still sets where requests go. |
| `base_uri` on a Jina or Supadata tool | These tools send hardcoded absolute URLs. Ask the developer how to proceed. |
| `handler` (middleware, retries, `MockHandler`) or any other Guzzle request option (`http_errors`, ...) | `new \NeuronAI\HttpClient\Guzzle\GuzzleHttpClient(customHeaders: [...], timeout: 30.0, connectTimeout: 5.0, handler: $handlerStack, options: [...])`. Pass extra `headers`, `timeout` and `connect_timeout` as these constructor arguments, never inside `options`: `GuzzleHttpClient` overwrites those three keys. Put every other Guzzle option (`proxy`, `verify`, `http_errors`, ...) in `options` as it was. The `curlOptions` rows above apply only to `CurlHttpClient`. guzzlehttp/guzzle must then stay in the app's `composer.json` (guide 1). |

### Case 2: A test that injects a Guzzle mock through `getClient()`

Before (3.x):

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Tools\Toolkits\Tavily\TavilySearchTool;

$mock = new MockHandler([
    new Response(200, [], '{"answer":"42","results":[]}'),
]);

$tool = new class ('test-key', [], HandlerStack::create($mock)) extends TavilySearchTool {
    public function __construct(string $key, array $topics, protected HandlerStack $handler)
    {
        parent::__construct($key, $topics);
    }

    protected function getClient(): Client
    {
        return $this->client ??= new Client(['handler' => $this->handler]);
    }
};

$result = $tool('neuron php');
```

After (4.x):

```php
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Tools\Toolkits\Tavily\TavilySearchTool;

$mock = new MockHandler([
    new Response(200, [], '{"answer":"42","results":[]}'),
]);

$tool = new TavilySearchTool('test-key', httpClient: new GuzzleHttpClient(handler: HandlerStack::create($mock)));

$result = $tool('neuron php');
```

- guzzlehttp/guzzle must be in the app's `composer.json`. Guide 1 added it if the tests referenced Guzzle; `require-dev` is enough.
- A mocked 4xx or 5xx response now throws `HttpException` (Case 5).
- Zep tools send `GET users/{user_id}`, and `POST users` when that returns 404, at the start of every invocation instead of in the constructor. Queue that response before each call's own response.
- `TavilySearchTool` sends `time_range` and `days` only when the model sets them. Remove the old `'day'`/`7` defaults from any request-body assertion.

### Case 3: A subclass method that calls the Guzzle client

Replace the calls as shown below. The helpers are protected methods of the parent tool:

| 3.x call in the subclass | 4.x |
|--------------------------|-----|
| Tavily or Zep: `$this->getClient()->post('<endpoint>', [RequestOptions::JSON => $body])` | `$this->post('<endpoint>', $body)` |
| Zep: `$this->getClient()->get('<endpoint>')` | `$this->get('<endpoint>')` |
| Zep, any other verb | `$this->httpClient->request(HttpRequest::delete(trim($this->url, '/').'/<endpoint>', [], $this->headers()))` (`HttpRequest::put()` and `HttpRequest::patch()` take the same arguments) |
| Supadata: `$this->getClient($this->key)->get('<endpoint>?a='.$a)` | `$this->get('<endpoint>', ['a' => $a])` |
| Jina: `$this->getClient()->post('https://r.jina.ai/', [RequestOptions::JSON => $body])` | `$this->httpClient->request(HttpRequest::post('https://r.jina.ai/', $body, $headers))`. `$headers` must hold every header the 3.x `getClient()` set, including `'Authorization' => 'Bearer '.$this->key`. |
| `json_decode((string) $response->getBody(), true)` | `$response->json()` |
| `(string) $response->getBody()` | `$response->body` |
| `$response->getStatusCode()`, `$response->getHeaderLine('X')` | `$response->statusCode`, `$response->header('X')` |

`HttpRequest` is `NeuronAI\HttpClient\HttpRequest`.

If a subclass overrides `__invoke()` of a Zep tool, it must call `$this->createUser();` first. 4.x provisions the Zep user there, not in the constructor.

The parent classes now declare some new member names. A subclass that declares its own member with one of these names overrides the parent's helper, so rename the subclass member:
- `$httpClient` on all four toolkits' tools;
- `post()` on Tavily and Zep tools;
- `get()` on Zep and Supadata tools;
- `headers()` on Zep tools.

Before (3.x):

```php
use GuzzleHttp\RequestOptions;
use NeuronAI\Tools\Toolkits\Zep\ZepSearchGraphTool;

class ZepUserFactsTool extends ZepSearchGraphTool
{
    public function __invoke(string $query, string $search_scope = 'facts', int $limit = 5): array
    {
        $user = json_decode((string) $this->getClient()->get('users/'.$this->user_id)->getBody(), true);

        $response = $this->getClient()->post('graph/search', [
            RequestOptions::JSON => [
                'user_id' => $this->user_id,
                'query' => $query,
                'scope' => 'edges',
                'limit' => $limit,
            ],
        ]);

        return [
            'user' => $user,
            'facts' => $this->mapEdges(json_decode((string) $response->getBody(), true)['edges'] ?? []),
        ];
    }
}
```

After (4.x):

```php
use NeuronAI\Tools\Toolkits\Zep\ZepSearchGraphTool;

class ZepUserFactsTool extends ZepSearchGraphTool
{
    public function __invoke(string $query, string $search_scope = 'facts', int $limit = 5): array
    {
        $this->createUser();

        $user = $this->get('users/'.$this->user_id)->json();

        $response = $this->post('graph/search', [
            'user_id' => $this->user_id,
            'query' => $query,
            'scope' => 'edges',
            'limit' => $limit,
        ]);

        return [
            'user' => $user,
            'facts' => $this->mapEdges($response->json()['edges'] ?? []),
        ];
    }
}
```

### Case 4: Code that calls Supadata's `getClient($key)`, or an app class that uses the `Supadata\HttpClient` or `HandleZepClient` trait

**External callers.** If the call hits an endpoint that a Supadata tool already wraps, invoke the tool, for example `SupadataVideoMetadataTool::make($key)($videoId)`. Otherwise send the request with Neuron's client, or with the app's own client if it already has one:

Before (3.x):

```php
use NeuronAI\Tools\Toolkits\Supadata\SupadataVideoMetadataTool;

$response = SupadataVideoMetadataTool::make($key)
    ->getClient($key)
    ->get('youtube/video?id='.$videoId);

$metadata = json_decode((string) $response->getBody(), true);
```

After (4.x):

```php
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpRequest;

$response = (new CurlHttpClient())->request(HttpRequest::get(
    'https://api.supadata.ai/v1/youtube/video?'.http_build_query(['id' => $videoId]),
    ['x-api-key' => $key],
));

$metadata = $response->json();
```

**Classes that `use` one of the traits.** The trait now declares `protected HttpClientInterface $httpClient`. The class must assign it in its constructor. The Supadata trait reads the key from `$this->key`, so the class needs that property. A `HandleZepClient` user still needs `$key` and `$user_id`. Replace the calls as in Case 3.

Before (3.x, with the tool identity already moved to properties by guide 3):

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\Toolkits\Supadata\HttpClient;

class YouTubeSearchTool extends Tool
{
    use HttpClient;

    protected string $name = 'youtube_search';

    public function __construct(protected string $key)
    {
    }

    public function __invoke(string $query): array
    {
        $response = $this->getClient($this->key)->get('youtube/search?query='.$query);

        return json_decode((string) $response->getBody(), true);
    }
}
```

After (4.x):

```php
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\Toolkits\Supadata\HttpClient;

class YouTubeSearchTool extends Tool
{
    use HttpClient;

    protected string $name = 'youtube_search';

    public function __construct(protected string $key, ?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    public function __invoke(string $query): array
    {
        return $this->get('youtube/search', ['query' => $query])->json();
    }
}
```

### Case 5: Code that catches Guzzle exceptions from these tools

`NeuronAI\Exceptions\HttpException` covers both status errors and network errors. It exposes `public readonly ?HttpRequest $request` and `public readonly ?HttpResponse $response`:

| 3.x | 4.x |
|-----|-----|
| `GuzzleException`, `TransferException`, `RequestException` | `HttpException` |
| `BadResponseException` | `HttpException` with `$e->response !== null` |
| `ClientException` (4xx) | `HttpException` with `$e->response !== null && $e->response->statusCode < 500` |
| `ServerException` (5xx) | `HttpException` with `$e->response !== null && $e->response->statusCode >= 500` |
| `ConnectException` | `HttpException` with `$e->response === null` |
| `$e->getResponse()->getStatusCode()` | `$e->response?->statusCode` |
| `(string) $e->getResponse()->getBody()` | `$e->response?->body` |
| `$e->getResponse()->getHeaderLine('X')` | `$e->response?->header('X')` |
| `$e->getRequest()->getUri()` | `$e->request?->uri` |
| `$e->hasResponse()` | `$e->response !== null` |

Providers and Neuron's other HTTP components (embeddings providers, HTTP vector stores, rerankers) also throw `HttpException`, as they did in 3.x. A `catch` around an agent run that must react only to toolkit failures has to check `$e->request?->uri` and rethrow anything else. The toolkits' hosts are `https://api.tavily.com/`, `https://r.jina.ai/`, `https://s.jina.ai/`, `https://api.getzep.com/` and `https://api.supadata.ai/`. If the same block also guards the app's own Guzzle calls, catch both types: `catch (HttpException|ClientException $e)`.

Before (3.x):

```php
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use NeuronAI\Chat\Messages\UserMessage;

try {
    $answer = $agent->chat(new UserMessage($question))->getMessage();
} catch (ClientException $e) {
    $status = $e->getResponse()->getStatusCode();
    $body = (string) $e->getResponse()->getBody();
    report("Tavily rejected the request ({$status}): {$body}");
} catch (ConnectException $e) {
    report('Tavily is unreachable: '.$e->getMessage());
}
```

After (4.x). Guide 23 migrates the agent call itself:

```php
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;

try {
    $answer = $agent->chat(new UserMessage($question))->getMessage();
} catch (HttpException $e) {
    // Providers throw HttpException too: handle only Tavily's, as the Guzzle catches did
    if (!str_starts_with((string) $e->request?->uri, 'https://api.tavily.com/')) {
        throw $e;
    }

    if ($e->response === null) {                  // was ConnectException
        report('Tavily is unreachable: '.$e->getMessage());
    } elseif ($e->response->statusCode < 500) {   // was ClientException
        report("Tavily rejected the request ({$e->response->statusCode}): {$e->response->body}");
    } else {                                      // ServerException was not caught
        throw $e;
    }
}
```

In a `toolErrorHandler` callback or `resolveToolErrorHandler()` override, check `HttpException` where the Guzzle type was. If the agent also registers app tools that call Guzzle themselves, keep the Guzzle check too: `$e instanceof HttpException || $e instanceof RequestException`. Other tools, such as `RetrievalTool`, also raise `HttpException` into the handler: if the branch must handle only these toolkits, also test the tool name, for example `in_array($call->getName(), ['web_search', 'url_reader', 'url_crawl'], true)`. The Before below already has the callback signature from guide 4:

Before:

```php
use GuzzleHttp\Exception\RequestException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;

$agent->toolErrorHandler(function (Throwable $e, ToolCall $call): string|ToolOutput|null {
    if ($e instanceof ToolException && $e->getMessage() === 'Invalid URL.') {
        return 'The URL is not valid: ask the user for the full address.';
    }

    if ($e instanceof RequestException) {
        return ToolOutput::error("{$call->getName()} is unavailable right now, answer without it.");
    }

    return null;
});
```

After (4.x). The invalid-URL branch goes away (Case 6):

```php
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;

$agent->toolErrorHandler(function (Throwable $e, ToolCall $call): string|ToolOutput|null {
    if ($e instanceof HttpException) {
        return ToolOutput::error("{$call->getName()} is unavailable right now, answer without it.");
    }

    return null;
});
```

With `parallelToolCalls(true)`, a tool failure reaches the handler and the caller as a `ToolException` that names the original class (guide 4). An `HttpException` check does not match it.

### Case 6: Code that expects the `'Invalid URL.'` `ToolException`

`JinaUrlReader`, `TavilyExtractTool` and `TavilyCrawlTool` now return a `ToolOutput` error for an invalid or non-http(s) URL. The model receives that error as the tool result and the run continues. `TavilyExtractTool` also returns a `ToolOutput` error when Tavily cannot extract the page.

1. Delete `toolErrorHandler` branches and `catch` blocks that only handle `ToolException('Invalid URL.')` (Case 5 shows one). Keep generic `ToolException` handling, which other errors still use.
2. Code that calls `__invoke()` directly, or through `parent::__invoke()` in a subclass, must handle the new return types: `string|ToolOutput` for `JinaUrlReader`, `array|ToolOutput` for the two Tavily tools. To keep the 3.x behaviour, throw when the result is a `ToolOutput`:

```php
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\Toolkits\Jina\JinaUrlReader;
use NeuronAI\Tools\ToolOutput;

$markdown = (new JinaUrlReader($key))($url);

if ($markdown instanceof ToolOutput) {
    throw new ToolException($markdown->getText());
}
```

3. Tests:

Before (3.x):

```php
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\Toolkits\Jina\JinaUrlReader;

public function test_rejects_invalid_url(): void
{
    $this->expectException(ToolException::class);

    (new JinaUrlReader('key'))('not-a-url');
}
```

After (4.x):

```php
use NeuronAI\Tools\Toolkits\Jina\JinaUrlReader;
use NeuronAI\Tools\ToolOutput;

public function test_rejects_invalid_url(): void
{
    $result = (new JinaUrlReader('key'))('not-a-url');

    $this->assertInstanceOf(ToolOutput::class, $result);
    $this->assertTrue($result->isError());
}
```

### Case 7: Remove a guzzlehttp/guzzle requirement that is no longer used

Do this last.

1. Remove the `use GuzzleHttp\...` imports that the cases above left unused.
2. Check whether anything still references Guzzle:

```bash
grep -rnE 'GuzzleHttp\\|GuzzleHttpClient|GuzzleStream' --include='*.php' --exclude-dir=vendor .
grep -rnE 'GuzzleHttp\\' --include='*.yaml' --include='*.yml' --include='*.xml' --exclude-dir=vendor --exclude-dir=node_modules .
grep -nE '"guzzlehttp/guzzle"' composer.json
```

3. If neither of the first two commands finds anything, and guide 1 added `guzzlehttp/guzzle` during this upgrade (see guide 1's report or `git diff` of `composer.json`), remove it with `composer remove guzzlehttp/guzzle`. Add `--dev` if it is under `require-dev`.
4. Keep the requirement if it was in `composer.json` before the upgrade started, or if any code or configuration still references Guzzle (for example Case 2 mocks).

## Checklist

- [ ] No subclass of a Tavily, Jina, Zep or Supadata tool declares `getClient()` or references `$this->client` or `RequestOptions::`.
- [ ] Every custom client from a deleted `getClient()` is passed as the `$httpClient` constructor argument of the tool, its subclass or its toolkit. A toolkit subclass that overrides `provide()` forwards `$this->httpClient` to every tool it creates.
- [ ] Subclasses of these tools still call `parent::__construct($key, ...)` (Zep: with `$user_id`).
- [ ] Zep subclasses that override `__invoke()` call `$this->createUser()` first.
- [ ] Classes that `use` `Supadata\HttpClient` or `HandleZepClient` assign `$this->httpClient` in their constructor and have a `$key` property.
- [ ] No code calls `getClient($key)` on a Supadata tool.
- [ ] Every `catch`/`instanceof` on a `GuzzleHttp\Exception` type around runs of agents that use these tools, around direct calls to them, or in those agents' `toolErrorHandler`/`resolveToolErrorHandler()` now matches `HttpException`. A Guzzle type is kept only where the same code also guards the app's own Guzzle calls. `HttpException` checks that must ignore other failures test the request host (around runs) or the tool name (in handlers).
- [ ] No code handles `ToolException('Invalid URL.')`. Direct callers of `JinaUrlReader`, `TavilyExtractTool` and `TavilyCrawlTool` handle a `ToolOutput` result.
- [ ] Tests that injected Guzzle mocks pass the mock through `GuzzleHttpClient(handler: ...)`.
- [ ] `guzzlehttp/guzzle` is required only if code still references Guzzle.
- [ ] Searches 1 to 4 find no remaining hit that this guide migrates.
