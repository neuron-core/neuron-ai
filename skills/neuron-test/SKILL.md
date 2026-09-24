---
name: neuron-test
description: Write tests for Neuron AI agents, RAG systems, workflows, and tools using the built-in testing utilities. Use this skill when the user mentions testing agents, writing unit tests, mocking AI providers, testing tool execution, verifying RAG retrieval, testing workflow behavior, or creating test cases for Neuron AI components. Also trigger for any task involving PHPUnit tests, fake providers, test assertions, or quality assurance in Neuron AI projects.
---

# Neuron AI Test

This skill helps you write comprehensive tests for Neuron AI applications using the built-in testing utilities in `NeuronAI\Testing`.

## Testing Philosophy

Neuron AI provides fake implementations that:
- **Never make real API calls** - All AI provider calls are mocked
- **Record all interactions** - Inspect what was sent and when
- **Provide fluent assertions** - PHPUnit-style assertions for verification

## Core Testing Utilities

### 1. FakeAIProvider

The primary tool for testing agents without real AI API calls.

```php
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;

// Create with predetermined responses
$provider = new FakeAIProvider(
    new AssistantMessage('Hello! How can I help you?'),
    new AssistantMessage('The answer is 42.')
);

// Or use static constructor
$provider = FakeAIProvider::make(
    new AssistantMessage('Response 1'),
    new AssistantMessage('Response 2')
);
```

**Key Features:**
- Responses are returned sequentially from queue
- Supports `chat()`, `stream()`, and `structured()` methods
- `stream()` derives its chunks from the queued message like a real provider: `ReasoningChunk` for reasoning blocks, `TextChunk` for text, then a `ToolArgumentChunk` sequence with each tool call's JSON inputs
- Records all requests for assertion

### 2. FakeVectorStore

For testing RAG systems without real vector databases.

```php
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\RAG\Document;

// Pre-populate with search results
$vectorStore = new FakeVectorStore([
    new Document('France is a country in Europe. Its capital is Paris.'),
    new Document('Germany is a country in Europe. Its capital is Berlin.'),
]);

// Or create empty and set results later
$vectorStore = FakeVectorStore::make();
$vectorStore->setSearchResults([
    new Document('Relevant document content')
]);
```

Preset results are returned as they are, trimmed to the request's `topK`. Documents stored through `addDocument()` and `addDocuments()` must carry an embedding, exactly like a real store: let the RAG or a `FakeEmbeddingsProvider` embed them first.

### 3. FakeEmbeddingsProvider

For testing embeddings without real API calls.

```php
use NeuronAI\Testing\FakeEmbeddingsProvider;

// Create with default dimensions (8)
$embeddings = new FakeEmbeddingsProvider();

// Or specify dimensions
$embeddings = new FakeEmbeddingsProvider(dimensions: 1536);

// Use static constructor
$embeddings = FakeEmbeddingsProvider::make();
```

### 4. FakeMcpTransport

For testing MCP (Model Context Protocol) integrations without a real MCP server.

```php
use NeuronAI\Testing\FakeMcpTransport;

// Queue predetermined responses
$transport = new FakeMcpTransport(
    ['result' => ['tools' => [['name' => 'search', 'description' => 'Search the web']]]],
    ['result' => ['content' => [['type' => 'text', 'text' => 'Search results...']]]],
);

// Or add responses later
$transport->addResponses(['result' => ['content' => 'More data']]);
```

**Key Features:**
- Responses returned sequentially from queue via `receive()`
- Records all sent/received data for assertion
- Fluent MCP-specific assertions (`assertInitialized`, `assertToolCalled`, etc.)

### 5. FakeMiddleware

For testing workflow middleware behavior.

```php
use NeuronAI\Testing\FakeMiddleware;

$middleware = FakeMiddleware::make();

// Configure custom handlers; they receive the arguments of before() / after(),
// including the segment's resources as the fourth
$middleware->setBeforeHandler(function ($node, $event, $state, $resources): void {
    $state->set('injected_data', 'value');
});

// Configure exceptions for testing error handling
$middleware->setThrowOnBefore(new \Exception('Test exception'));
```

