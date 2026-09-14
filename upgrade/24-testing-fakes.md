# Upgrade: Testing fakes are as strict as the seams they stand in

## Summary

The fakes in `NeuronAI\Testing` let application tests exercise real wiring without
network or infrastructure. In 3.x some of them were more permissive than the component
they replace, or deviated from its contract: a test could pass on input production
rejects, and some code paths a real provider drives were unreachable through the fake.
4.x closes those gaps, and the public surface of the fakes changes as follows.

| 3.x | 4.x |
|---|---|
| `FakeVectorStore::addDocument()` / `addDocuments()` store a document without an embedding | They throw `VectorStoreException` for a document without an embedding, exactly like every real store. Let the RAG or a `FakeEmbeddingsProvider` embed it first |
| `FakeVectorStore::search()` returns every preset result | It trims the preset results to the request's `topK` |
| `FakeVectorStore::getRecorded()` returns `array{method: string, args: array}` entries | It returns `VectorStoreRecord` objects: `method`, `documents` (add methods), `filters` (delete), `request` (search) |
| `FakeAIProvider::stream()` yields `TextChunk` only, built from `getContent()` | It derives the chunks from the queued message: `ReasoningChunk` for reasoning blocks, `TextChunk` for text, then a `ToolArgumentChunk` sequence carrying each tool call's JSON inputs |
| `FakeEmbeddingsProvider` vectors are the first N hex characters of an MD5 hash: at most 32 dimensions, values between 0.19 and 0.39 | Vectors are hash bytes scaled to `[0, 1]` for any number of dimensions. Still deterministic, but the values differ from 3.x and carry no similarity meaning |
| `FakeMcpTransport::receive()` on an empty queue fails the test through `Assert::fail()` | It throws `McpException`, so a tool error handler cannot swallow the failure into a tool result |
| `FakeMcpTransport::assertMethodReceived()`, `getSendCallCount()`, `getReceiveCallCount()` | Removed. `assertMethodReceived()` could never match a JSON-RPC response, which carries `result` or `error` and never `method`; the counters duplicated `count(getSent())` and `count(getReceived())` |
| `FakeMcpTransport::__serialize()` re-queues the requests it sent as responses | Removed. The fake serializes as plain data, so a persisted run restores it with its queue and recordings intact. A test that resumes a run through a persisted MCP tool must queue the responses to the new client's `initialize` handshake |

`FakeChannel`, `ChannelRecord` and `VectorStoreRecord` are new in 4.x; there is nothing
to migrate for them.

## What to Search For

Search the whole application, including tests and config, excluding `vendor/`:

```
grep -rn "FakeVectorStore" --include="*.php" .
grep -rn "getRecorded()" --include="*.php" .
grep -rn "assertMethodReceived\|getSendCallCount\|getReceiveCallCount" --include="*.php" .
grep -rn "FakeAIProvider" --include="*.php" . | grep -i "stream"
grep -rn "FakeEmbeddingsProvider\|embedText(" --include="*.php" .
```

The first finds tests that add documents to the fake store or read its recording. The
second finds every recording read; only the vector store's shape changed. The third finds
calls to the removed transport methods. The fourth finds tests that stream through the
provider fake and may assume every chunk is a `TextChunk`. The fifth finds tests pinned
to specific fake embedding values.

## How to Refactor

### Case 1: Documents added straight to the fake store

Before:

```php
$store = new FakeVectorStore();
$store->addDocument(new Document('Hello'));
```

After:

```php
$embeddings = new FakeEmbeddingsProvider();
$store = new FakeVectorStore();
$store->addDocument($embeddings->embedDocument(new Document('Hello')));
```

Preset search results passed to the constructor or `setSearchResults()` are returned as
they are and need no embedding.

### Case 2: Reading the vector store recording

Before:

```php
$last = end($store->getRecorded());

$this->assertSame('similaritySearch', $last['method']);
$this->assertSame($embedding, $last['args'][0]);
```

After:

```php
$last = end($store->getRecorded());

$this->assertSame('search', $last->method);
$this->assertSame($embedding, $last->request->embedding);
```

Prefer the assertion helpers where one fits: `assertSearchCount()`,
`assertSearchedWithFilters()`, `assertDeletedWithFilters()`.

### Case 3: Streaming a tool call through the fake

Before:

```php
foreach ($agent->stream(new UserMessage('Search it')) as $chunk) {
    $text .= $chunk->content; // every chunk was a TextChunk
}
```

After:

```php
foreach ($agent->stream(new UserMessage('Search it')) as $chunk) {
    if ($chunk instanceof TextChunk) {
        $text .= $chunk->content;
    }
}
```

A stream adapter attached to the agent now receives the same chunk kinds a real provider
emits, so tests can assert reasoning and tool-argument protocol events without a real
provider.

### Case 4: MCP transport assertions

Before:

```php
$transport->assertMethodReceived('initialize', 1);
$this->assertSame(2, $transport->getSendCallCount());
```

After:

```php
$transport->assertReceiveCount(1);
$transport->assertSendCount(2);
```

### Case 5: Tests pinned to fake embedding values

Before:

```php
$this->assertSame([0.188, 0.2, ...], $embeddings->embedText('Hello'));
$this->assertSame('Neuron', $memory->recall('What do you know?')[0]);
```

After:

```php
$this->assertCount(8, $embeddings->embedText('Hello'));
$this->assertSame($embeddings->embedText('Hello'), $embeddings->embedText('Hello'));
$this->assertContains('Neuron', $memory->recall('What do you know?'));
```

Fake vectors are deterministic but meaningless: never assert their values or a ranking
between them.

## Checklist

- No document reaches `FakeVectorStore::addDocument()` / `addDocuments()` without an embedding.
- Recording reads use the `VectorStoreRecord` properties, not array keys.
- Stream tests filter chunk kinds or expect the reasoning and tool-argument chunks.
- No call to `assertMethodReceived()`, `getSendCallCount()` or `getReceiveCallCount()`.
- No assertion on specific fake embedding values or on a similarity order between fake vectors.
