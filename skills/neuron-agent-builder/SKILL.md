---
name: neuron-agent-builder
description: Create and configure Neuron AI agents with providers, tools, instructions, and memory. Use this skill whenever the user mentions building agents, creating AI assistants, setting up LLM-powered chat bots, configuring chat agents, or wants to create an agent that can talk, use tools, or handle conversations. Also trigger for any task involving agent configuration, provider setup, tool integration, or chat history management in Neuron AI.
---

# Neuron AI Agent Builder

This skill helps you create and configure Neuron AI agents for building agentic applications in PHP.

## Core Agent Structure

A Neuron agent extends the `Agent` class and implements key methods:

```php
use NeuronAI\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;

class MyAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: 'ANTHROPIC_API_KEY',
            model: 'ANTHROPIC_MODEL',
        );
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                "You are a helpful AI assistant."
            ]
        );
    }
}
```

## Agent Execution Methods

### Chat Mode (Synchronous)
For standard back-and-forth conversations:

```php
$agent = MyAgent::make();
$response = $agent->chat(
    new UserMessage("Hello!")
)->getMessage();
echo $response->getContent();
```

### Stream Mode (Real-time)
For streaming responses as tokens arrive:

```php
foreach ($agent->stream(new UserMessage("Hello"))->events() as $event) {
    if ($event instanceof AIResponseChunkEvent) {
        echo $event->chunk;
    }
}
```

### Structured Output Mode
For extracting structured data from natural language:

```php
class Person
{
    #[SchemaProperty(description: 'The user name', required: true)]
    public string $name;

    #[SchemaProperty(description: 'What the user loves to eat')]
    public string $preference;
}

$person = $agent->structured(
    new UserMessage("I'm John and I like pizza!"),
    Person::class
);
```

## Providers Configuration

### Anthropic
```php
new Anthropic(
    key: $_ENV['ANTHROPIC_API_KEY'],
    model: 'claude-3-5-sonnet-20241022',
)
```

### OpenAI
```php
new OpenAI(
    key: $_ENV['OPENAI_API_KEY'],
    model: 'gpt-4',
)
```

### Ollama (Local)
```php
new Ollama(
    baseUrl: 'http://localhost:11434',
    model: 'llama3',
)
```

### Other Providers
- `Gemini` - Google AI models
- `Mistral` - Mistral AI models
- `HuggingFace` - Open models via HuggingFace
- `Deepseek` - DeepSeek models
- `Grok` - XAI models
- `AWSBedrockRuntime` - AWS Bedrock models
- `Cohere` - Cohere models

## Tools Integration

### Adding Built-in Toolkits

```php
use NeuronAI\Tools\Toolkits\MySQL\MySQLToolkit;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;

protected function tools(): array
{
    return [
        MySQLToolkit::make(\DB::connection()->getPdo()),
        CalculatorToolkit::make(),
    ];
}
```

### Available Toolkits
- **MySQLToolkit** - Database queries via MySQL
- **PostgreSQLToolkit** - Database queries via PostgreSQL
- **CalculatorToolkit** - Math operations (sum, mean, std, etc.)
- **TavilyToolkit** - Web search with Tavily
- **SESToolkit** - Email sending via AWS SES
- **JinaToolkit** - Reranking with Jina

### Creating Custom Tools

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class WeatherTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'get_weather',
            description: 'Get the current weather for a location',
            properties: [
                new ToolProperty(
                    name: 'location',
                    type: ToolPropertyType::String,
                    description: 'The city name',
                    required: true,
                ),
            ],
        );
    }

    public function execute(array $arguments): mixed
    {
        $location = $arguments['location'];
        // Call weather API and return result
        return "The weather in {$location} is sunny, 72°F";
    }
}
```

## System Prompt Engineering

Use `SystemPrompt` for structured agent instructions:

```php
new SystemPrompt(
    background: [
        "You are a data analyst expert in creating reports.",
    ],
    steps: [
        "Analyze the user's request",
        "Query the database",
        "Generate a summary",
    ],
    constraints: [
        "Always cite your sources",
        "Never make up data",
    ],
    outputFormat: [
        "Provide executive summary first",
        "Follow with detailed breakdown",
    ],
)
```

## Memory and Chat History

Agents automatically maintain conversation history. For custom memory:

```php
use NeuronAI\Agent\Memory\FileMemory;

// In agent class
protected function memory(): MemoryInterface
{
    return new FileMemory('/path/to/memory.json');
}
```

### Memory Types
- `InMemoryMemory` - Default, session-based
- `FileMemory` - Persist to file
- `SQLMemory` - Database-backed

## Content Blocks (Multi-modal)

Agents support multiple content types:

```php
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Enums\SourceType;

$message = new UserMessage([
    new TextContent('Analyze this image:'),
    new ImageContent(
        content: 'https://example.com/image.jpg',
        sourceType: SourceType::URL,
        mediaType: 'image/jpeg'
    ),
]);
```

## CLI Generation

Use the Neuron CLI to generate agent boilerplate:

```bash
php vendor/bin/neuron make:agent MyCustomAgent
```

## Common Patterns

### Tool Approval Middleware
For human oversight of tool execution:

```php
use NeuronAI\Agent\Middleware\ToolApproval;

// In agent constructor or configuration
$this->middleware(ToolApproval::class, new ToolApproval());
```

### Observability with Inspector
Monitor agent execution:

```bash
# Set environment variable
INSPECTOR_INGESTION_KEY=your_key_here
```

### Parallel Tool Calls
Execute tools in parallel (requires pcntl):

```php
$agent->setConfig(['parallelToolCalls' => true]);
```

## Key Decisions

When helping users build agents:

1. **Choose execution mode** based on requirements:
   - `chat()` for standard conversations
   - `stream()` for real-time streaming
   - `structured()` for data extraction

2. **Select provider** based on:
   - Cost considerations
   - Required features (e.g., tool calling support)
   - Latency requirements
   - Model capabilities

3. **Add tools** when agent needs to:
   - Access external systems (databases, APIs)
   - Perform calculations
   - Search the web
   - Send emails

4. **Configure memory** when:
   - Long-running conversations need persistence
   - Multiple sessions should share history
   - Memory needs to be shared across agents

5. **Use middleware** for:
   - Logging and monitoring
   - Tool approval workflows
   - Custom pre/post processing

## Project Structure Considerations

For Laravel projects:
```php
namespace App\Neuron;

class MyAgent extends Agent { ... }
```

For Symfony projects:
- Use dependency injection for providers
- Configure as service in services.yaml

## Testing

```php
use PHPUnit\Framework\TestCase;

class MyAgentTest extends TestCase
{
    public function testAgentChat(): void
    {
        $agent = MyAgent::make();
        $response = $agent->chat(
            new UserMessage('Hello')
        )->getMessage();

        $this->assertNotEmpty($response->getContent());
    }
}
```
