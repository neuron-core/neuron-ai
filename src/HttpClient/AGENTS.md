# HttpClient Module

Framework-agnostic HTTP abstraction for AI providers and vector stores.

## Interface

```php
interface HttpClientInterface {
    public function send(HttpRequest $request): HttpResponse;
    public function stream(HttpRequest $request): StreamInterface;
}
```

## Implementations

| Class | Mode | Library |
|-------|------|---------|
| `GuzzleHttpClient` | Sync | Guzzle |
| `AmpHttpClient` | Async | Amp |

## Request/Response

| Class | Purpose |
|-------|---------|
| `HttpRequest` | Request builder (method, url, headers, body) |
| `HttpResponse` | Response container (status, headers, body) |
| `HttpMethod` | Enum: GET, POST, PUT, DELETE, PATCH |

## Streaming

`StreamInterface` for SSE/streaming responses:

```php
$stream = $client->stream($request);
foreach ($stream as $chunk) {
    // Process chunk
}
```

## Dependency Injection

Use `HasHttpClient` trait:

```php
class MyProvider {
    use HasHttpClient;

    public function __construct() {
        $this->setHttpClient(new GuzzleHttpClient());
    }
}
```

## Dependencies

None. Self-contained.
