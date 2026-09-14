# Testing Module

Fakes shipped with the framework so applications, and our own test suite, can exercise real wiring without network or infrastructure. Every fake implements the corresponding framework contract (`AIProviderInterface`, `EmbeddingsProviderInterface`, `VectorStoreInterface`, `WorkflowMiddleware`, `McpTransportInterface`, `StreamingChannelInterface`), so it plugs into the same seam a production implementation would and nothing in the system under test is mocked away.

## Pattern: queue responses, record calls, assert

A fake is pre-loaded with the responses it should return and records everything it receives in typed records (`RequestRecord`, `MiddlewareRecord`, `VectorStoreRecord`, `ChannelRecord`); assertions read the recording.

```php
$provider = new FakeAIProvider(new AssistantMessage('Hello!'));

Agent::make()->setAiProvider($provider)->chat(new UserMessage('Hi'));

$provider->assertCallCount(1);
$provider->assertSent(fn (RequestRecord $request): bool => $request->messages[0]->getContent() === 'Hi');
```

`FakeMcpTransport` applies the same shape at the JSON-RPC level: queue responses, then `assertMethodSent('initialize')`, `assertToolCalled('search')`, and so on. `FakeChannel` records protocol events and the segment lifecycle (`getSent()`, `getSuspensions()`, `getCompletions()`, `getFailures()`); `setThrowOnSend()` exercises the workflow's failure policy.

An exhausted response queue throws the seam's own exception (`ProviderException`, `McpException`), never a PHPUnit failure: a tool error handler would swallow an assertion failure raised from inside a tool call, and the fakes must not depend on PHPUnit for control flow. Fakes carry no custom serialization either; they hold plain data, so a persisted run round-trips them with their recordings intact.

## Fidelity

A fake is as strict as the seam it stands in: `FakeVectorStore` requires an embedding to store a document and trims search results to the request's `topK`, exactly like a real store, so a test cannot pass on input production would reject.

## Generator gotcha

`FakeAIProvider::stream()` must record the call and consume the queued response **eagerly**, then delegate chunk emission to a separate `streamChunks()` generator: a generator body does not run until it is iterated, so side effects placed inside it would never happen for a caller that only checks the recording. Keep that split when touching stream fakes. `streamChunks()` derives the chunk sequence from the queued message itself (reasoning and text blocks in content order, then each tool call's JSON inputs as `ToolArgumentChunk`s) so stream adapters see the same chunk kinds a real provider emits.
