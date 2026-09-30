# Upgrade: Testing fakes are as strict as the seams they stand in

## Summary

Each fake in `NeuronAI\Testing` now behaves like the component it replaces. Tests that relied on a fake being more lenient, or on its 3.x recording format, must change.

| 3.x | 4.x |
|---|---|
| `FakeAIProvider::stream()` yields `TextChunk`s cut from `getContent()` | The chunks follow the queued message: a `ReasoningChunk` sequence for each reasoning block, a `TextChunk` sequence for each text block (no separator between blocks), then, for a `ToolCallMessage`, `ToolArgumentChunk`s with each call's JSON inputs (Case 1) |
| `FakeMessageMapper`, `FakeToolMapper`, `FakeAIProvider::messageMapper()` / `toolPayloadMapper()` | Removed (Case 2) |
| `FakeMiddleware::before()` / `after()` take three arguments | They take a fourth, `NeuronAI\Workflow\WorkflowResources $resources` (Case 3) |
| A `FakeMiddleware` passed to `addMiddleware()` / `addGlobalMiddleware()` records its calls on that instance | The workflow runs a copy made for every execution segment, so the instance the test holds records nothing (Case 4) |
| `FakeVectorStore::addDocument()` / `addDocuments()` store a document that has no embedding | They throw `NeuronAI\Exceptions\VectorStoreException` (Case 5) |
| `FakeVectorStore::getRecorded()` returns `['method' => ..., 'args' => [...]]` arrays | It returns `NeuronAI\Testing\VectorStoreRecord` objects (Case 6) |
| `FakeEmbeddingsProvider` vectors have at most 32 values, each between 0.188 and 0.4 | They have exactly `$dimensions` values between 0 and 1, and the values differ from 3.x (Case 7) |
| `FakeMcpTransport::assertMethodReceived()`, `getSendCallCount()`, `getReceiveCallCount()` | Removed (Case 8) |
| `FakeMcpTransport::receive()` on an empty queue fails with `PHPUnit\Framework\AssertionFailedError` | It throws `NeuronAI\MCP\McpException` (Case 9) |
| An unserialized `FakeMcpTransport` has the requests it sent queued ahead of its remaining responses, and empty recordings | It comes back unchanged: the unconsumed responses and the recordings (Case 10) |

No stored data is involved: the fakes exist only inside test runs.

Already migrated by earlier guides, leave these as they are:

