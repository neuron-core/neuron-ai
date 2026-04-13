# Testing Module

Test fakes and utilities for testing Neuron applications.

## Fakes

| Class | Purpose |
|-------|---------|
| `FakeAIProvider` | Mock AI responses, record calls |
| `FakeEmbeddingsProvider` | Mock embeddings |
| `FakeVectorStore` | Mock vector store |
| `FakeMiddleware` | Track middleware execution |
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

## Dependencies

- `Providers` module (implements interfaces)
