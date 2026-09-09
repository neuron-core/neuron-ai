# HttpClient Module

Framework-agnostic HTTP abstraction used by providers, vector stores, toolkits and MCP transports. `HttpClientInterface` is the swappability seam: any implementation can be injected anywhere the framework talks HTTP, through the `HasHttpClient` trait. ext-curl is the only dependency.

## Design decisions

- **No PSR-18 underneath.** PSR-18 cannot express the streaming contract (`stream()` must deliver bytes as they arrive), so implementations adapt concrete clients directly. `StreamInterface` is pull-based (`eof()`, `read()`, `readLine()`, `close()`), which is exactly what SSE parsing needs.
- **`CurlHttpClient` is the default everywhere** because it needs ext-curl only. It drives `stream()` through a curl multi handle (`CurlStream`) so SSE chunks surface incrementally: live streaming is guaranteed by the framework, not dependent on the client the user picked. `GuzzleHttpClient` (HandlerStack middleware) and `AmpHttpClient` (async) are opt-in adapters. The `curlOptions` constructor argument is the raw `CURLOPT_*` escape hatch (proxies, CA bundles) and wins over the built-in defaults.
- **Hooks are taps, not middleware.** `onRequest` hooks run in registration order and may return a modified request (dynamic auth, signing, logging); `onResponse` hooks are observation-only and also fire for error responses before the exception is thrown. There is intentionally no retry, caching or short-circuit power here: wrap the client behind the interface, or inject Guzzle with a HandlerStack, for that.

```php
$client = (new CurlHttpClient())
    ->onRequest(fn (HttpRequest $request): HttpRequest => $request->withHeaders(['Authorization' => 'Bearer '.$token()]))
    ->onResponse(fn (HttpResponse $response, HttpRequest $request) => $logger->debug("{$request->uri} → {$response->statusCode}"));
```

- **Errors are exceptions.** Both `request()` and `stream()` throw `HttpException`: with the `HttpResponse` attached for status >= 400 (checked at header arrival on `stream()`), without one for network errors.
- **Body encoding is inferred from shape.** An `HttpRequest` array body is JSON-encoded; an array body containing resources (or `['contents' => ...]` parts) is sent as multipart with filenames derived from the underlying file, so APIs that infer format from the extension keep working. String bodies pass through raw.
- Every client sends `User-Agent: neuron-ai/4.x` (`HttpClientInterface::USER_AGENT`) unless the caller supplies its own, and curl always suppresses `Expect: 100-continue` because some gateways (Google Vertex) reject it.

## Testing

The curl and amp tests boot PHP's built-in server (`tests/HttpClient/fixtures/server.php`) and exercise the real stack, including the assertion that SSE chunks arrive incrementally. Provider tests fake HTTP in-process through `GuzzleHttpClient` + Guzzle's `MockHandler`: they test provider logic through the seam, which is implementation-agnostic, and keep the Guzzle adapter exercised.
