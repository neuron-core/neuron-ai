# Upgrade: MCP client and transport contract

## Summary

This guide covers application code that talks to `NeuronAI\MCP\McpClient` directly, implements
`McpTransportInterface`, extends a Neuron MCP transport or `McpClient`, inspects an `McpException` raised by an HTTP
MCP server, or connects to an HTTP MCP server whose URL redirects.

| 3.x | 4.x |
|---|---|
| `McpClient::callTool()` returned the whole JSON-RPC response, including `['error' => [...]]` when the server refused the call | Throws `McpException`: `getMessage()` is the server's `error.message`, `getCode()` its `error.code`. The returned array always holds `result`. `listTools()` and the initialize handshake in `new McpClient()` throw the same way |
| `McpTransportInterface`: `connect()`, `send()`, `receive()`, `disconnect()` | Also `setProtocolVersion(string $version): void` |
| The client read one message per request and threw `Invalid response ID` on any other message | The client calls `receive()` until the response with its request ID arrives, skipping notifications, server requests and other IDs |
| `StreamableHttpTransport` / `SseHttpTransport`: `protected readonly GuzzleHttp\Client $httpClient`, `__construct(array $config)` | `protected readonly NeuronAI\HttpClient\HttpClientInterface $httpClient`, `__construct(array $config, ?HttpClientInterface $httpClient = null)`. The default client is `CurlHttpClient` |
| `StreamableHttpTransport::$lastResponse`: `?Psr\Http\Message\ResponseInterface` | `?NeuronAI\HttpClient\HttpResponse` |
| `StreamableHttpTransport::parseSSEResponse(string): string` (first `data:` line) | `parseSSEResponse(string): array` (one JSON payload per event, in order) |
| `new McpClient($config)`, `new McpConnector($config)` | Both take an optional `?HttpClientInterface $httpClient` second argument, handed to the HTTP transports |
| `McpException` from an HTTP failure: code = HTTP status, previous = Guzzle exception | Code `0`, previous = `NeuronAI\Exceptions\HttpException` (status on `->response?->statusCode`) |
| MCP URLs that redirect were followed | SSE (`'async' => true`): no redirect is followed. Streamable HTTP: only redirects to the same origin |

MCP clients and transports store nothing, so no stored data needs migrating.

Other guides cover related MCP changes. Leave these to them:
- stdio `command` and `env`: guides 47 and 48;
- MCP tool results: guide 49;
- `McpConnector` tools, subclasses and constructor: guide 50;
- tool name collisions: guide 11;
- `FakeMcpTransport`: guide 56;
- Neuron's HTTP client classes: guide 2.

## What to Search For

Run from the application root:

```bash
# 1. Direct use of McpClient
grep -rnE 'McpClient\b|->(callTool|listTools)\(' --include='*.php' --exclude-dir=vendor .

# 2. Custom transports, and subclasses of the MCP client and transports
grep -rnE 'implements .*McpTransportInterface|extends +[A-Za-z\\]*(McpClient|StdioTransport|StreamableHttpTransport|SseHttpTransport)\b' --include='*.php' --exclude-dir=vendor .

# 3. Code that handles McpException
grep -rn 'McpException' --include='*.php' --exclude-dir=vendor .

# 4. Places that configure MCP servers
grep -rnE 'McpConnector|StreamableHttpTransport|SseHttpTransport' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:
- **Search 1**: keep only `NeuronAI\MCP\McpClient` instances: `new McpClient(...)`, values typed `McpClient`, and
  `callTool()`/`listTools()` calls on them (Case 1).
- **Search 2**: `implements` hits go to Case 2. Subclasses of `StreamableHttpTransport` or `SseHttpTransport` go to
  Case 3. Every subclass also goes to Case 4. Also find subclasses of these subclasses, and classes that extend an
  alias (`use NeuronAI\MCP\StreamableHttpTransport as BaseTransport;`, which search 4 shows).
- **Search 3**: open each `catch (McpException ...)`, `instanceof McpException` and exception-handler hit, and look
  for `getCode()`, `getPrevious()` or checks on the message text (Case 5).
- **Search 4**: collect every config with a `url` key that reaches `McpConnector`, `McpClient` or an HTTP transport
  (Case 6). Follow a `url` read from `env()`, a config file or a database row to its source.

If none of the searches finds a hit that this guide migrates, this guide does not apply.

## How to Refactor

### Case 1: Code that reads a JSON-RPC error from `McpClient`

`new McpClient($config)` still connects and runs the initialize handshake in its constructor.

Before (3.x):

```php
use NeuronAI\MCP\McpClient;

