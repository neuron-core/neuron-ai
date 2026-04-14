# Testing Module

Test fakes and utilities for testing Neuron applications.

## Fakes

| Class | Purpose |
|-------|---------|
| `FakeAIProvider` | Mock AI responses, record calls |
| `FakeEmbeddingsProvider` | Mock embeddings |
| `FakeVectorStore` | Mock vector store |
| `FakeMiddleware` | Track middleware execution |
| `FakeMcpTransport` | Mock MCP transport, record send/receive |
| `FakeMessageMapper` | Mock message mapping |
| `FakeToolMapper` | Mock tool mapping |

## FakeAIProvider Usage

```php
$provider = new FakeAIProvider();
$provider->addResponse(new AssistantMessage('Hello!'));

$agent = Agent::make()->withProvider($provider);
$response = $agent->chat(new UserMessage('Hi'));

// Verify calls
$provider->assertCalled();
$provider->assertCalledTimes(1);
```

**PHP Generator Gotcha**: `FakeAIProvider::stream()` uses separate `streamChunks()` method to ensure side effects execute. See project memory for details.

## Record Types

| Class | Purpose |
|-------|---------|
| `RequestRecord` | Captured request details |
| `MiddlewareRecord` | Captured middleware execution |

## FakeMcpTransport Usage

Test double for `McpTransportInterface`. Queue predetermined responses, then assert what was sent/received.

```php
$transport = new FakeMcpTransport(
    ['result' => ['tools' => []]],
    ['result' => ['content' => 'Hello']],
);

$transport->connect();
$transport->send(['method' => 'initialize', 'params' => []]);
$response = $transport->receive(); // first queued response

$transport->assertConnected();
$transport->assertMethodSent('initialize');
$transport->assertInitialized(); // checks initialize + notifications/initialized
$transport->assertToolsListCalled();
$transport->assertToolCalled('search');
```

## Dependencies

- `Providers` module (implements interfaces)
- `MCP` module (`FakeMcpTransport` implements `McpTransportInterface`)
