---
name: neuron-tool-creator
description: Create custom tools and toolkits for Neuron AI agents. Use this skill whenever the user mentions tools, functions, capabilities, agent actions, building tools, extending agent functionality, or wants to add custom capabilities to Neuron agents. Also trigger for tasks involving tool properties, tool execution, toolkits, MCP connector, or any request to make an agent able to perform specific actions.
---

# Neuron AI Tool Creator

This skill helps you create custom tools and toolkits for Neuron AI agents. Tools extend agent capabilities by allowing them to perform concrete actions like database queries, API calls, calculations, and more.

## Tool Basics

A tool is a class that implements `ToolInterface` and defines:

1. **Name** - Unique identifier
2. **Description** - What the tool does (helps the AI know when to use it)
3. **Properties** - Parameters the tool accepts
4. **Execute method** - The actual logic

### Creating a Simple Tool

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyType;

class WeatherTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'get_weather',
            description: 'Get the current weather for a specific location',
            properties: [
                new ToolProperty(
                    name: 'location',
                    type: ToolPropertyType::String,
                    description: 'The city name or zip code',
                    required: true,
                ),
            ],
        );
    }

    public function execute(array $arguments): mixed
    {
        $location = $arguments['location'];

        // Call weather API
        $weather = $this->fetchWeather($location);

        return [
            'location' => $location,
            'temperature' => $weather['temp'],
            'condition' => $weather['condition'],
            'humidity' => $weather['humidity'],
        ];
    }

    private function fetchWeather(string $location): array
    {
        // Weather API implementation
        return [
            'temp' => 72,
            'condition' => 'Sunny',
            'humidity' => 45,
        ];
    }
}
```

## Tool Properties

### Supported Property Types

```php
use NeuronAI\Tools\ToolPropertyType;

new ToolProperty(
    name: 'query',
    type: ToolPropertyType::String,  // String, Number, Boolean, Array, Object
    description: 'The search query',
    required: true,
    default: null,
    enum: ['option1', 'option2'],  // Optional: restrict to these values
);

new ToolProperty(
    name: 'limit',
    type: ToolPropertyType::Number,
    description: 'Maximum results to return',
    required: false,
    default: 10,
    minimum: 1,
    maximum: 100,
);

new ToolProperty(
    name: 'options',
    type: ToolPropertyType::Array,
    description: 'List of options',
    required: true,
    items: ToolPropertyType::String,  // Type of array items
);
```

### Object Properties

```php
new ToolProperty(
    name: 'user_data',
    type: ToolPropertyType::Object,
    description: 'User information',
    required: true,
    properties: [
        new ToolProperty(
            name: 'name',
            type: ToolPropertyType::String,
            description: 'User name',
            required: true,
        ),
        new ToolProperty(
            name: 'age',
            type: ToolPropertyType::Number,
            description: 'User age',
            required: false,
        ),
    ],
);
```

### Nested Properties

```php
new ToolProperty(
    name: 'filters',
    type: ToolPropertyType::Object,
    description: 'Search filters',
    required: false,
    properties: [
        new ToolProperty(
            name: 'date_range',
            type: ToolPropertyType::Object,
            description: 'Date range filter',
            required: false,
            properties: [
                new ToolProperty(
                    name: 'start',
                    type: ToolPropertyType::String,
                    description: 'Start date (YYYY-MM-DD)',
                    required: true,
                ),
                new ToolProperty(
                    name: 'end',
                    type: ToolPropertyType::String,
                    description: 'End date (YYYY-MM-DD)',
                    required: true,
                ),
            ],
        ),
    ],
);
```

## Tool Configuration

### Visibility Control

```php
class HiddenTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'internal_operation',
            description: 'Internal tool not exposed to users',
            properties: [],
            visible: false,  // Tool won't appear in function calling
        );
    }
}
```

### Execution Limits

```php
class LimitedTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'expensive_operation',
            description: 'A costly operation',
            properties: [],
            maxRuns: 1,  // Can only run once per conversation
        );
    }
}
```

### Dependency Injection

```php
class DatabaseTool extends Tool
{
    public function __construct(
        private \PDO $pdo
    ) {
        parent::__construct(
            name: 'query_database',
            description: 'Execute a SQL query on the database',
            properties: [
                new ToolProperty(
                    name: 'query',
                    type: ToolPropertyType::String,
                    description: 'The SQL query to execute',
                    required: true,
                ),
            ],
        );
    }

