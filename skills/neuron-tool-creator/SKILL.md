---
name: neuron-tool-creator
description: Create custom tools, toolkits, and MCP integrations for Neuron AI agents. Use this skill when the user mentions creating tools, building toolkits, extending Tool class, defining tool properties, implementing tool execution, MCP server integration, Model Context Protocol, connecting external tools, or tool guidelines. Also trigger for any task involving ToolProperty, ArrayProperty, ObjectProperty, AbstractToolkit, McpConnector, or StdioTransport/SseHttpTransport/StreamableHttpTransport.
---

# Neuron AI Tool Creator

This skill helps you create custom tools, toolkits, and MCP integrations for Neuron AI agents.

## Core Concepts

Tools give agents the ability to:
- Execute actions (API calls, database queries, file operations)
- Retrieve information (web search, data lookup)
- Interact with external systems

Every tool has:
- **Name**: Unique identifier
- **Description**: Explains what the tool does (critical for LLM)
- **Properties**: Input parameters with types and descriptions
- **Execution**: The actual logic to run

## Creating Custom Tools

### Method 1: Extend Tool Class with `__invoke`

The cleanest approach for complex tools:

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

class WeatherTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'get_weather',
            description: 'Get the current weather for a location. Returns temperature, conditions, and humidity.'
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'location',
                type: PropertyType::STRING,
                description: 'The city and country, e.g., "Paris, France"',
                required: true
            ),
            ToolProperty::make(
                name: 'units',
                type: PropertyType::STRING,
                description: 'Temperature units: "celsius" or "fahrenheit"',
                required: false,
                enum: ['celsius', 'fahrenheit']
            ),
        ];
    }

    public function __invoke(string $location, ?string $units = 'celsius'): string
    {
        // Your API call or logic here
        $weatherData = $this->fetchWeather($location, $units);

        return json_encode($weatherData);
    }

    private function fetchWeather(string $location, string $units): array
    {
        // Implementation...
        return [
            'location' => $location,
            'temperature' => 22,
            'units' => $units,
            'conditions' => 'sunny',
        ];
    }
}
```

### Method 2: Fluent Builder with `setCallable`

For simpler tools or closures:

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

$weatherTool = Tool::make('get_weather', 'Get weather for a location')
    ->addProperty(
        ToolProperty::make(
            name: 'location',
            type: PropertyType::STRING,
            description: 'City name',
            required: true
        )
    )
    ->setCallable(function (string $location): string {
        // Your logic here
        return "Weather in {$location}: Sunny, 22°C";
    });
```

### Method 3: Class with Dependencies

For tools that need external dependencies (database, API client):

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use PDO;

