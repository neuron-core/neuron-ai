# Testing

When you test an agent, you don't want every test run to make real API calls to OpenAI, Anthropic, or any other provider. Real calls are slow, cost money, and return different results every time — making your tests flaky and expensive. The same applies to RAG agents: you don't want to spin up a vector database or call an embeddings API just to verify your agent's logic.

Neuron ships with drop-in test doubles that solve this. `FakeAIProvider` replaces the AI provider, `FakeEmbeddingsProvider` replaces the embeddings provider, and `FakeVectorStore` replaces the vector store. They return predetermined responses, never hit the network, and record every interaction so you can assert exactly what your agent did.

## Setup

Create a `FakeAIProvider` with the responses you expect the model to return, then inject it into your agent:

```php
use NeuronAI\Chat\Messages\Stream\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;

$provider = new FakeAIProvider(
    new AssistantMessage('Hello! How can I help you?')
);

$agent = new MyAgent();
$agent->setAiProvider($provider);
```

Responses are returned in order. If your agent makes multiple calls to the provider (e.g. tool calls), queue multiple responses:

```php
$provider = new FakeAIProvider(
    new AssistantMessage('First response'),
    new AssistantMessage('Second response'),
);
```

## Chat

```php
public function test_agent_responds(): void
{
    $provider = new FakeAIProvider(
        new AssistantMessage('The capital of France is Paris.')
    );

    $agent = new MyAgent();
    $agent->setAiProvider($provider);

    $message = $agent->chat(new UserMessage('What is the capital of France?'))->getMessage();

    $this->assertSame('The capital of France is Paris.', $message->getContent());
    $provider->assertCallCount(1);
}
```

## Streaming

The fake provider splits the response text into chunks, simulating a real stream:

```php
public function test_agent_streams_response(): void
{
    $provider = new FakeAIProvider(
        new AssistantMessage('Hello world')
    );

    $agent = new MyAgent();
    $agent->setAiProvider($provider);

    $handler = $agent->stream(new UserMessage('Hi'));

    $chunks = [];
    foreach ($handler->events() as $event) {
        if ($event instanceof \NeuronAI\Chat\Messages\Stream\Chunks\TextChunk) {
            $chunks[] = $event->content;
        }
    }

    // The response is split into chunks of 5 characters by default
    $this->assertSame(['Hello', ' worl', 'd'], $chunks);

    // The final message is available after the stream is consumed
    $state = $handler->run();
    $this->assertSame('Hello world', $state->getMessage()->getContent());
}
```

You can change the chunk size with `setStreamChunkSize()`:

```php
$provider->setStreamChunkSize(10);
```

## Structured Output

Provide a JSON string that matches your output class schema. The agent will deserialize and validate it as usual:

```php
public function test_agent_returns_structured_output(): void
{
    $provider = new FakeAIProvider(
        new AssistantMessage('{"name": "Alice"}')
    );

    $agent = new MyAgent();
    $agent->setAiProvider($provider);

    $user = $agent->structured(new UserMessage('Generate a user'), User::class);

    $this->assertInstanceOf(User::class, $user);
    $this->assertSame('Alice', $user->name);
}
```

## Tool Calls

When the model decides to call a tool, it returns a `ToolCallMessage`. The agent executes the tool and loops back to the provider for a final answer. Queue both responses:

```php
use NeuronAI\Chat\Messages\ToolCallMessage;

public function test_agent_uses_tools(): void
{
    $searchTool = Tool::make('search', 'Search the web')
        ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Search query', true))
        ->setCallable(fn (string $query): string => "Results for: {$query}");

    $provider = new FakeAIProvider(
        // First call: the model asks to use the search tool
        new ToolCallMessage(null, [
            (clone $searchTool)->setCallId('call_1')->setInputs(['query' => 'PHP frameworks']),
        ]),
        // Second call: the model responds using the tool result
        new AssistantMessage('Here are the top PHP frameworks...')
    );

    $agent = new MyAgent();
    $agent->setAiProvider($provider);
    $agent->addTool($searchTool);

    $message = $agent->chat(new UserMessage('Best PHP frameworks?'))->getMessage();

    $this->assertSame('Here are the top PHP frameworks...', $message->getContent());
    $provider->assertCallCount(2);
}
```

