<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\TodoPlanning;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

require_once __DIR__ . '/../../vendor/autoload.php';

// Create example tools for a complex web development task
class CreateDatabaseSchemaTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'create_database_schema',
            'Create a database schema for the application'
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make('schema_name', PropertyType::STRING, 'The name of the database schema', true),
        ];
    }

    public function __invoke(string $schema_name): string
    {
        // Simulate database schema creation
        \usleep(500000); // 0.5 seconds
        return "Database schema '{$schema_name}' has been created successfully with tables: users, posts, comments, and tags.";
    }
}

class CreateApiEndpointTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'create_api_endpoint',
            'Create a REST API endpoint'
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make('endpoint_name', PropertyType::STRING, 'The name of the endpoint (e.g., /api/users)', true),
            ToolProperty::make('method', PropertyType::STRING, 'HTTP method (GET, POST, PUT, DELETE)', true),
        ];
    }

    public function __invoke(string $endpoint_name, string $method): string
    {
        \usleep(500000); // 0.5 seconds
        return "API endpoint '{$method} {$endpoint_name}' has been created with proper request validation and error handling.";
    }
}

class RunTestsTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'run_tests',
            'Run unit and integration tests'
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make('test_suite', PropertyType::STRING, 'The test suite to run (e.g., unit, integration, all)', true),
        ];
    }

    public function __invoke(string $test_suite): string
    {
        \usleep(800000); // 0.8 seconds
        return "Test suite '{$test_suite}' completed: 42 tests passed, 0 failed. Coverage: 87%.";
    }
}

class WriteDocumentationTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'write_documentation',
            'Write documentation for a feature or module'
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make('doc_name', PropertyType::STRING, 'The name of the documentation file', true),
            ToolProperty::make('content', PropertyType::STRING, 'Brief description of the content', true),
        ];
    }

    public function __invoke(string $doc_name, string $content): string
    {
        \usleep(500000); // 0.5 seconds
        return "Documentation '{$doc_name}' has been written covering: {$content}.";
    }
}

echo "=== Agent with TodoPlanning Middleware ===\n";
echo "-------------------------------------------------------------------\n\n";

// Create AI provider
$provider = new Anthropic(
    '',
    'claude-3-7-sonnet-latest'
);

// Create agent with TodoPlanning middleware attached to PrepareInferenceNode
$agent = Agent::make()
    ->setAiProvider($provider)
    ->setInstructions(
        'You are a senior software engineer. When given complex tasks, break them down into clear steps and track your progress using the todo planning tool.'
    )
    ->addTool([
        new CreateDatabaseSchemaTool(),
        new CreateApiEndpointTool(),
        new RunTestsTool(),
        new WriteDocumentationTool(),
    ])
    ->addMiddleware(
        ChatNode::class,
        new TodoPlanning()
    );

// Give the agent a complex task
$message = new UserMessage(
    'Build a complete blog system with the following requirements:
    1. Design and create a database schema for blog posts with comments and tags
    2. Create REST API endpoints for CRUD operations on posts
    3. Create API endpoints for comments and tags
    4. Run comprehensive tests to ensure everything works
    5. Write API documentation

    Please break this down into steps and complete each one systematically.'
);

echo "User Request:\n";
echo "─────────────\n";
echo \wordwrap($message->getTextContent(), 75) . "\n\n";

echo "Agent Execution:\n";
echo "────────────────\n\n";

try {
    $startTime = \microtime(true);

    // The agent will automatically use write_todos to track the task breakdown
    $response = $agent->chat(messages: $message);

    $duration = \round(\microtime(true) - $startTime, 2);

    echo "\n";
    echo "Agent Response:\n";
    echo "───────────────\n";
    echo \wordwrap($response->getTextContent(), 75) . "\n\n";

    echo "Execution completed in {$duration} seconds.\n";
} catch (\Exception $e) {
    echo "❌ Error: {$e->getMessage()}\n";
    echo "Stack trace:\n{$e->getTraceAsString()}\n";
}

echo "=== Example Complete ===\n";