    public function execute(array $arguments): mixed
    {
        $stmt = $this->pdo->prepare($arguments['query']);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
```

## Adding Tools to Agents

### Single Tool

```php
use NeuronAI\Agent;

class MyAgent extends Agent
{
    protected function tools(): array
    {
        return [
            new WeatherTool(),
        ];
    }
}
```

### Multiple Tools

```php
protected function tools(): array
{
    return [
        new WeatherTool(),
        new DatabaseTool(\DB::connection()->getPdo()),
        new CalculatorTool(),
    ];
}
```

### Dynamic Tool Addition

```php
$agent = MyAgent::make();
$agent->addTool(new CustomTool());
```

## Creating Toolkits

A toolkit is a collection of related tools.

### Basic Toolkit Pattern

```php
use NeuronAI\Tools\Toolkit;

class EcommerceToolkit extends Toolkit
{
    public function __construct(
        private \PDO $pdo,
        private string $apiKey
    ) {}

    public function tools(): array
    {
        return [
            new GetProductTool($this->pdo),
            new UpdateInventoryTool($this->pdo),
            new SendEmailTool($this->apiKey),
        ];
    }
}

// Individual tools
class GetProductTool extends Tool
{
    public function __construct(private \PDO $pdo)
    {
        parent::__construct(
            name: 'get_product',
            description: 'Get product information by ID',
            properties: [
                new ToolProperty(
                    name: 'product_id',
                    type: ToolPropertyType::Number,
                    description: 'The product ID',
                    required: true,
                ),
            ],
        );
    }

    public function execute(array $arguments): mixed
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM products WHERE id = :id'
        );
        $stmt->execute(['id' => $arguments['product_id']]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
```

### Using Static Constructor

```php
use NeuronAI\StaticConstructor;

class EcommerceToolkit extends Toolkit
{
    use StaticConstructor;

    public static function make(\PDO $pdo, string $apiKey): self
    {
        return new self($pdo, $apiKey);
    }
}

// Usage
protected function tools(): array
{
    return [
        EcommerceToolkit::make(\DB::connection()->getPdo(), $_ENV['API_KEY']),
    ];
}
```

## MCP Connector

Instead of implementing tools manually, you can connect to MCP servers that expose tools.

### Basic MCP Connection

```php
use NeuronAI\MCP\McpConnector;

protected function tools(): array
{
    return [
        ...McpConnector::make([
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-everything'],
        ])->tools(),
    ];
}
```

### Local MCP Server

```php
protected function tools(): array
{
    return [
        ...McpConnector::make([
            'command' => 'node',
            'args' => ['/path/to/local-mcp-server/index.js'],
        ])->tools(),
    ];
}
```

### Multiple MCP Servers

```php
protected function tools(): array
{
    $server1 = McpConnector::make([
        'command' => 'npx',
        'args' => ['-y', '@modelcontextprotocol/server-filesystem'],
        'env' => ['ALLOWED_DIRECTORIES' => './docs'],
    ]);

    $server2 = McpConnector::make([
        'command' => 'npx',
        'args' => ['-y', '@modelcontextprotocol/server-postgres'],
    ]);

    return [
        ...$server1->tools(),
        ...$server2->tools(),
    ];
}
```

## Common Tool Patterns

### API Wrapper Tool

```php
class APICallTool extends Tool
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey
    ) {
        parent::__construct(
            name: 'api_call',
            description: 'Make an API call to external service',
            properties: [
                new ToolProperty(
                    name: 'endpoint',
                    type: ToolPropertyType::String,
                    description: 'The API endpoint',
                    required: true,
                ),
                new ToolProperty(
                    name: 'method',
                    type: ToolPropertyType::String,
                    description: 'HTTP method (GET, POST, PUT, DELETE)',
                    required: false,
                    default: 'GET',
                    enum: ['GET', 'POST', 'PUT', 'DELETE'],
                ),
                new ToolProperty(
                    name: 'data',
                    type: ToolPropertyType::Object,
                    description: 'Request body for POST/PUT',
                    required: false,
                ),
            ],
        );
    }

    public function execute(array $arguments): mixed
    {
        $client = new \GuzzleHttp\Client();
        $response = $client->request(
            $arguments['method'],
            $this->baseUrl . $arguments['endpoint'],
            [
                'headers' => ['Authorization' => "Bearer {$this->apiKey}"],
                'json' => $arguments['data'] ?? [],
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }
}
```

### Database Query Tool

```php
class DatabaseQueryTool extends Tool
{
    public function __construct(private \PDO $pdo)
    {
        parent::__construct(
            name: 'execute_sql',
            description: 'Execute a SQL SELECT query on the database',
            properties: [
                new ToolProperty(
                    name: 'query',
                    type: ToolPropertyType::String,
                    description: 'The SQL SELECT query to execute',
                    required: true,
                ),
                new ToolProperty(
                    name: 'params',
                    type: ToolPropertyType::Object,
                    description: 'Query parameters for prepared statement',
                    required: false,
                ),
            ],
        );
    }

    public function execute(array $arguments): mixed
    {
        // Security: Only allow SELECT queries
        $query = trim($arguments['query']);
        if (!preg_match('/^SELECT\s/i', $query)) {
            throw new \RuntimeException('Only SELECT queries are allowed');
        }

        $stmt = $this->pdo->prepare($query);
        $params = $arguments['params'] ?? [];
        $stmt->execute($params);

        return [
            'columns' => array_keys($stmt->fetch(\PDO::FETCH_ASSOC) ?: []),
            'rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
            'count' => $stmt->rowCount(),
        ];
    }
}
```

### File Operations Tool

```php
class FileReadTool extends Tool
{
    public function __construct(
        private string $allowedDir
    ) {
        parent::__construct(
            name: 'read_file',
            description: 'Read the contents of a text file',
            properties: [
                new ToolProperty(
                    name: 'path',
                    type: ToolPropertyType::String,
                    description: 'File path relative to allowed directory',
                    required: true,
                ),
            ],
        );
    }

    public function execute(array $arguments): mixed
    {
        $fullPath = $this->allowedDir . '/' . $arguments['path'];

        // Security: Prevent directory traversal
        $realPath = realpath($fullPath);
        $realAllowed = realpath($this->allowedDir);

        if (strpos($realPath, $realAllowed) !== 0) {
            throw new \RuntimeException('Access denied: path outside allowed directory');
        }

        return [
            'content' => file_get_contents($fullPath),
            'size' => filesize($fullPath),
            'modified' => date('Y-m-d H:i:s', filemtime($fullPath)),
        ];
    }
}
```

## Tool Execution Modes

### Sequential (Default)

Tools execute one after another:

```php
$agent = MyAgent::make();
$response = $agent->chat(new UserMessage("Query the database and send an email"));
// Tools execute sequentially
```

### Parallel

Execute tools concurrently (requires pcntl extension):

```php
$agent = MyAgent::make();
$agent->setConfig(['parallelToolCalls' => true]);

$response = $agent->chat(new UserMessage("Check weather and get stock price"));
// Both tools execute in parallel
```

## Tool Approval Middleware

For human oversight of tool execution:

```php
use NeuronAI\Agent\Middleware\ToolApproval;

$agent = MyAgent::make();
$agent->middleware(ToolApproval::class, new ToolApproval());

try {
    $response = $agent->chat(new UserMessage("Delete all data"));
} catch (\NeuronAI\Workflow\WorkflowInterrupt $interrupt) {
    // Present tools for approval to user
    $approvalRequest = $interrupt->getRequest();

    // After user approval
    $resumeRequest = $this->getUserApproval($approvalRequest);
    $response = $agent->resume($resumeRequest);
}
```

## CLI Generation

```bash
php vendor/bin/neuron make:tool MyCustomTool
```

## Testing Tools

```php
use PHPUnit\Framework\TestCase;

class WeatherToolTest extends TestCase
{
    public function testWeatherToolExecution(): void
    {
        $tool = new WeatherTool();
        $result = $tool->execute(['location' => 'New York']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('temperature', $result);
        $this->assertArrayHasKey('location', $result);
        $this->assertEquals('New York', $result['location']);
    }
}
```

## Best Practices

### Tool Descriptions
- Be specific about what the tool does
- Include when to use the tool
- Mention any constraints or limitations
- Use clear, concise language

### Property Definitions
- Use `required: false` for optional parameters with sensible defaults
- Provide helpful descriptions for each property
- Use enums when values should be from a specific set
- Validate inputs in the execute method

### Security
- Always validate and sanitize user inputs
- Use prepared statements for database queries
- Implement proper error handling
- Consider rate limiting for external API calls

### Error Handling

```php
public function execute(array $arguments): mixed
{
    try {
        // Tool logic
        return $result;
    } catch (\Exception $e) {
        return [
            'error' => $e->getMessage(),
            'suggestion' => 'Please check your input and try again.',
        ];
    }
}
```

## Built-in Toolkits Reference

The framework includes these ready-to-use toolkits:

### Calculator Toolkit
- `add` - Add numbers
- `subtract` - Subtract numbers
- `multiply` - Multiply numbers
- `divide` - Divide numbers
- `mean` - Calculate average
- `std` - Calculate standard deviation

### MySQL Toolkit
- `query` - Execute SQL queries
- `table_info` - Get table structure

### PostgreSQL Toolkit
- Same as MySQL toolkit

### Tavily Toolkit
- `search` - Web search with Tavily API

### SES Toolkit
- `send_email` - Send emails via AWS SES

### Jina Toolkit
- `rerank` - Rerank search results