## Assertions

`FakeAIProvider` includes built-in assertions you can use in your tests:

```php
// Verify the total number of provider calls
$provider->assertCallCount(2);

// Verify calls by method
$provider->assertMethodCallCount('chat', 1);
$provider->assertMethodCallCount('stream', 1);

// Verify no calls were made
$provider->assertNothingSent();

// Verify the system prompt
$provider->assertSystemPrompt('You are a helpful assistant.');

// Verify tools were configured
$provider->assertToolsConfigured(['search', 'calculator']);

// Custom assertion with a callback
$provider->assertSent(fn (RequestRecord $record): bool =>
    $record->method === 'chat'
    && $record->messages[0]->getContent() === 'Hello'
);
```

## Inspecting Recorded Requests

For more advanced checks, access the raw recorded requests:

```php
$records = $provider->getRecorded();

$records[0]->method;          // 'chat', 'stream', or 'structured'
$records[0]->messages;        // Message[] sent to the provider
$records[0]->systemPrompt;    // The system prompt at call time
$records[0]->tools;           // The tools configured at call time
$records[0]->structuredClass; // The output class (structured calls only)
$records[0]->structuredSchema; // The JSON schema (structured calls only)
```

## Testing RAG

RAG agents depend on an embeddings provider and a vector store in addition to the AI provider. Neuron provides `FakeEmbeddingsProvider` and `FakeVectorStore` to replace both in tests.

### FakeEmbeddingsProvider

Generates deterministic embeddings without calling any external API. Drop it in wherever you need an embeddings provider:

```php
use NeuronAI\Testing\FakeEmbeddingsProvider;

$embeddings = new FakeEmbeddingsProvider();
```

### FakeVectorStore

Returns predetermined documents from `similaritySearch()` regardless of the embedding passed in. Pass the documents you want returned to the constructor:

```php
use NeuronAI\RAG\Document;
use NeuronAI\Testing\FakeVectorStore;

$vectorStore = new FakeVectorStore([
    new Document('Paris is the capital of France.'),
    new Document('Berlin is the capital of Germany.'),
]);
```

### RAG Chat

```php
public function test_rag_answers_from_documents(): void
{
    $provider = new FakeAIProvider(
        new AssistantMessage('Paris is the capital of France.')
    );

    $vectorStore = new FakeVectorStore([
        new Document('France is a country in Europe. Its capital is Paris.'),
    ]);

    $rag = new MyRAG();
    $rag->setAiProvider($provider);
    $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
    $rag->setVectorStore($vectorStore);

    $message = $rag->chat(new UserMessage('What is the capital of France?'))->getMessage();

    $this->assertSame('Paris is the capital of France.', $message->getContent());
    $provider->assertCallCount(1);
    $vectorStore->assertSearchCount(1);
}
```

### Adding Documents

Test that your indexing pipeline correctly embeds and stores documents:

```php
public function test_documents_are_embedded_and_stored(): void
{
    $embeddings = new FakeEmbeddingsProvider();
    $vectorStore = new FakeVectorStore();

    $rag = new MyRAG();
    $rag->setAiProvider(new FakeAIProvider());
    $rag->setEmbeddingsProvider($embeddings);
    $rag->setVectorStore($vectorStore);

    $rag->addDocuments([
        new Document('First document'),
        new Document('Second document'),
    ]);

    $embeddings->assertCallCount(2);
    $vectorStore->assertDocumentCount(2);
    $vectorStore->assertHasDocumentWithContent('First document');
}
```

### RAG Assertions

```php
// FakeEmbeddingsProvider
$embeddings->assertCallCount(2);
$embeddings->assertEmbeddedText('Some specific text');
$embeddings->assertNothingEmbedded();

// FakeVectorStore
$vectorStore->assertSearchCount(1);
$vectorStore->assertDocumentCount(3);
$vectorStore->assertHasDocumentWithContent('Expected content');
$vectorStore->assertNothingStored();
```