### 6. FakeChannel

For testing streaming channels: where a run's output goes when the consumer is not the caller (queue workers, websockets, resumed runs).

```php
use NeuronAI\Testing\FakeChannel;

$channel = FakeChannel::make();

// Return the same fake from the factory to inspect every segment's delivery
$agent = Agent::make()
    ->setStreamAdapter(fn (): VercelAIAdapter => new VercelAIAdapter())
    ->setChannel(fn (): FakeChannel => $channel);

// Simulate a broken transport: the framework reports the error and keeps the run alive
$channel->setThrowOnSend(new \RuntimeException('transport down'));
```

**Key Features:**
- Records every `ProtocolEvent` delivered through `send()` and the segment lifecycle (`suspended`, `completed`, `failed`) as `ChannelRecord`s
- `getSent()` returns the protocol events in stream order; `getSuspensions()`, `getCompletions()`, `getFailures()` the lifecycle records

## Test Patterns by Component

### Testing Agent Chat

```php
use PHPUnit\Framework\TestCase;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;

class MyAgentTest extends TestCase
{
    public function test_agent_returns_expected_response(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Expected response')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);

        $message = $agent->chat(new UserMessage('Hello'))->getMessage();

        $this->assertSame('Expected response', $message->getContent());
        $provider->assertCallCount(1);
    }

    public function test_agent_uses_system_prompt(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setInstructions('Always respond in French.');

        $agent->chat(new UserMessage('Hello'))->getMessage();

        $provider->assertSystemPrompt('Always respond in French.');
    }
}
```

### Testing Agent with Tools

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Chat\Messages\ToolCallMessage;

class SearchTool extends Tool
{
    protected string $name = 'search';

    protected ?string $description = 'Search the web';

    protected function properties(): array
    {
        return [new ToolProperty('query', PropertyType::STRING, 'Search query', true)];
    }

    public function __invoke(string $query): string
    {
        return "Results for: {$query}";
    }
}

public function test_agent_executes_tool_and_returns_result(): void
{
    // First response: the model calls the tool (a ToolCall record: name, call id, inputs)
    // Second response: the model uses the tool result to answer
    $provider = new FakeAIProvider(
        new ToolCallMessage(null, [
            ToolCall::make('search', 'call_1', ['query' => 'PHP frameworks']),
        ]),
        new AssistantMessage('Based on my search, here are the top PHP frameworks...')
    );

    $agent = Agent::make();
    $agent->setAiProvider($provider);
    $agent->addTool(new SearchTool());

    $message = $agent->chat(new UserMessage('What are the best PHP frameworks?'))->getMessage();

    $this->assertSame('Based on my search, here are the top PHP frameworks...', $message->getContent());
    $provider->assertCallCount(2); // Tool call + final response
    $provider->assertToolsConfigured(['search']);
}
```

### Testing Streaming

```php
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;

public function test_agent_streams_response(): void
{
    $provider = new FakeAIProvider(new AssistantMessage('Hello world'));
    $provider->setStreamChunkSize(5); // Control chunk size for predictable tests

    $agent = Agent::make();
    $agent->setAiProvider($provider);

    $stream = $agent->stream(new UserMessage('Hi'));

    $chunks = [];
    foreach ($stream as $event) {
        if ($event instanceof TextChunk) {
            $chunks[] = $event->content;
        }
    }

    $this->assertSame(['Hello', ' worl', 'd'], $chunks);

    $state = $stream->getReturn();
    $this->assertSame('Hello world', $state->getMessage()->getContent());
}
```

A queued message with reasoning content blocks streams `ReasoningChunk`s first, and a queued `ToolCallMessage` streams a `ToolArgumentChunk` sequence for each call, so stream adapters attached to the agent produce the same protocol events they would with a real provider.

### Testing Structured Output

```php
use NeuronAI\Chat\Messages\AssistantMessage;