$client = new McpClient(['url' => 'https://mcp.example.com/mcp', 'token' => $token]);

$response = $client->callTool('search_docs', ['query' => $query]);

if (isset($response['error'])) {
    $logger->warning('MCP search failed', [
        'code' => $response['error']['code'],
        'message' => $response['error']['message'],
    ]);

    return [];
}

return $response['result']['content'];
```

After (4.x):

```php
use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpException;

$client = new McpClient(['url' => 'https://mcp.example.com/mcp', 'token' => $token]);

try {
    $response = $client->callTool('search_docs', ['query' => $query]);
} catch (McpException $e) {
    $logger->warning('MCP search failed', [
        'code' => $e->getCode(),
        'message' => $e->getMessage(),
    ]);

    return [];
}

return $response['result']['content'];
```

1. Replace every `['error']` check on a `callTool()` result with a `try`/`catch (McpException $e)` around the call.
   This includes `McpClient` subclasses that check the result of `parent::callTool()`.
2. Put only the `callTool()` call inside the `try`. The `catch` also receives transport failures: HTTP errors,
   timeouts, a stopped stdio server. 3.x threw those as `McpException` too, past the `if`. A JSON-RPC error has no
   previous exception and carries the server's code. If the old error branch recovers (returns a fallback, retries,
   tells the user), ask the developer whether transport failures should now take that branch too.

### Case 2: Classes that implement `McpTransportInterface`

This includes test doubles written by the application. In the example, `WebSocketConnection` stands for the
application's own connection class.

Before (3.x):

```php
namespace App\Mcp;

use NeuronAI\MCP\McpTransportInterface;

class WebSocketTransport implements McpTransportInterface
{
    public function __construct(protected WebSocketConnection $connection, protected float $timeout = 30.0)
    {
    }

    public function connect(): void
    {
        $this->connection->open();
    }