- `FakeAIProvider` return values (`chat()`, `structured()` and the `getReturn()` of `stream()` are `ProviderResponse`), including subclass overrides of `chat()`, `stream()`, `streamChunks()` and `structured()`: guide 41.
- `FakeAIProvider::systemPrompt()` and `RequestRecord::$systemPrompt` (`?SystemMessage`): guide 24.
- The message ID of streamed chunks (the queued message's `getId()` instead of `fake_msg_...`): guide 38.
- `FakeVectorStore` calls (`search(SearchRequest)`, `delete(FilterExpression)`), its `DocumentSchema` constructor argument and filter checks: guide 19. Metadata values checked when a document is stored: guide 20.
- Tool entries of queued `ToolCallMessage`s: guide 4. `StreamingNode::class` middleware keys: guide 26. The frames built-in adapters send: guide 37.

Nothing to migrate: `FakeChannel`, `ChannelRecord`, `VectorStoreRecord` and `FakeMcpTransport::assertProtocolVersion()` are new, and `FakeClassifier` is unchanged.

## What to Search For

Run from the application root:

```bash
# 0. Files that use a Neuron testing fake
grep -rlE 'Fake(AIProvider|Middleware|VectorStore|EmbeddingsProvider|McpTransport|MessageMapper|ToolMapper)([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .

# 1. Streams fed by FakeAIProvider (Case 1)
grep -rlE 'FakeAIProvider' --include='*.php' --exclude-dir=vendor . | xargs grep -nHE 'stream\(|Chunk|StreamAdapter' /dev/null

# 2. Removed mapper fakes and mapper accessors (Case 2)
grep -rnE 'FakeMessageMapper|FakeToolMapper' --include='*.php' --exclude-dir=vendor .
grep -rlE 'FakeAIProvider' --include='*.php' --exclude-dir=vendor . | xargs grep -nHE 'messageMapper|toolPayloadMapper|function +(getModel|split) *\(|\$model([^A-Za-z0-9_]|$)' /dev/null

# 3. FakeMiddleware calls, subclasses and registrations (Cases 3 and 4)
grep -rlE 'FakeMiddleware' --include='*.php' --exclude-dir=vendor . | xargs grep -nHE -e '->(before|after)\(|add(Global)?Middleware\(|extends +[A-Za-z0-9_\\]*FakeMiddleware|->node([^A-Za-z0-9_]|$)' /dev/null

# 4. Documents written to FakeVectorStore, and reads of its recording (Cases 5 and 6)
grep -rlE 'FakeVectorStore' --include='*.php' --exclude-dir=vendor . | xargs grep -nHE 'addDocuments?\(|getRecorded\(' /dev/null
grep -rnE "\[[\"']args[\"']\]|[\"'](similaritySearch|deleteBy|deleteBySource)[\"']" --include='*.php' --exclude-dir=vendor .

# 5. Assertions on fake embedding values (Case 7)
grep -rlE 'FakeEmbeddingsProvider' --include='*.php' --exclude-dir=vendor . | xargs grep -nHE 'embed(Text|Document|Documents)\(|new FakeEmbeddingsProvider\([^)]|->search\(' /dev/null

# 6. FakeMcpTransport: removed methods, exhausted queue, serialization (Cases 8 to 10)
grep -rnE 'assertMethodReceived|getSendCallCount|getReceiveCallCount' --include='*.php' --exclude-dir=vendor .
grep -rlE 'FakeMcpTransport' --include='*.php' --exclude-dir=vendor . | xargs grep -nHE 'AssertionFailedError|serialize\(' /dev/null
```

If search 0 finds nothing, this guide does not apply. Otherwise follow the hits:

- **Search 1:** keep loops over `$agent->stream(...)`, over a `stream()` called directly on the fake, and tests that attach a stream adapter, when a queued response carries a `ReasoningContent` block, more than one text block, or is a `ToolCallMessage`. A response with a single text block, such as `new AssistantMessage('Hello')`, streams the same chunks as in 3.x.
- **Search 2:** keep calls on a `FakeAIProvider` and members declared in classes that extend it.
- **Search 3:** keep `->before(`/`->after(` calls on a `FakeMiddleware` (or a subclass), classes that extend it, `addMiddleware()`/`addGlobalMiddleware()` calls that receive a `FakeMiddleware` instance, and `->node` reads of its `MiddlewareRecord`s. Middleware returned by a `middleware()` or `globalMiddleware()` hook needs no change.
- **Search 4:** the second pattern also finds recording reads in helpers that do not name `FakeVectorStore`. Guide 19 already rewrote the calls, so the method names left as strings are recording reads. An `'args'` key of an array that is not a `FakeVectorStore` recording is unrelated.
- **Search 5:** keep assertions on vector values, vector lengths, or the order of search results over fake-embedded documents.
- **Search 6:** `serialize\(` also matches `unserialize(`.

## How to Refactor

### Case 1: Consuming a stream through `FakeAIProvider`

Before (3.x; the loop as guide 23 left it, the tool call as guide 4 left it; `ResearchAgent` registers the `search` tool):

```php
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;

$provider = new FakeAIProvider(
    new ToolCallMessage(null, [ToolCall::make(name: 'search', callId: 'call_1', inputs: ['query' => 'php'])]),
    new AssistantMessage([new ReasoningContent('Checking the results.'), new TextContent('Found 3 frameworks.')]),
);
$agent = ResearchAgent::make()->setThreadId('test')->setAiProvider($provider);

$text = '';
$chunks = 0;
foreach ($agent->stream(new UserMessage('Search it')) as $chunk) {
    $chunks++;
    if ($chunk instanceof ToolCallChunk || $chunk instanceof ToolResultChunk) {
        continue;
    }
    $text .= $chunk->content;
}

$this->assertSame('Found 3 frameworks.', $text);
$this->assertSame(6, $chunks);
```

After (4.x):

```php
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;

$text = '';
$chunks = 0;
foreach ($agent->stream(new UserMessage('Search it')) as $chunk) {
    $chunks++;
    if ($chunk instanceof TextChunk) {
        $text .= $chunk->content;
    }
}

$this->assertSame('Found 3 frameworks.', $text);
$this->assertSame(14, $chunks);
```

1. Collect text with a positive `instanceof TextChunk` check. Without it, the loop collects the reasoning text (`ReasoningChunk` has `content` and does not extend `TextChunk`) and reads the missing `content` of `ToolArgumentChunk` (`Undefined property` warning). A check that skips only `ToolCallChunk` and `ToolResultChunk` lets `ToolArgumentChunk` through.
2. Recompute exact chunk counts and sequences. For each queued response the fake yields, in order: `ReasoningChunk`s for each reasoning block, `TextChunk`s for each text block, then `ToolArgumentChunk`s (`toolName`, `delta`, `toolCallId`) with each tool call's JSON-encoded inputs. Each block and each call's JSON is cut into pieces of `setStreamChunkSize()` characters (5 by default). The agent still yields a `ToolCallChunk` and a `ToolResultChunk` for each executed call. In the example: 3 argument chunks for `{"query":"php"}`, 2 tool chunks, 5 reasoning chunks, 4 text chunks.
3. The streamed text of a response with several text blocks is the blocks joined with no separator, while `getContent()` joins them with a space. Compare it with `implode('', array_map(fn (TextContent $block): string => $block->content, $answer->getTextBlocks()))`, not with `$answer->getContent()`.
4. Tests of a stream adapter over the fake (`setStreamAdapter(...)`) now also receive the reasoning and tool-argument events for such responses. Add them to the expected event or frame sequences.

### Case 2: `FakeMessageMapper`, `FakeToolMapper` and `FakeAIProvider` subclasses

Before (3.x; `$provider` is a `FakeAIProvider`, `ChatGateway` is an application class that takes mappers):

```php
use NeuronAI\Testing\FakeMessageMapper;
use NeuronAI\Testing\FakeToolMapper;

$messagesPayload = $provider->messageMapper()->map($messages);
$toolsPayload = $provider->toolPayloadMapper()->map($tools);

$gateway = new ChatGateway(new FakeMessageMapper(), new FakeToolMapper());
```

After (4.x):

```php
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;

$messagesPayload = array_map(fn (Message $message): array => $message->jsonSerialize(), $messages);
$toolsPayload = array_map(fn (ToolInterface|ProviderToolInterface $tool): mixed => $tool->jsonSerialize(), $tools);

$gateway = new ChatGateway(
    new class () implements MessageMapperInterface {
        public function map(array $messages): array
        {
            return array_map(fn (Message $message): array => $message->jsonSerialize(), $messages);
        }
    },
    new class () implements ToolMapperInterface {
        public function map(array $tools): array
        {
            return array_map(fn (ToolInterface|ProviderToolInterface $tool): mixed => $tool->jsonSerialize(), $tools);
        }
    },
);
```

1. Replace `messageMapper()->map(...)` and `toolPayloadMapper()->map(...)` calls on a `FakeAIProvider` with the `jsonSerialize()` maps above. They produce what the 3.x fakes produced.
2. Where `new FakeMessageMapper()` or `new FakeToolMapper()` is passed as a mapper, pass an application-owned implementation with the same body. Use the anonymous classes above, or a class in the tests' support namespace when several tests need it. Remove the imports of the deleted classes.
3. In a class that extends `FakeAIProvider`, delete `messageMapper()` and `toolPayloadMapper()` overrides that nothing calls. `FakeAIProvider` now declares `public function getModel(): string`, `protected string $model` and `protected function split(string $text): array`. Rename a subclass member that reuses one of these names with an incompatible declaration (another signature, another property type, or a narrower visibility), and update its call sites.

### Case 3: Calling `FakeMiddleware::before()` / `after()` directly, and its subclasses

A three-argument call throws `ArgumentCountError`, and a subclass that overrides `before()` or `after()` with the 3.x signature is a fatal error when it loads.

Before (3.x):

```php
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

$middleware = new FakeMiddleware();
$middleware->before($node, $event, $state);
$middleware->after($node, $result, $state);

class CountingMiddleware extends FakeMiddleware
{
    public int $calls = 0;

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        $this->calls++;
        parent::before($node, $event, $state);
    }
}
```

After (4.x):

```php
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;

$middleware = new FakeMiddleware();
$middleware->before($node, $event, $state, new WorkflowResources());
$middleware->after($node, $result, $state, new WorkflowResources());

class CountingMiddleware extends FakeMiddleware
{
    public int $calls = 0;

    public function before(NodeInterface $node, Event $event, WorkflowState $state, WorkflowResources $resources): void
    {
        $this->calls++;
        parent::before($node, $event, $state, $resources);
    }
}
```

Closures passed to `setBeforeHandler()` / `setAfterHandler()` now receive the resources as a fourth argument. Closures with three parameters keep working.

### Case 4: `FakeMiddleware` registered by instance

Before (3.x):

```php
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeMiddleware;

$middleware = new FakeMiddleware();
$agent->addMiddleware(ChatNode::class, $middleware);
$workflow->addGlobalMiddleware($middleware);

$agent->chat(new UserMessage('Hi'));
$middleware->assertBeforeCalled();
```

After (4.x):

```php
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;

$middleware = new FakeMiddleware();
$agent->addMiddleware(ChatNode::class, fn (): WorkflowMiddleware => $middleware);
$workflow->addGlobalMiddleware(fn (): WorkflowMiddleware => $middleware);

$agent->chat(new UserMessage('Hi'));
$middleware->assertBeforeCalled();
```

1. Wrap every `FakeMiddleware` instance passed to `addMiddleware()` or `addGlobalMiddleware()`, on an agent, a RAG or a workflow, in a closure that returns it. The workflow uses what the closure returns as it is, so the calls land on the instance the test asserts on. Split an array of instances into one call per instance, in the same order: `->addMiddleware(ChatNode::class, fn (): WorkflowMiddleware => $first)->addMiddleware(ChatNode::class, fn (): WorkflowMiddleware => $second)`. The declared parameter type does not accept an array of closures.
2. `MiddlewareRecord::$node` is the node object that ran. A node registered by instance with `addNode()` is copied for every execution segment, so replace `assertSame($node, $record->node)` with `assertInstanceOf(SearchNode::class, $record->node)` or `$middleware->assertBeforeCalledForNode(SearchNode::class)`, using the node's class.

### Case 5: Documents stored straight into `FakeVectorStore`

Before (3.x):

```php
use NeuronAI\RAG\Document;
use NeuronAI\Testing\FakeVectorStore;

$store = new FakeVectorStore();
$store->addDocument(new Document('Hello'));
$store->addDocuments([new Document('A'), new Document('B')]);
```

After (4.x):

```php
use NeuronAI\RAG\Document;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;

$embeddings = new FakeEmbeddingsProvider();
$store = new FakeVectorStore();
$store->addDocument($embeddings->embedDocument(new Document('Hello')));
$store->addDocuments($embeddings->embedDocuments([new Document('A'), new Document('B')]));
```

- A fixed vector works too: `(new Document('Hello'))->setEmbedding([0.1, 0.2, 0.3])`.
- Documents stored through `RAG::addDocuments()` are embedded by the RAG and need no change. Preset results (the constructor argument, `setSearchResults()`) are returned as they are and need no embedding.
- A test that stores a document without an embedding on purpose must expect `VectorStoreException` (`Document <id> must have an embedding before it can be stored.`).

### Case 6: Reading the `FakeVectorStore` recording

Before (3.x):

```php
$recorded = $store->getRecorded();
$last = end($recorded);

$this->assertSame('similaritySearch', $last['method']);
$this->assertSame($embedding, $last['args'][0]);
$this->assertSame('deleteBy', $recorded[0]['method']);
$this->assertSame(['file', 'a.pdf'], $recorded[0]['args']);
```

After (4.x):

```php
use NeuronAI\RAG\VectorStore\Filter\Filter;

$recorded = $store->getRecorded();
$last = end($recorded);

$this->assertSame('search', $last->method);
$this->assertSame($embedding, $last->request->embedding);
$store->assertDeletedWithFilters(Filter::where('sourceType', 'file')->where('sourceName', 'a.pdf'));
```

| 3.x entry | 4.x `VectorStoreRecord` |
|---|---|
| `$r['method']` | `$r->method` |
| `'similaritySearch'`, `$r['args'][0]` (the embedding) | `'search'`, `$r->request->embedding`. `$r->request` is the `SearchRequest`, which also has `->filters` and `->topK` |
| `'addDocument'`, `$r['args'][0]` | `'addDocument'`, `$r->documents[0]` |
| `'addDocuments'`, `$r['args']` | `'addDocuments'`, `$r->documents` |
| `'deleteBy'` (also recorded for `deleteBySource()`), `$r['args']`: `[$sourceType, $sourceName]` or `[$sourceType]` | `'delete'`, `$r->filters` (a `FilterExpression`) |

1. Rewrite each read with the table, or use the helpers: `assertSearchCount(n)` (unchanged), `assertSearchedWithFilters($expression)` and `assertDeletedWithFilters($expression)`.
2. The two filter helpers compare `toArray()`, so pass the same expression form the code under test passes to the store. `RAG::reindexBySource()` and guide 19's rewrite of `deleteBy($type, $name)` match `Filter::where('sourceType', $type)->where('sourceName', $name)`. Guide 19's rewrite of `deleteBy($type)` is `Filter::eq('sourceType', $type)`, which does not match `Filter::where('sourceType', $type)`.

### Case 7: Tests pinned to `FakeEmbeddingsProvider` values

Before (3.x; the search as guide 19 left it):

```php
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\Testing\FakeEmbeddingsProvider;

$embeddings = new FakeEmbeddingsProvider();
$this->assertSame($vectorCapturedFrom3x, $embeddings->embedText('Hello'));
$this->assertCount(32, (new FakeEmbeddingsProvider(64))->embedText('Hello'));

$store = new MemoryVectorStore(topK: 2);
$store->addDocuments($embeddings->embedDocuments([new Document('Neuron'), new Document('Laravel'), new Document('Symfony')]));
$results = $store->search(new SearchRequest($embeddings->embedText('What is Neuron?')));
$this->assertSame('Neuron', $results[0]->getContent());
```

After (4.x):

```php
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\Testing\FakeEmbeddingsProvider;

$embeddings = new FakeEmbeddingsProvider();
$this->assertCount(8, $embeddings->embedText('Hello'));
$this->assertSame($embeddings->embedText('Hello'), $embeddings->embedText('Hello'));
$this->assertCount(64, (new FakeEmbeddingsProvider(64))->embedText('Hello'));

$store = new MemoryVectorStore(topK: 2);
$store->addDocuments([
    (new Document('Neuron'))->setEmbedding([1.0, 0.0]),
    (new Document('Laravel'))->setEmbedding([0.0, 1.0]),
    (new Document('Symfony'))->setEmbedding([0.1, 0.9]),
]);
$results = $store->search(new SearchRequest([0.9, 0.1]));
$this->assertSame('Neuron', $results[0]->getContent());
```

1. Do not assert vector values. Assert the length (the constructor's `$dimensions`, 8 by default, now also above 32) and that the same text gives the same vector.
2. The order of search results over fake-embedded documents changes. When the test checks an order, give the documents and the query fixed vectors with `setEmbedding()`, as above.
3. When the test only checks that a document is found, and the store's `topK` is at least the number of stored documents, assert membership: `$this->assertContains('Neuron', array_map(fn (Document $document): string => $document->getContent(), $results));`.
4. If neither fits, for example because a RAG embeds the query inside the run and `topK` is smaller than the number of documents, ask the developer whether to raise `topK` in the test or to seed the store with fixed vectors.

### Case 8: `FakeMcpTransport` counters and `assertMethodReceived()`

Before (3.x):

```php
$transport->assertMethodReceived('initialize', 1);
$this->assertSame(3, $transport->getSendCallCount());
$this->assertSame(2, $transport->getReceiveCallCount());
```

After (4.x):

```php
$this->assertCount(1, array_filter(
    $transport->getReceived(),
    fn (array $message): bool => ($message['result']['method'] ?? null) === 'initialize'
        || ($message['method'] ?? null) === 'initialize',
));
$transport->assertSendCount(3);
$transport->assertReceiveCount(2);
```

- `assertMethodReceived($method, $count)` (the count defaults to 1) becomes the `array_filter()` count above, which keeps its exact meaning. When the test is really about the request the client sent, `$transport->assertMethodSent($method, $count)` is the clearer replacement.
- `getSendCallCount()` becomes `count($transport->getSent())`, or `assertSendCount(n)` inside an assertion. `getReceiveCallCount()` becomes `count($transport->getReceived())`, or `assertReceiveCount(n)`.

### Case 9: An exhausted `FakeMcpTransport`

Before (3.x):

```php
use PHPUnit\Framework\AssertionFailedError;

$this->expectException(AssertionFailedError::class);
```

After (4.x):

```php
use NeuronAI\MCP\McpException;

$this->expectException(McpException::class);
$this->expectExceptionMessage('FakeMcpTransport response queue is empty');
```

Change only expectations that target the fake's empty queue. Remove the `AssertionFailedError` import if nothing else uses it.

### Case 10: Serializing a `FakeMcpTransport` or the connector that holds it

Before (3.x; `$tool` is a `tools/list` entry such as `['name' => 'search', 'inputSchema' => [...]]`):

```php
use NeuronAI\MCP\McpConnector;
use NeuronAI\Testing\FakeMcpTransport;

$transport = new FakeMcpTransport(
    ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
    ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [$tool]]],
);
$connector = McpConnector::make(['transport' => $transport]);
$connector->tools();

$restored = unserialize(serialize($connector));
// 3.x: the restored fake replayed the requests sent above as its responses, and its recordings were empty
```

After (4.x):

```php
use NeuronAI\MCP\McpConnector;
use NeuronAI\Testing\FakeMcpTransport;

$transport = new FakeMcpTransport(
    ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
    ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [$tool]]],
    // Responses for the client of the restored connector
    ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
    ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [$tool]]],
);
$connector = McpConnector::make(['transport' => $transport]);
$connector->tools();

$restored = unserialize(serialize($connector));
$restored->tools();
```

1. The restored connector opens a new client, which sends `initialize` again with ID 1, then numbers its next requests 2, 3 and so on. Queue a response for each of them before serializing, in the constructor or with `addResponses()`.
2. The restored fake keeps the recordings made before serialization. Count assertions after the round trip include that earlier traffic.

## Checklist

- Stream loops over a `FakeAIProvider` collect text with `instanceof TextChunk`, and exact chunk counts, sequences and adapter events include the reasoning and tool-argument chunks.
- No reference to `FakeMessageMapper`, `FakeToolMapper`, or `messageMapper()`/`toolPayloadMapper()` on a `FakeAIProvider`.
- Every direct `FakeMiddleware::before()`/`after()` call and override has the `WorkflowResources` argument.
- Every `FakeMiddleware` passed to `addMiddleware()`/`addGlobalMiddleware()` is wrapped in `fn (): WorkflowMiddleware => $middleware`.
- No document reaches `FakeVectorStore::addDocument()`/`addDocuments()` without an embedding.
- Reads of the vector store recording use `VectorStoreRecord` properties and the 4.x method names `search`/`delete`; search 4's second pattern finds nothing.
- No assertion on fake embedding values, on a length capped at 32, or on an order of fake-embedded search results.
- No call to `assertMethodReceived()`, `getSendCallCount()` or `getReceiveCallCount()`.
- No expectation of `AssertionFailedError` from an exhausted `FakeMcpTransport`.
- Tests that serialize the MCP fake queue the restored client's responses explicitly.