public function test_agent_extracts_structured_data(): void
{
    $provider = new FakeAIProvider(
        new AssistantMessage('{"name": "Alice", "age": 30}')
    );

    $agent = Agent::make();
    $agent->setAiProvider($provider);

    class Person
    {
        #[SchemaProperty(description: 'The person name', required: true)]
        public string $name;

        #[SchemaProperty(description: 'The person age')]
        public int $age;
    }

    $person = $agent->structured(
        new UserMessage('My name is Alice and I am 30 years old'),
        Person::class
    );

    $this->assertInstanceOf(Person::class, $person);
    $this->assertSame('Alice', $person->name);
    $this->assertSame(30, $person->age);

    $provider->assertMethodCallCount('structured', 1);
}
```

### Testing RAG Systems

```php
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Document;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;

class MyRAGTest extends TestCase
{
    public function test_rag_retrieves_and_answers(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Paris is the capital of France.')
        );

        $vectorStore = new FakeVectorStore([
            new Document('France is a country in Europe. Its capital is Paris.'),
        ]);

        $rag = RAG::make();
        $rag->setAiProvider($provider);
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->setVectorStore($vectorStore);

        $message = $rag->chat(new UserMessage('What is the capital of France?'))->getMessage();

        $this->assertSame('Paris is the capital of France.', $message->getContent());
        $provider->assertCallCount(1);
        $vectorStore->assertSearchCount(1);
    }

    public function test_rag_adds_documents(): void
    {
        $embeddings = new FakeEmbeddingsProvider();
        $vectorStore = new FakeVectorStore();

        $rag = RAG::make();
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
        $vectorStore->assertHasDocumentWithContent('Second document');
    }
}
```

### Testing Workflows

```php
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;

class MyWorkflowTest extends TestCase
{
    public function test_workflow_executes_nodes_in_sequence(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new FirstNode(),
                new SecondNode(),
                new ThirdNode(),
            ]);

        $finalState = $workflow->run();

        $this->assertTrue($finalState->get('first_executed'));
        $this->assertTrue($finalState->get('second_executed'));
        $this->assertTrue($finalState->get('third_executed'));
    }

    public function test_workflow_with_initial_state(): void
    {
        $workflow = Workflow::make(
            state: new WorkflowState(['input' => 'test_value'])
        )->addNodes([
            new ProcessNode(),
        ]);

        $finalState = $workflow->run();

        $this->assertEquals('test_value', $finalState->get('original_input'));
    }
}
```

### Testing Middleware

```php
use NeuronAI\Testing\FakeMiddleware;

class MyMiddlewareTest extends TestCase
{
    public function test_middleware_runs_on_all_nodes(): void
    {
        $middleware = FakeMiddleware::make();

        Workflow::make()
            ->addGlobalMiddleware($middleware)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->run();

        // 3 nodes = 3 before + 3 after calls
        $middleware->assertBeforeCalledTimes(3);
        $middleware->assertAfterCalledTimes(3);
        $middleware->assertCallCount(6);
    }

    public function test_middleware_only_runs_for_specific_node(): void
    {
        $middleware = FakeMiddleware::make();

        Workflow::make()
            ->addMiddleware(NodeTwo::class, $middleware)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->run();

        $middleware->assertBeforeCalledTimes(1);
        $middleware->assertBeforeCalledForNode(NodeTwo::class);
    }

    public function test_middleware_can_modify_state(): void
    {
        $middleware = FakeMiddleware::make()
            ->setBeforeHandler(function ($node, $event, $state): void {
                $state->set('injected_by_middleware', true);
            });

        $finalState = Workflow::make()
            ->addMiddleware(NodeOne::class, $middleware)
            ->addNodes([new NodeOne(), new NodeTwo()])
            ->run();

        $this->assertTrue($finalState->get('injected_by_middleware'));
    }
}
```

### Testing Workflow Interruption

```php
use NeuronAI\Workflow\Persistence\InMemoryPersistence;

