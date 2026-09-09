# Testing Module

Fakes shipped with the framework so applications, and our own test suite, can exercise real wiring without network or infrastructure. Every fake implements the corresponding framework contract (`AIProviderInterface`, `EmbeddingsProviderInterface`, `VectorStoreInterface`, `WorkflowMiddleware`, `McpTransportInterface`, `StreamingChannelInterface`), so it plugs into the same seam a production implementation would and nothing in the system under test is mocked away.

## Pattern: queue responses, record calls, assert

A fake is pre-loaded with the responses it should return and records everything it receives (`RequestRecord`, `MiddlewareRecord`); assertions read the recording.

```php
$provider = new FakeAIProvider(new AssistantMessage('Hello!'));

Agent::make()->setAiProvider($provider)->chat(new UserMessage('Hi'));

$provider->assertCallCount(1);
$provider->assertSent(fn (RequestRecord $request): bool => $request->messages[0]->getContent() === 'Hi');
```

`FakeMcpTransport` applies the same shape at the JSON-RPC level: queue responses, then `assertMethodSent('initialize')`, `assertToolCalled('search')`, and so on. `FakeChannel` records deliveries (`sent`, `lines`, suspended/completed/failed states) and its `throwOnSend` exercises the workflow's failure policy.

## Generator gotcha

`FakeAIProvider::stream()` must record the call and consume the queued response **eagerly**, then delegate chunk emission to a separate `streamChunks()` generator: a generator body does not run until it is iterated, so side effects placed inside it would never happen for a caller that only checks the recording. Keep that split when touching stream fakes.
