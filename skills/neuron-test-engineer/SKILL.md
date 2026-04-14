---
name: neuron-test-engineer
description: Write tests for Neuron AI agents, RAG systems, workflows, and tools using the built-in testing utilities. Use this skill when the user mentions testing agents, writing unit tests, mocking AI providers, testing tool execution, verifying RAG retrieval, testing workflow behavior, or creating test cases for Neuron AI components. Also trigger for any task involving PHPUnit tests, fake providers, test assertions, or quality assurance in Neuron AI projects.
---

# Neuron AI Test Engineer

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

// Configure custom handlers
$middleware->setBeforeHandler(function ($node, $event, $state): void {
    $state->set('injected_data', 'value');
});

// Configure exceptions for testing error handling
$middleware->setThrowOnBefore(new \Exception('Test exception'));
```

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
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Chat\Messages\ToolCallMessage;

public function test_agent_executes_tool_and_returns_result(): void
{
    $searchTool = Tool::make('search', 'Search the web')
        ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Search query', true))
        ->setCallable(fn (string $query): string => "Results for: {$query}");

    // First response: model calls the tool
    // Second response: model uses tool result to answer
    $provider = new FakeAIProvider(
        new ToolCallMessage(null, [
            (clone $searchTool)->setCallId('call_1')->setInputs(['query' => 'PHP frameworks']),
        ]),
        new AssistantMessage('Based on my search, here are the top PHP frameworks...')
    );

    $agent = Agent::make();
    $agent->setAiProvider($provider);
    $agent->addTool($searchTool);

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

    $handler = $agent->stream(new UserMessage('Hi'));

    $chunks = [];
    foreach ($handler->events() as $event) {
        if ($event instanceof TextChunk) {
            $chunks[] = $event->content;
        }
    }

    $this->assertSame(['Hello', ' worl', 'd'], $chunks);

    $state = $handler->run();
    $this->assertSame('Hello world', $state->getMessage()->getContent());
}
```

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

        $finalState = $workflow->init()->run();

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

        $finalState = $workflow->init()->run();

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
            ->init()
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
            ->init()
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
            ->init()
            ->run();

        $this->assertTrue($finalState->get('injected_by_middleware'));
    }
}
```

### Testing Workflow Interruption

```php
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;

class MyInterruptTest extends TestCase
{
    public function test_workflow_interrupts_and_resumes(): void
    {
        $workflow = Workflow::make(
            persistence: new InMemoryPersistence(),
            resumeToken: 'test-workflow'
        )->addNodes([
            new NodeOne(),
            new InterruptableNode(),
            new NodeThree(),
        ]);

        // First run should interrupt
        $interrupt = null;
        try {
            $workflow->init()->run();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
        }

        $this->assertNotNull($interrupt);
        $this->assertEquals('human input needed', $interrupt->getRequest()->getMessage());

        // Resume with human feedback
        $finalState = $workflow->init($interrupt->getRequest())->run();

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
$transport->assertMethodReceived('initialize', 1);

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
    $record->systemPrompt;    // ?string system prompt
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
    $record->state;   // WorkflowState at call time
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
$hiddenTool = Tool::make('secret', 'Secret tool')
    ->setCallable(fn ($input) => "Result: {$input}")
    ->visible(false);

// This will NOT include 'secret' in tools configured
$provider->assertToolsConfigured(['search']); // Only visible tools
```