class MyInterruptTest extends TestCase
{
    public function test_workflow_interrupts_and_resumes(): void
    {
        $workflow = Workflow::make(workflowId: 'test-workflow')
            ->setPersistence(new InMemoryPersistence())
            ->addNodes([
                new NodeOne(),
                new InterruptableNode(),
                new NodeThree(),
            ]);

        // The first run stops at the interruption and returns the suspended state
        $state = $workflow->run();

        $this->assertTrue($state->isInterrupted());
        $this->assertSame('human input needed', $state->getInterruptRequest()->getMessage());

        // Resume with the human answer
        $finalState = $workflow->resume(['approved' => true])->run();

        $this->assertFalse($finalState->isInterrupted());
        $this->assertTrue($finalState->get('interruptable_node_executed'));
    }
}
```

### Testing MCP Integrations

Use `FakeMcpTransport` to test code that interacts with MCP servers without running a real server.

```php
use NeuronAI\Testing\FakeMcpTransport;

class McpIntegrationTest extends TestCase
{
    public function test_mcp_initialization_handshake(): void
    {
        $transport = new FakeMcpTransport(
            ['result' => ['capabilities' => [], 'serverInfo' => ['name' => 'test-server']]],
            ['result' => []],
        );

        $transport->connect();

        // Simulate initialization handshake
        $transport->send(['method' => 'initialize', 'params' => ['capabilities' => []]]);
        $transport->receive(); // consume capabilities response

        $transport->send(['method' => 'notifications/initialized']);
        $transport->receive(); // consume ack

        $transport->assertInitialized();
        $transport->assertConnected();
    }

    public function test_mcp_tool_call(): void
    {
        $transport = new FakeMcpTransport(
            ['result' => ['tools' => [['name' => 'search', 'description' => 'Search']]]],
            ['result' => ['content' => [['type' => 'text', 'text' => 'Found 3 results']]]],
        );

        $transport->connect();

        $transport->send(['method' => 'tools/list', 'params' => []]);
        $transport->receive();

        $transport->send(['method' => 'tools/call', 'params' => ['name' => 'search', 'arguments' => ['query' => 'test']]]);
        $transport->receive();

        $transport->assertToolsListCalled();
        $transport->assertToolCalled('search');
        $transport->assertSendCount(2);
        $transport->assertReceiveCount(2);
    }
}
```

## Testing Agents Built by Application Code

The patterns above hold the Agent in the test. When a controller, job or evaluator builds the Agent, a test reaches it only through the application's container: the fake replaces a binding, so the Agent must take its provider from that binding. Laravel is shown; any container works the same way.

```php
class SupportAgent extends Agent
{
    public function __construct(protected AIProviderInterface $llm)
    {
        parent::__construct();
    }

    protected function provider(): AIProviderInterface
    {
        return $this->llm;
    }
}

// Application code resolves the agent through the container
$agent = app(SupportAgent::class)->setThreadId($threadId);

// The test binds one fake instance, drives the application, and asserts on that instance
$fake = new FakeAIProvider(new AssistantMessage('Hello!'));
$this->app->instance(AIProviderInterface::class, $fake);

$this->postJson('/support', ['message' => 'Hi'])->assertOk();

$fake->assertCallCount(1);
```

- An agent built with `SupportAgent::make()`, or a `provider()` hook returning `new Anthropic(...)`, never consults the container, so the test calls the real provider. Build agents through the container wherever tests must reach them.
- A fake keeps its queued responses and recorded calls on the instance, with no global state. Bind a single instance per test and assert on that object: a binding that builds a new fake on every resolution leaves the test asserting on an instance the application never used.
- Jobs reach the same binding when the test queue runs them in-process (`sync`); a separate worker process has its own container.
- To fake at the HTTP level, give the component a client the test controls. Providers, embeddings providers, vector stores, rerankers, MCP transports and the HTTP toolkits accept an `HttpClientInterface`. A framework's own HTTP fake, such as Laravel's `Http::fake()`, only sees requests sent through the framework's client.

```php
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;

