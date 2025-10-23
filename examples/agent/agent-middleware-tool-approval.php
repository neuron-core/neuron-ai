<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\ToolApprovalMiddleware;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\WorkflowInterrupt;

require_once __DIR__ . '/../../vendor/autoload.php';

// Create some example tools that we want to gate with approval
class FileDeleteTool extends Tool
{
    public function __construct(
        public string $filePath = ''
    ) {
        parent::__construct(
            'delete_file',
            'Delete a file from the filesystem'
        );
    }

    public function __invoke(): string
    {
        $this->result = "File '{$this->filePath}' has been deleted.";
        return "  [TOOL EXECUTED] Deleted file: {$this->filePath}";
    }
}

class FileReadTool extends Tool
{
    public function __construct(
        public string $filePath = ''
    ) {
        parent::__construct(
            'file_read',
            'Read the contents of a file'
        );
    }

    public function __invoke(): string
    {
        $this->result = "Contents of '{$this->filePath}': Sample file content...";
        return "  [TOOL EXECUTED] Read file: {$this->filePath}";
    }
}

class CommandExecuteTool extends Tool
{
    public function __construct(
        public string $command = ''
    ) {
        parent::__construct(
            'execute_command',
            'Execute a system command'
        );
    }

    public function __invoke(): string
    {
        $this->result = "Command '{$this->command}' executed successfully.";
        return "  [TOOL EXECUTED] Executed command: {$this->command}";
    }
}

$provider = new Anthropic\Anthropic(
    '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',
    'claude-3-7-sonnet-latest'
);
$persistence = new FilePersistence(__DIR__);
$workflowId = 'agent_with_tool_approval_' . \uniqid();

echo "=== Agent Middleware: Tool Approval Example ===\n\n";

// Create agent with ToolApprovalMiddleware
// Only 'delete_file' and 'execute_command' require approval
$agent = Agent::make()
    ->setAiProvider($provider)
    ->setInstructions('You are a helpful assistant with access to file and command tools. Be concise.')
    ->addTool([
        new FileReadTool(),
        new FileDeleteTool(),
        new CommandExecuteTool(),
    ])
    ->middleware(
        ToolCallEvent::class,
        new ToolApprovalMiddleware(['delete_file', 'execute_command'])
    );

// Scenario 1: Safe operation (no approval needed)
echo "Scenario 1: Safe operation (read_file) - No approval needed\n";
echo "-----------------------------------------------------------\n";

try {
    $message = UserMessage::make('Read the config.json file');
    echo "User: {$message->getContent()}\n\n";

    $response = $agent->chat($message);
    echo "Agent: {$response->getContent()}\n\n";
} catch (\Exception $e) {
    echo "Error: {$e->getMessage()}\n\n";
}

// Scenario 2: Dangerous operation (requires approval)
echo "\nScenario 2: Dangerous operation (delete_file) - Requires approval\n";
echo "-------------------------------------------------------------------\n";

// Reset state for new conversation
$agent = Agent::make()
    ->setAiProvider($provider)
    ->setInstructions('You are a helpful assistant with access to file and command tools. Be concise.')
    ->addTool([
        new FileReadTool(),
        new FileDeleteTool(),
        new CommandExecuteTool(),
    ])
    ->middleware(
        ToolCallEvent::class,
        new ToolApprovalMiddleware(['delete_file', 'execute_command'])
    );

try {
    $message = new UserMessage('Delete the old_logs.txt file');
    echo "User: {$message->getContent()}\n\n";

    $response = $agent->chat($message);
    echo "Agent: {$response->getContent()}\n\n";

} catch (WorkflowInterrupt $interrupt) {
    echo "⚠️  WORKFLOW INTERRUPTED - Approval Required\n\n";

    $request = $interrupt->getRequest();
    echo "Message: {$request->getReason()}\n";
    echo "Actions requiring approval:\n";

    foreach ($request->getPendingActions() as $action) {
        echo "  - {$action->name}: {$action->description}}";
    }

    echo "\n";

    foreach ($request->getPendingActions() as $action) {
        if (promptUserForApproval()) {
            $action->approve();
        } else {
            $action->reject('User denied operation');
        }
    }

    // Continue with the same agent state (it will resume automatically)
    echo "Resuming workflow...\n\n";

    // The agent needs to be rebuilt with the updated state for resumption
    // In a real application, you'd use persistence to save/load workflow state
    $response = $agent->chat(interrupt: $request);
    echo "Agent: {$response->getContent()}\n";
}

// Helper function to simulate user input
function promptUserForApproval(): bool
{
    // In a real application, this would prompt the user via CLI, web UI, etc.
    // For this example, we'll automatically approve for demonstration
    echo "\n[Simulating user decision...]\n";
    \sleep(1);

    // Randomly approve or deny for demonstration
    return (bool) \rand(0, 1);
}

echo "\n\n=== Example Complete ===\n";
echo "This demonstrates how middleware can intercept workflow execution\n";
echo "for human-in-the-loop patterns like tool approval.\n";
