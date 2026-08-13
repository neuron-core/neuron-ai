# HttpClient Module

Framework-agnostic HTTP abstraction for AI providers, vector stores, toolkits, and MCP transports.
`HttpClientInterface` is the swappability seam: any implementation can be injected anywhere the
framework talks HTTP. There is no PSR-18 layer underneath — PSR-18 cannot express the streaming
contract (`stream()` must deliver bytes as they arrive), so implementations adapt concrete
clients directly.

## Interface

```php
interface HttpClientInterface {
    public function request(HttpRequest $request): HttpResponse;
    public function stream(HttpRequest $request): StreamInterface;
    public function withBaseUri(string $baseUri): HttpClientInterface;
    public function withHeaders(array $headers): HttpClientInterface;
    public function withTimeout(float $timeout): HttpClientInterface;
}
```

Both `request()` and `stream()` throw `HttpException` on failure: with the `HttpResponse`
attached for status >= 400 (checked at header arrival on `stream()`), without one for
network errors.

## Implementations

| Class | Mode | Dependency |
|-------|------|------------|
| `CurlHttpClient` | Sync | ext-curl only — **the default everywhere** |
| `GuzzleHttpClient` | Sync | guzzlehttp/guzzle (require-dev + suggest) — opt-in, e.g. for HandlerStack middleware |
| `AmpHttpClient` | Async | amphp/http-client (require-dev) |

`CurlHttpClient` drives `stream()` through a curl multi handle (`CurlStream`), so SSE chunks
surface incrementally — live streaming is guaranteed, not client-dependent. It always suppresses
`Expect: 100-continue` (some gateways, e.g. Google Vertex, reject it). The `curlOptions`
constructor argument is the raw `CURLOPT_*` escape hatch (proxies, custom CA bundles, ...) and
wins over the built-in defaults.

### Request/response hooks

`CurlHttpClient` offers taps — deliberately not middleware:

```php
$client = (new CurlHttpClient())
    ->onRequest(fn (HttpRequest $r): HttpRequest => $r->withHeaders(['Authorization' => 'Bearer '.$token()]))
    ->onResponse(fn (HttpResponse $res, HttpRequest $req) => $logger->debug("{$req->uri} → {$res->statusCode}"));
```

`onRequest` hooks run in registration order and may return a modified request (dynamic auth,
signing, logging). `onResponse` hooks are observation-only and also fire for error responses
before the `HttpException` is thrown; on `stream()` they receive the response with an empty
body (the body is a live stream), except on error where it is drained. There is intentionally
no retry/caching/short-circuit power here — wrap the client behind `HttpClientInterface` (or
inject `GuzzleHttpClient` with a HandlerStack) for that.

## Request/Response

| Class | Purpose |
|-------|---------|
| `HttpRequest` | Request value object (method, uri, headers, body) with `::get()`/`::post()`/... factories |
| `HttpResponse` | Response container (statusCode, body, headers) with `json()` and case-insensitive `header()` helpers |
| `HttpMethod` | Enum: GET, POST, PUT, DELETE, PATCH |

Array bodies are JSON-encoded; an array body containing resources (or `['contents' => ...]`
parts) is sent as multipart, with upload filenames derived from the underlying file so APIs
that infer format from the extension keep working. String bodies pass through raw.

## Streaming

`StreamInterface` for SSE/streaming responses (pull-based: `eof()`, `read()`, `readLine()`,
`close()`):

```php
$stream = $client->stream($request);
while (!$stream->eof()) {
    $line = $stream->readLine();
}
```

## Dependency Injection

Use `HasHttpClient` trait:

```php
class MyProvider {
    use HasHttpClient;

    public function __construct() {
        $this->setHttpClient(new CurlHttpClient());
    }
}
```

## Testing

`tests/HttpClient/CurlHttpClientTest.php` boots PHP's built-in server
(`tests/HttpClient/fixtures/server.php`) and exercises the real curl stack, including the
assertion that SSE chunks arrive incrementally. Provider tests fake HTTP in-process through
`GuzzleHttpClient` + Guzzle's `MockHandler` — they test provider logic through the seam, which
is implementation-agnostic, and keep the Guzzle adapter exercised.

## Dependencies

ext-curl. Self-contained otherwise.