class DatabaseQueryTool extends Tool
{
    public function __construct(protected PDO $pdo)
    {
        parent::__construct(
            name: 'query_users',
            description: 'Query user data from the database'
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'email',
                type: PropertyType::STRING,
                description: 'User email to search for',
                required: false
            ),
            ToolProperty::make(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Maximum number of results',
                required: false
            ),
        ];
    }

    public function __invoke(?string $email = null, ?int $limit = 10): array
    {
        $query = "SELECT * FROM users";

        if ($email) {
            $query .= " WHERE email LIKE :email";
        }

        $query .= " LIMIT :limit";

        $stmt = $this->pdo->prepare($query);

        if ($email) {
            $stmt->bindValue(':email', "%{$email}%");
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
```

## Property Types

### Basic Types

```php
use NeuronAI\Tools\PropertyType;

PropertyType::STRING;   // Text values
PropertyType::INTEGER;  // Whole numbers
PropertyType::NUMBER;   // Floats/decimals
PropertyType::BOOLEAN;  // true/false
PropertyType::ARRAY;    // Lists
PropertyType::OBJECT;   // Key-value objects
```

### ToolProperty (Scalar Values)

```php
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

// Basic property
new ToolProperty(
    name: 'query',
    type: PropertyType::STRING,
    description: 'Search query',
    required: true
);

// Property with enum constraints
new ToolProperty(
    name: 'sort_order',
    type: PropertyType::STRING,
    description: 'Sort direction',
    required: false,
    enum: ['asc', 'desc']
);

// Integer with description
new ToolProperty(
    name: 'limit',
    type: PropertyType::INTEGER,
    description: 'Maximum results (1-100)',
    required: false
);
```

### ArrayProperty (Lists)

```php
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

// Array of strings
new ArrayProperty(
    name: 'tags',
    description: 'List of tags to filter by',
    required: false,
    items: new ToolProperty(
        name: 'tag',
        type: PropertyType::STRING,
        description: 'Single tag'
    )
);

// Array with constraints
new ArrayProperty(
    name: 'ids',
    description: 'List of user IDs',
    required: true,
    items: new ToolProperty(
        name: 'id',
        type: PropertyType::INTEGER,
        description: 'User ID'
    ),
    minItems: 1,
    maxItems: 100
);
```

### ObjectProperty (Complex Objects)

```php
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

// Inline object definition
new ObjectProperty(
    name: 'address',
    description: 'User address',
    required: true,
    properties: [
        new ToolProperty('street', PropertyType::STRING, 'Street name', true),
        new ToolProperty('city', PropertyType::STRING, 'City name', true),
        new ToolProperty('zip', PropertyType::STRING, 'Postal code', false),
    ]
);

// Object mapped to a PHP class (auto-deserialization)
new ObjectProperty(
    name: 'user',
    description: 'User object',
    required: true,
    class: User::class  // Auto-generates schema from class
);
```

### Nested Complex Properties

```php
// Array of objects
new ArrayProperty(
    name: 'contacts',
    description: 'List of contacts',
    required: true,
    items: new ObjectProperty(
        name: 'contact',
        properties: [
            new ToolProperty('name', PropertyType::STRING, 'Contact name', true),
            new ToolProperty('email', PropertyType::STRING, 'Email address', true),
        ]
    )
);
```

## Tool Execution

### Return Values

Tools must return a string or stringifiable value:

```php
// String
public function __invoke(string $query): string
{
    return "Result: {$query}";
}

// Array (auto-converted to JSON)
public function __invoke(string $query): array
{
    return ['status' => 'success', 'data' => []];
}

// Object with __toString
public function __invoke(): Stringable
{
    return new class implements Stringable {
        public function __toString(): string {
            return 'result';
        }
    };
}
```

### Accessing Inputs Directly

```php
public function __invoke(string $query, ?string $filter = null): string
{
    // Access individual input
    $value = $this->getInput('query');

    // Access all inputs
    $allInputs = $this->getInputs();

    // Check if input exists
    if ($this->getInput('filter') !== null) {
        // ...
    }
}
```

### Error Handling

```php
public function __invoke(string $url): string
{
    try {
        $response = $this->httpClient->get($url);
        return $response->getBody()->getContents();
    } catch (\Exception $e) {
        // Return error message for the LLM to understand
        return "Error fetching URL: {$e->getMessage()}";
    }
}
```

## Tool Visibility

Hidden tools are executable but not shown to the LLM:

```php
// Visible to LLM (default)
$tool->visible(true);

// Hidden from LLM schema but still callable
$tool->visible(false);
```

Use case: Internal tools called by other tools, not directly by the agent.

## Max Runs

Limit how many times a tool can be called in a single session:

```php
$tool->setMaxRuns(5);  // Maximum 5 calls per session
```

## Creating Toolkits

Toolkits group related tools together with shared context.

### Basic Toolkit

```php
use NeuronAI\Tools\Toolkits\AbstractToolkit;

class CalculatorToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return "This toolkit allows you to perform mathematical operations.
        You can use these functions to solve mathematical expressions
        step by step to calculate the final result.";
    }

    public function provide(): array
    {
        return [
            SumTool::make(),
            SubtractTool::make(),
            MultiplyTool::make(),
            DivideTool::make(),
        ];
    }
}
```

### Toolkit with Dependencies

```php
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use PDO;

class MySQLToolkit extends AbstractToolkit
{
    public function __construct(protected PDO $pdo)
    {
    }

    public function guidelines(): ?string
    {
        return "These tools allow you to learn the database structure,
        getting detailed information about tables, columns, relationships,
        and constraints to generate and execute precise SQL queries.";
    }

    public function provide(): array
    {
        return [
            MySQLSchemaTool::make($this->pdo),
            MySQLSelectTool::make($this->pdo),
            MySQLWriteTool::make($this->pdo),
        ];
    }
}
```

### Using Toolkits

```php
use NeuronAI\Agent\Agent;