$client = new GuzzleHttpClient(handler: HandlerStack::create(new MockHandler([
    new Response(200, [], json_encode(['answer' => 'Answer', 'results' => []])),
])));

$toolkit = TavilyToolkit::make('test-key', httpClient: $client);
```

## Assertion Reference

### FakeAIProvider Assertions

```php
// Verify number of calls
$provider->assertCallCount(3);

// Verify specific method was called
$provider->assertMethodCallCount('chat', 2);
$provider->assertMethodCallCount('stream', 1);
$provider->assertMethodCallCount('structured', 1);

// Verify system prompt
$provider->assertSystemPrompt('You are a helpful assistant.');

// Verify tools were configured
$provider->assertToolsConfigured(['search', 'calculator']);

// Verify no calls were made
$provider->assertNothingSent();

// Custom assertion with callback
$provider->assertSent(function (RequestRecord $record): bool {
    return $record->method === 'chat'
        && str_contains($record->messages[0]->getContent(), 'keyword');
});
```

### FakeVectorStore Assertions

```php
// Verify search count
$vectorStore->assertSearchCount(2);

// Verify document count
$vectorStore->assertDocumentCount(5);

// Verify specific document exists
$vectorStore->assertHasDocumentWithContent('Expected content');

// Verify store is empty
$vectorStore->assertNothingStored();

// Verify the filters used by a search or a delete
$vectorStore->assertSearchedWithFilters(Filter::eq('tenant', 'acme'));
$vectorStore->assertDeletedWithFilters(Filter::eq('sourceName', 'old.txt'));
```

### FakeEmbeddingsProvider Assertions

```php
// Verify embedding call count
$embeddings->assertCallCount(3);

// Verify specific text was embedded
$embeddings->assertEmbeddedText('Expected text to embed');

// Verify no embeddings were made
$embeddings->assertNothingEmbedded();
```

### FakeMiddleware Assertions

```php
// Verify before() was called
$middleware->assertBeforeCalled();
$middleware->assertBeforeNotCalled();
$middleware->assertBeforeCalledTimes(3);
$middleware->assertBeforeCalledForNode(NodeOne::class);

// Verify after() was called
$middleware->assertAfterCalled();
$middleware->assertAfterNotCalled();
$middleware->assertAfterCalledTimes(3);
$middleware->assertAfterCalledForNode(NodeOne::class);

// Verify total call count
$middleware->assertCallCount(6);
$middleware->assertNotCalled();
```

### FakeMcpTransport Assertions

```php
// Verify connection state
$transport->assertConnected();
$transport->assertDisconnected();

// Verify send/receive counts
$transport->assertSendCount(3);
$transport->assertReceiveCount(3);
$transport->assertNothingSent();
$transport->assertNothingReceived();

// Verify specific MCP methods
$transport->assertMethodSent('initialize', 1);

// Convenience assertions for common MCP patterns
$transport->assertInitialized();          // initialize + notifications/initialized
$transport->assertToolsListCalled(1);     // tools/list sent N times
$transport->assertToolCalled('search', 2); // tools/call with specific tool name

// Custom assertion with callback
$transport->assertSent(function (array $data): bool {
    return ($data['method'] ?? null) === 'tools/call'
        && ($data['params']['name'] ?? null) === 'search';
});
```

### FakeChannel Assertions

```php
// Custom assertion on the delivered protocol events
$channel->assertSent(fn (ProtocolEvent $event): bool => $event->type === 'text-delta');
$channel->assertNothingSent();