    public function send(array $data): void
    {
        $this->connection->write(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function receive(): array
    {
        $frame = $this->connection->read($this->timeout); // null on timeout

        return $frame === null ? [] : json_decode($frame, true, 512, JSON_THROW_ON_ERROR);
    }

    public function disconnect(): void
    {
        $this->connection->close();
    }
}
```

After (4.x):

```php
namespace App\Mcp;

use NeuronAI\MCP\McpException;
use NeuronAI\MCP\McpTransportInterface;

class WebSocketTransport implements McpTransportInterface
{
    public function __construct(protected WebSocketConnection $connection, protected float $timeout = 30.0)
    {
    }

    public function connect(): void
    {
        $this->connection->open();
    }

    public function send(array $data): void
    {
        $this->connection->write(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function receive(): array
    {
        $frame = $this->connection->read($this->timeout); // null on timeout

        if ($frame === null) {
            throw new McpException('Timeout waiting for a message from the MCP server');
        }

        return json_decode($frame, true, 512, JSON_THROW_ON_ERROR);
    }

    public function setProtocolVersion(string $version): void
    {
    }

    public function disconnect(): void
    {
        $this->connection->close();
    }
}
```

1. Add `public function setProtocolVersion(string $version): void`. The client calls it once, after the initialize
   handshake, with the protocol version the server chose. An empty body is enough. A transport that speaks HTTP
   should store the version and send it as the `MCP-Protocol-Version` header on later requests, as
   `StreamableHttpTransport` does.
2. Make `receive()` return exactly one complete JSON-RPC message per call. It must wait until a message arrives, and
   throw `McpException` on timeout or when the connection closes. It must never return `[]` or a partial message.
   The client calls `receive()` again until its response arrives, so a transport that returns `[]` makes the client
   loop forever. In 3.x the same transport failed with `McpException: Invalid response ID`.
3. Tests that assert the sent `initialize` request need new expected values: `params.protocolVersion` is now
   `'2025-11-25'` (was `'2024-11-05'`), and `params.capabilities` is an empty object (was `{"sampling": {}}`).

### Case 3: Subclasses of `StreamableHttpTransport` or `SseHttpTransport`

First decide what the subclass is for.

**The subclass only tunes HTTP** (proxy, TLS, timeouts, extra headers): delete it. Put the settings in the MCP
config, or pass a configured client to `McpConnector`. Pass it to `McpClient` instead when the app uses the client
directly.

Before (3.x):

```php
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\StreamableHttpTransport;

class ProxiedMcpTransport extends StreamableHttpTransport
{
    protected Client $proxiedClient;

    public function __construct(array $config)
    {
        parent::__construct($config);

        $this->proxiedClient = new Client([
            'timeout' => $config['timeout'] ?? 30,
            'connect_timeout' => 5,
            'proxy' => 'http://proxy.internal:3128',
            'verify' => '/etc/ssl/certs/corp-ca.pem',
            'headers' => ['Accept' => 'application/json, text/event-stream'],
        ]);
    }

    public function send(array $data): void
    {
        $headers = array_merge($this->getAuthHeaders(), ['Content-Type' => 'application/json']);

        if ($this->sessionId !== null) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        try {
            $response = $this->proxiedClient->send(
                new Request('POST', $this->config['url'], $headers, json_encode($data, JSON_THROW_ON_ERROR))
            );
        } catch (GuzzleException $e) {
            throw new McpException('HTTP request failed: ' . $e->getMessage(), $e->getCode(), $e);
        }

        if ($response->hasHeader('Mcp-Session-Id')) {
            $this->sessionId = $response->getHeader('Mcp-Session-Id')[0];
        }

        $this->lastResponse = $response;
    }
}

$config = ['url' => 'https://mcp.example.com/mcp', 'token' => $token, 'timeout' => 60];

$tools = McpConnector::make(['transport' => new ProxiedMcpTransport($config)])->tools();
```

After (4.x), with the class deleted:

```php
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\MCP\McpConnector;

$config = ['url' => 'https://mcp.example.com/mcp', 'token' => $token, 'timeout' => 60];

$httpClient = new CurlHttpClient(
    connectTimeout: 5.0,
    curlOptions: [
        CURLOPT_PROXY => 'http://proxy.internal:3128',
        CURLOPT_CAINFO => '/etc/ssl/certs/corp-ca.pem',
    ],
);

$tools = (new McpConnector($config, $httpClient))->tools();
```

Use `new McpConnector($config, $httpClient)`, not `McpConnector::make($config, $httpClient)`: `make()` accepts the
second argument at runtime, but static analysis rejects it. `new McpClient($config, $httpClient)` and
`'transport' => new StreamableHttpTransport($config, $httpClient)` take the client the same way.

How each 3.x Guzzle setting carries over:

| 3.x Guzzle setting | 4.x |
|---|---|
| `timeout` | Config `'timeout' => 60`. The transports send it with every request, and it overrides the client's own timeout |
| `connect_timeout` | `new CurlHttpClient(connectTimeout: 5.0)` |
| `headers` | Config `'headers' => [...]`, or `'token' => '...'` for `Authorization: Bearer`. Drop `Accept` and `Content-Type`: the transports send the values MCP requires |
| `proxy` | `curlOptions: [CURLOPT_PROXY => '...']` |
| `verify` with a CA bundle path | `curlOptions: [CURLOPT_CAINFO => '/path/ca.pem']` |
| `verify => false` | `curlOptions: [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]`. For SSE without an injected client, config `'verify' => false` does the same |
| `handler` (middleware, retries, `MockHandler`), any other Guzzle option | `new \NeuronAI\HttpClient\Guzzle\GuzzleHttpClient(handler: $handlerStack, options: [...])`. `guzzlehttp/guzzle` then stays in `composer.json` |

For SSE (`'async' => true`), the client sends only the POST requests. The event stream itself (the GET) is opened by
PHP's stream wrapper, as in 3.x, and uses only the `url`, `headers`, `token`, `timeout` and `verify` config keys. A
proxy or CA bundle set on the client does not reach it. When a client is injected, `'verify' => false` covers only
the event stream, so set the curl options on the client too.

**The subclass has its own logic**: keep it and translate what it does with the HTTP types:

| 3.x in the subclass | 4.x |
|---|---|
| `$this->httpClient->send(new Request('POST', $url, $headers, $body))`, `$this->httpClient->post($url, ['headers' => $headers, 'body' => $body])` | `$this->httpClient->request(new HttpRequest(method: HttpMethod::POST, uri: $url, headers: $headers, body: $body, timeout: (float) ($this->config['timeout'] ?? 30)))` |
| `catch (GuzzleException $e)` | `catch (HttpException $e)`: thrown for statuses >= 400 and for network errors; the status is `$e->response?->statusCode` |
| `$response->getStatusCode()`, `$this->lastResponse->getStatusCode()` | `->statusCode` |
| `(string) $response->getBody()` | `->body` |
| `hasHeader('X')`, `getHeader('X')[0]`, `getHeaderLine('X')` | `->header('X')`, which returns `null` when the header is absent |
| `protected function parseSSEResponse(string $sseResponse): string` | `: array`, returning the JSON payload of every event in order. The parent decodes them and `receive()` returns them one at a time. Callers in the subclass get the array |
| Constructor that builds a Guzzle client | Build no client. Accept a `?HttpClientInterface $httpClient = null` and pass it to `parent::__construct($config, $httpClient)` |

The imports are `NeuronAI\HttpClient\HttpRequest`, `NeuronAI\HttpClient\HttpMethod` and
`NeuronAI\Exceptions\HttpException`.

In 3.x, `StreamableHttpTransport`'s Guzzle client added `Accept: application/json, text/event-stream` to every
request. The 4.x client does not. A `StreamableHttpTransport` subclass that sends its own POST must set that `Accept`
header, and also send `'MCP-Protocol-Version' => $this->protocolVersion` when that is not `null`.
`getAuthHeaders()`, `$sessionId`, `$config` and SSE's `$postEndpointUrl` are unchanged. When the extra headers do
not depend on the request body, override `getAuthHeaders()` and delete the `send()` override.

Before (3.x):

```php
use NeuronAI\MCP\StreamableHttpTransport;
use Psr\Log\LoggerInterface;

class LoggingMcpTransport extends StreamableHttpTransport
{
    public function __construct(array $config, protected LoggerInterface $logger)
    {
        parent::__construct($config);
    }

    public function receive(): array
    {
        if ($this->lastResponse !== null) {
            $this->logger->debug('MCP response', [
                'status' => $this->lastResponse->getStatusCode(),
                'session' => $this->lastResponse->getHeaderLine('Mcp-Session-Id'),
                'body' => (string) $this->lastResponse->getBody(),
            ]);
        }

        return parent::receive();
    }
}
```

After (4.x):

```php
use NeuronAI\MCP\StreamableHttpTransport;
use Psr\Log\LoggerInterface;

class LoggingMcpTransport extends StreamableHttpTransport
{
    public function __construct(array $config, protected LoggerInterface $logger)
    {
        parent::__construct($config);
    }

    public function receive(): array
    {
        if ($this->lastResponse !== null) {
            $this->logger->debug('MCP response', [
                'status' => $this->lastResponse->statusCode,
                'session' => $this->lastResponse->header('Mcp-Session-Id') ?? '',
                'body' => $this->lastResponse->body,
            ]);
        }

        return parent::receive();
    }
}
```

A `receive()` override that copied the 3.x body (decoding `$lastResponse` itself) should be deleted: the 4.x parent
handles SSE-framed responses with several events.

### Case 4: A subclass declares a member that 4.x now declares

The 4.x classes declare these new members. A subclass member with the same name now overrides or redeclares the
parent's. If the subclass declares it `private` or with an incompatible signature, PHP refuses to load the class.
Otherwise it silently replaces the parent's member. Rename the subclass's member and its uses.

| Class | New member names |
|---|---|
| `McpClient` | `PROTOCOL_VERSION`, `$config`, `$httpClient`, `$transport`, `$requestId`, `$sessionProcessId`, `$inheritedTransports`, `createTransport()`, `openSession()`, `request()`, `leaveInheritedSession()`, `exchange()` (`$transport` and `$requestId` were `private` in 3.x) |
| `StdioTransport` | `INHERITED_ENV`, `EXIT_GRACE_SECONDS`, `SIGKILL`, `$buffer`, `inheritedEnv()`, `discardStderr()`, `exitsWithin()`, `setProtocolVersion()` |
| `StreamableHttpTransport` | `$protocolVersion`, `$pendingMessages`, `decodeMessages()`, `decodeEvents()`, `setProtocolVersion()` |
| `SseHttpTransport` | `nextBufferedEvent()`, `readIntoBuffer()`, `setProtocolVersion()` |

### Case 5: Code that reads the status or cause of an `McpException`

This applies to `McpException`s raised by the HTTP transports: from `McpConnector::tools()`, from an MCP tool during
an agent run, or from `McpClient`. With `parallelToolCalls(true)`, a tool's exception reaches callers wrapped in a
`ToolException` (guide 4).

Before (3.x):

```php
use GuzzleHttp\Exception\ConnectException;
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\McpException;

try {
    return McpConnector::make(['url' => 'https://crm.example.com/mcp', 'token' => $tokens->get()])->tools();
} catch (McpException $e) {
    if ($e->getCode() === 401) {
        return McpConnector::make(['url' => 'https://crm.example.com/mcp', 'token' => $tokens->refresh()])->tools();
    }

    if ($e->getPrevious() instanceof ConnectException) {
        return [];
    }

    throw $e;
}
```

After (4.x):

```php
use NeuronAI\Exceptions\HttpException;
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\McpException;

try {
    return McpConnector::make(['url' => 'https://crm.example.com/mcp', 'token' => $tokens->get()])->tools();
} catch (McpException $e) {
    $previous = $e->getPrevious();

    if ($previous instanceof HttpException && $previous->response?->statusCode === 401) {
        return McpConnector::make(['url' => 'https://crm.example.com/mcp', 'token' => $tokens->refresh()])->tools();
    }

    if ($previous instanceof HttpException && $previous->response === null) {
        return [];
    }

    throw $e;
}
```

With `$previous = $e->getPrevious()`:

| 3.x | 4.x |
|---|---|
| `$e->getCode()` as the HTTP status | `$previous instanceof HttpException ? $previous->response?->statusCode : null` (`$e->getCode()` is now `0`) |
| `$e->getPrevious() instanceof ClientException` / `ServerException` / `BadResponseException` | `$previous instanceof HttpException && $previous->response !== null`, then compare `$previous->response->statusCode` with 500 |
| `$e->getPrevious() instanceof ConnectException` | `$previous instanceof HttpException && $previous->response === null` |
| `(string) $e->getPrevious()->getResponse()->getBody()` | `$previous->response?->body` |
| Message text `HTTP request failed: Client error: ... 401 Unauthorized ...` (Streamable HTTP) | `Authentication failed: Invalid or expired token` |
| Message text `... 403 Forbidden ...` (Streamable HTTP) | `Authorization failed: Insufficient permissions` |
| Other status messages, `HTTP request failed: Server error: ...` | `HTTP request failed: HTTP 500 error during POST <url>: <body>`. SSE POST failures start with `HTTP POST failed: ` |

Remove the Guzzle exception imports these checks leave unused.

### Case 6: An HTTP MCP server URL that redirects

3.x followed redirects. 4.x fails:
- SSE configs (`'async' => true`) follow no redirect:
  `McpException: SSE connection failed: HTTP/1.1 301 Moved Permanently`.
- Streamable HTTP configs follow a redirect only within the same origin (scheme, host and port), so `http://` to
  `https://` is refused: `McpException: HTTP request failed: Network error during POST <url>: refused a redirect to
  another origin: <target>`.

Before (3.x), with servers that redirect to `https://docs.example.com/mcp` and `https://legacy.example.com/sse/`:

```php
use NeuronAI\MCP\McpConnector;

$docs = McpConnector::make(['url' => 'http://docs.example.com/mcp'])->tools();
$legacy = McpConnector::make(['url' => 'https://legacy.example.com/sse', 'async' => true])->tools();
```

After (4.x):

```php
use NeuronAI\MCP\McpConnector;

$docs = McpConnector::make(['url' => 'https://docs.example.com/mcp'])->tools();
$legacy = McpConnector::make(['url' => 'https://legacy.example.com/sse/', 'async' => true])->tools();
```

1. For each HTTP MCP config from search 4, check the URL with `curl -sI '<url>' | grep -iE '^(HTTP|location)'`, or
   call `->tools()` once. A 3xx status with a `Location` header means the URL redirects.
2. Set `'url'` to the final address. For Streamable HTTP, the refused target is at the end of the error message.
3. When the URL comes from the environment or deployed configuration, update the committed files
   (`.env.example`, config files) and report the change to the developer. Deployed values need it too.
4. When the server cannot be reached from where you work, list its URL in the report and ask the developer to
   confirm that it is the final address.

### Case 7: Remove HTTP packages that are no longer used

Do this last, and only if Cases 3 or 5 removed Guzzle or PSR-7 code.

1. Remove the `use GuzzleHttp\...` and `use Psr\Http\Message\...` imports the cases above left unused.
2. Check what the application still references and requires:

   ```bash
   grep -rnE 'GuzzleHttp\\|GuzzleHttpClient|GuzzleStream|Psr\\Http\\(Message|Client)\\' --include='*.php' --exclude-dir=vendor .
   grep -rnE 'GuzzleHttp\\' --include='*.yaml' --include='*.yml' --include='*.xml' --exclude-dir=vendor --exclude-dir=node_modules .
   grep -nE '"(guzzlehttp/(guzzle|psr7|promises)|psr/http-(message|client|factory))"' composer.json
   ```

3. Remove a package with `composer remove <package>` only when both conditions hold:
   - guide 1 added it during this upgrade (see guide 1's report or `git diff` of `composer.json`);
   - no remaining reference maps to it (guide 1, Case 1, lists which namespace belongs to which package).
   Add `--dev` if the package is under `require-dev`.
4. Keep every package that was in `composer.json` before the upgrade started.

## Checklist

- No code reads `['error']` from a `callTool()` result; `McpException` is caught around the call where the app
  handled the error before.
- Every class that implements `McpTransportInterface` has `setProtocolVersion(string $version): void`, and its
  `receive()` throws `McpException` instead of returning `[]`.
- No subclass of `StreamableHttpTransport` or `SseHttpTransport` calls Guzzle methods on `$this->httpClient`, reads
  PSR-7 methods on `$lastResponse`, or declares `parseSSEResponse()` with a `string` return type.
- HTTP-only subclasses are deleted, and their settings live in the MCP config or in the client passed to
  `new McpConnector($config, $httpClient)` / `new McpClient($config, $httpClient)`.
- No subclass of `McpClient` or a Neuron transport declares a member listed in Case 4.
- No status check on an `McpException` relies on `getCode()` or on a Guzzle exception type.
- Every HTTP MCP `url` is the final address, with no redirect; URLs that could not be checked are listed in the
  report.
- `guzzlehttp/guzzle` and the PSR HTTP packages are required only if code still references them.
- The application's static analysis reports no error on `NeuronAI\MCP` symbols. Where the servers are reachable,
  each connector's `->tools()` returns without an `McpException`.