class MyAgent extends Agent
{
    protected function tools(): array
    {
        return [
            // Use full toolkit
            ...CalculatorToolkit::make(),

            // Use toolkit with dependencies
            ...MySQLToolkit::make($this->pdo),
        ];
    }
}
```

### Toolkit Filtering

Control which tools are exposed:

```php
// Exclude specific tools
...MySQLToolkit::make($pdo)
    ->exclude([MySQLWriteTool::class]),

// Include only specific tools
...MySQLToolkit::make($pdo)
    ->only([MySQLSchemaTool::class, MySQLSelectTool::class]),

// Configure tools dynamically
...MyToolkit::make()
    ->with(ExpensiveTool::class, function (Tool $tool): Tool {
        $tool->setMaxRuns(1);  // Limit expensive operations
        return $tool;
    }),
```

## MCP (Model Context Protocol) Integration

MCP allows connecting to external tool servers.

### Local MCP Server (Stdio)

```php
use NeuronAI\MCP\McpConnector;

// Connect to local MCP server
$mcpTools = McpConnector::make([
    'command' => 'npx',
    'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/path/to/dir'],
])->tools();
```

### HTTP MCP Server

```php
use NeuronAI\MCP\McpConnector;

// Streamable HTTP (synchronous, recommended)
$mcpTools = McpConnector::make([
    'url' => 'https://mcp.example.com',
    'timeout' => 30,
])->tools();

// SSE HTTP (asynchronous)
$mcpTools = McpConnector::make([
    'url' => 'https://mcp.example.com/sse',
    'async' => true,
    'timeout' => 30,
])->tools();
```

### MCP with Authentication

```php
// Bearer token authentication
$mcpTools = McpConnector::make([
    'url' => 'https://mcp.example.com',
    'token' => 'your-api-token',
])->tools();

// Custom headers
$mcpTools = McpConnector::make([
    'url' => 'https://mcp.example.com',
    'headers' => [
        'X-API-Key' => 'your-key',
        'X-Custom-Header' => 'value',
    ],
])->tools();
```

### MCP with Environment Variables

```php
$mcpTools = McpConnector::make([
    'command' => 'node',
    'args' => ['server.js'],
    'env' => [
        'API_KEY' => $_ENV['API_KEY'],
        'DEBUG' => 'true',
    ],
])->tools();
```

### MCP Tool Filtering

```php
// Exclude specific tools
$mcpTools = McpConnector::make([
    'command' => 'npx',
    'args' => ['-y', '@modelcontextprotocol/server-everything'],
])
    ->exclude(['dangerous_tool', 'admin_tool'])
    ->tools();

// Include only specific tools
$mcpTools = McpConnector::make([
    'url' => 'https://mcp.example.com',
])
    ->only(['search', 'read', 'write'])
    ->tools();
```

### Using MCP Tools in Agent

```php
class MyAgent extends Agent
{
    protected function tools(): array
    {
        return [
            // Custom tools
            new MyCustomTool(),

            // MCP server tools
            ...McpConnector::make([
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/data'],
            ])->tools(),

            // HTTP MCP server
            ...McpConnector::make([
                'url' => 'https://api.example.com/mcp',
                'token' => $_ENV['MCP_TOKEN'],
            ])->tools(),
        ];
    }
}
```

## Best Practices

### 1. Write Clear Descriptions

The LLM relies on descriptions to understand when and how to use tools:

```php
// BAD - Vague
description: 'Search function'

// GOOD - Clear and actionable
description: 'Search the company knowledge base for documents, FAQs, and policies.
Returns relevant excerpts with source URLs. Use this when the user asks about
company procedures, policies, or documented information.'
```

### 2. Use Property Descriptions

```php
// BAD
ToolProperty::make('query', PropertyType::STRING, 'Query', true)