// Verify how the run segment ended
$channel->assertSuspended();
$channel->assertCompleted();
$channel->assertFailed();
```

## Testing Multiple Turns

```php
public function test_conversation_remembers_context(): void
{
    $provider = new FakeAIProvider(
        new AssistantMessage('Hi! I can help with that.'),
        new AssistantMessage('The capital of France is Paris.'),
    );

    $agent = Agent::make();
    $agent->setAiProvider($provider);

    $first = $agent->chat(new UserMessage('Hello'))->getMessage();
    $second = $agent->chat(new UserMessage('What is the capital of France?'))->getMessage();

    $this->assertSame('Hi! I can help with that.', $first->getContent());
    $this->assertSame('The capital of France is Paris.', $second->getContent());
    $provider->assertCallCount(2);
}
```

## Inspecting Recorded Calls

### RequestRecord Properties

```php
foreach ($provider->getRecorded() as $record) {
    $record->method;          // 'chat', 'stream', or 'structured'
    $record->messages;        // Message[] passed to provider
    $record->systemPrompt;    // ?SystemMessage system prompt
    $record->tools;           // ToolInterface[] configured tools
    $record->structuredClass; // ?string output class (structured only)
    $record->structuredSchema;// array schema (structured only)
}
```

### MiddlewareRecord Properties

```php
foreach ($middleware->getRecorded() as $record) {
    $record->method;  // 'before' or 'after'
    $record->node;    // NodeInterface being executed
    $record->event;   // Event passed/returned
    $record->state;   // the live WorkflowState object, not a snapshot
}
```

### VectorStoreRecord Properties

```php
foreach ($vectorStore->getRecorded() as $record) {
    $record->method;    // 'addDocument', 'addDocuments', 'delete' or 'search'
    $record->documents; // Document[] stored (add methods only)
    $record->filters;   // ?FilterExpression (delete only)
    $record->request;   // ?SearchRequest (search only)
}
```

### ChannelRecord Properties

```php
foreach ($channel->getRecorded() as $record) {
    $record->method;     // 'send', 'suspended', 'completed' or 'failed'
    $record->event;      // ?ProtocolEvent (send only)
    $record->state;      // ?WorkflowState (suspended and completed)
    $record->workflowId; // ?string (completed and failed)
    $record->exception;  // ?Throwable (failed only)
}
```

## Running Tests

```bash
# Run all tests
composer test

# Run specific test file
vendor/bin/phpunit tests/Agent/AgentTest.php

# Run specific test method
vendor/bin/phpunit --filter test_chat_with_tools

# Run with verbose output
vendor/bin/phpunit --colors=always -v
```

## Best Practices

1. **Use descriptive test names** - Test names should describe the behavior being verified
2. **One assertion per concept** - Group related assertions but keep tests focused
3. **Test edge cases** - Empty results, errors, null values
4. **Test streaming consumption** - Always consume generators in tests
5. **Verify call counts** - Ensure the expected number of API calls are made
6. **Use custom assertions** - `assertSent()` with callbacks for complex verification
7. **Test middleware order** - Verify execution order when order matters
8. **Test state changes** - Verify workflow state after execution

## Common Pitfalls

### Generator Not Consumed

```php
// WRONG: Generator not consumed, code never runs
$generator = $provider->stream(new UserMessage('Hi'));
// Body of generator hasn't executed yet!

// CORRECT: Consume the generator
$generator = $provider->stream(new UserMessage('Hi'));
foreach ($generator as $chunk) {
    // Process chunks
}
$finalMessage = $generator->getReturn();
```

### Empty Response Queue

```php
// WRONG: No responses queued
$provider = new FakeAIProvider();
$provider->chat(new UserMessage('Hi')); // Throws ProviderException!

// CORRECT: Queue responses before calling
$provider = new FakeAIProvider(new AssistantMessage('Response'));
$provider->chat(new UserMessage('Hi'));
```

### Hidden Tools Not Sent to Provider

```php
// Hidden tools are executable but not sent to AI
$agent->addTool((new SecretTool())->visible(false));

// This will NOT include 'secret' in tools configured
$provider->assertToolsConfigured(['search']); // Only visible tools
```
