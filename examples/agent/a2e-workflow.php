<?php

declare(strict_types=1);

/**
 * Secure Declarative Workflow Execution with php-a2e
 *
 * Demonstrates how an AI agent can execute complex multi-step workflows
 * WITHOUT arbitrary code execution. The agent generates a declarative
 * JSONL workflow, A2E validates it through 6 security stages, then
 * executes only pre-approved operations.
 *
 * This is fundamentally different from tool calls: the agent describes
 * the entire workflow upfront, and A2E validates the full graph before
 * executing anything.
 *
 * Install: composer require mauricioperera/php-a2e
 * Docs:    https://github.com/MauricioPerera/php-a2e
 */

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPA2E\A2E;
use PHPA2E\Config;

require_once __DIR__ . '/../../vendor/autoload.php';

// --- Setup A2E executor ---

$a2e = new A2E(new Config(
    dataDir: __DIR__ . '/data/a2e',
    masterKey: \getenv('A2E_MASTER_KEY') ?: 'dev-key-change-in-production',
));

// --- Define A2E tools for the agent ---

class ValidateWorkflowTool extends Tool
{
    public function __construct(private A2E $a2e)
    {
        parent::__construct(
            'validate_workflow',
            'Validate a JSONL workflow before execution. Returns validation result with any errors or warnings.'
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make('workflow', PropertyType::STRING, 'The JSONL workflow string to validate', true),
        ];
    }

    public function __invoke(string $workflow): string
    {
        $result = $this->a2e->validate($workflow);
        return \json_encode([
            'valid' => $result->valid,
            'errors' => $result->errors,
            'warnings' => $result->warnings,
            'issues' => $result->issues,
        ]);
    }
}

class ExecuteWorkflowTool extends Tool
{
    public function __construct(private A2E $a2e)
    {
        parent::__construct(
            'execute_workflow',
            'Execute a validated JSONL workflow. Operations run in declared order with automatic data flow between steps.'
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make('workflow', PropertyType::STRING, 'The JSONL workflow string to execute', true),
        ];
    }

    public function __invoke(string $workflow): string
    {
        $result = $this->a2e->execute(
            jsonl: $workflow,
            agentId: 'neuron-agent',
            validateFirst: true,
        );

        return \json_encode([
            'status' => $result->status,
            'duration_ms' => $result->durationMs,
            'results' => $result->results,
        ]);
    }
}

class ListCapabilitiesTool extends Tool
{
    public function __construct(private A2E $a2e)
    {
        parent::__construct(
            'list_capabilities',
            'List all available A2E operations the agent can use in workflows'
        );
    }

    protected function properties(): array
    {
        return [];
    }

    public function __invoke(): string
    {
        $ops = $this->a2e->operations->all();
        $capabilities = [];
        foreach ($ops as $op) {
            $capabilities[] = $op->type();
        }
        return \json_encode($capabilities);
    }
}


echo "=== Pattern 1: Agent-generated workflow ===\n";
echo "-------------------------------------------------------------------\n\n";

// The agent decides what workflow to build based on user intent.
// A2E ensures only pre-approved operations can execute.

$agent = Agent::make()
    ->setAiProvider(
        new Anthropic(
            \getenv('ANTHROPIC_API_KEY') ?: '',
            'claude-3-5-haiku-20241022'
        )
    )
    ->setInstructions(
        "You are an assistant that executes tasks using declarative JSONL workflows via A2E.\n"
        . "When the user asks you to do something, build a JSONL workflow using available operations.\n"
        . "Always validate the workflow before executing it.\n\n"
        . "JSONL format — each line is a JSON object:\n"
        . '{"type":"operationUpdate","operationId":"<id>","operation":{"<OpType>":{<config>}}}' . "\n"
        . '{"type":"beginExecution","executionId":"<id>","operationOrder":["op1","op2"]}' . "\n\n"
        . "Available operations: ApiCall (HTTP requests), FilterData, TransformData, "
        . "Calculate, FormatText, MergeData, Conditional, Loop, ValidateData.\n"
        . "Data flows via paths like /workflow/mydata — set outputPath in one op, use inputPath in the next."
    )
    ->addTool(new ListCapabilitiesTool($a2e))
    ->addTool(new ValidateWorkflowTool($a2e))
    ->addTool(new ExecuteWorkflowTool($a2e));

$response = $agent->chat(
    new UserMessage(
        'Fetch the list of users from https://jsonplaceholder.typicode.com/users, '
        . 'filter only those with an id less than or equal to 5, '
        . 'then count how many there are.'
    )
)->getMessage();

echo "Agent: " . $response->getContent() . "\n\n";


echo "=== Pattern 2: Pre-built workflow with agent routing ===\n";
echo "-------------------------------------------------------------------\n\n";

// Pre-defined workflows that the agent selects based on user intent.
// Useful for production: workflows are version-controlled, not LLM-generated.

$workflows = [
    'check-api-health' => \implode("\n", [
        \json_encode(['type' => 'operationUpdate', 'operationId' => 'ping',
            'operation' => ['ApiCall' => [
                'method' => 'GET',
                'url' => 'https://jsonplaceholder.typicode.com/posts/1',
                'outputPath' => '/workflow/health',
            ]]]),
        \json_encode(['type' => 'operationUpdate', 'operationId' => 'timestamp',
            'operation' => ['GetCurrentDateTime' => [
                'timezone' => 'UTC',
                'outputPath' => '/workflow/checked_at',
            ]]]),
        \json_encode(['type' => 'beginExecution', 'executionId' => 'health-check',
            'operationOrder' => ['ping', 'timestamp']]),
    ]),
];

// Validate and execute a pre-built workflow
$validation = $a2e->validate($workflows['check-api-health']);
echo "Validation: " . ($validation->valid ? 'PASSED' : 'FAILED') . "\n";

if ($validation->valid) {
    $result = $a2e->execute($workflows['check-api-health']);
    echo "Status: {$result->status}\n";
    echo "Duration: {$result->durationMs}ms\n";
    echo "Operations executed: " . \count($result->results) . "\n";
}

echo "\n=== Example Complete ===\n";