// GOOD
ToolProperty::make(
    name: 'query',
    type: PropertyType::STRING,
    description: 'Natural language search query. Be specific and include key terms.
    Example: "vacation policy for remote employees"',
    required: true
)
```

### 3. Use Enums for Constrained Values

```php
ToolProperty::make(
    name: 'sort_by',
    type: PropertyType::STRING,
    description: 'Field to sort results by',
    required: false,
    enum: ['date', 'relevance', 'popularity']
)
```

### 4. Return Structured Data

```php
public function __invoke(string $query): string
{
    $results = $this->search($query);

    // Return structured JSON
    return json_encode([
        'success' => true,
        'query' => $query,
        'count' => count($results),
        'results' => $results,
    ]);
}
```

### 5. Handle Errors Gracefully

```php
public function __invoke(string $url): string
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return json_encode([
            'success' => false,
            'error' => 'Invalid URL format',
            'hint' => 'Please provide a valid URL starting with http:// or https://'
        ]);
    }

    // ...
}
```

### 6. Validate Inputs

```php
public function __invoke(string $email, int $limit = 10): string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Invalid email format: {$email}";
    }

    if ($limit < 1 || $limit > 100) {
        return "Limit must be between 1 and 100, got: {$limit}";
    }

    // ...
}
```

### 7. Use Type Hints

```php
// Use specific types in __invoke signature
public function __invoke(
    string $query,
    ?int $limit = 10,
    bool $includeMetadata = false
): string {
    // ...
}
```

## Available Built-in Toolkits

| Toolkit | Purpose |
|---------|---------|
| `CalculatorToolkit` | Math operations (sum, subtract, multiply, divide, etc.) |
| `MySQLToolkit` | MySQL database queries |
| `PostgreSQLToolkit` | PostgreSQL database queries |
| `FileSystemToolkit` | File operations (read, write, edit, delete, glob) |
| `TavilyToolkit` | Web search and crawling |
| `JinaToolkit` | URL reading and web search |
| `SESToolkit` | AWS SES email sending |
| `CalendarToolkit` | Date/time operations |
| `SupadataYouTubeToolkit` | YouTube video metadata and transcripts |

## CLI Generation

Generate tool boilerplate:

```bash
php vendor/bin/neuron make:tool WeatherTool
```

## Complete Example: API Tool

```php
<?php

declare(strict_types=1);

namespace App\Neuron\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use GuzzleHttp\Client;

class GitHubSearchTool extends Tool
{
    private Client $client;

    public function __construct()
    {
        parent::__construct(
            name: 'github_search',
            description: 'Search GitHub repositories. Returns repository names, descriptions, stars, and URLs.'
        );

        $this->client = new Client([
            'base_uri' => 'https://api.github.com',
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'NeuronAI-Agent',
            ],
        ]);
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'query',
                type: PropertyType::STRING,
                description: 'Search query. Use GitHub search syntax (e.g., "language:php stars:>100")',
                required: true
            ),
            ToolProperty::make(
                name: 'sort',
                type: PropertyType::STRING,
                description: 'Sort field',
                required: false,
                enum: ['stars', 'forks', 'updated']
            ),
            ToolProperty::make(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Maximum results (1-100)',
                required: false
            ),
        ];
    }

    public function __invoke(
        string $query,
        ?string $sort = 'stars',
        ?int $limit = 10
    ): string {
        $limit = min(max($limit ?? 10, 1), 100);

        try {
            $response = $this->client->get('/search/repositories', [
                'query' => [
                    'q' => $query,
                    'sort' => $sort,
                    'per_page' => $limit,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            $results = array_map(function ($repo) {
                return [
                    'name' => $repo['full_name'],
                    'description' => $repo['description'] ?? 'No description',
                    'stars' => $repo['stargazers_count'],
                    'language' => $repo['language'],
                    'url' => $repo['html_url'],
                ];
            }, $data['items'] ?? []);

            return json_encode([
                'success' => true,
                'count' => count($results),
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            return json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

## Testing Tools

```php
use PHPUnit\Framework\TestCase;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;

class WeatherToolTest extends TestCase
{
    public function test_tool_properties(): void
    {
        $tool = new WeatherTool();

        $this->assertEquals('get_weather', $tool->getName());
        $this->assertStringContainsString('weather', $tool->getDescription());

        $properties = $tool->getProperties();
        $this->assertCount(2, $properties);

        $requiredProps = $tool->getRequiredProperties();
        $this->assertContains('location', $requiredProps);
        $this->assertNotContains('units', $requiredProps);
    }

    public function test_tool_execution(): void
    {
        $tool = new WeatherTool();
        $tool->setInputs(['location' => 'Paris, France']);

        $tool->execute();

        $result = json_decode($tool->getResult(), true);
        $this->assertArrayHasKey('temperature', $result);
        $this->assertEquals('Paris, France', $result['location']);
    }
}
```
