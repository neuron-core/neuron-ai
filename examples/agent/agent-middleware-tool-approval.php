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
    public string $name = 'delete_file';
    public string $description = 'Delete a file from the filesystem';

    public function __construct(
        public string $filePath = ''
    ) {
    }

    public function execute(): void
    {
        $this->result = "File '{$this->filePath}' has been deleted.";
        echo "  [TOOL EXECUTED] Deleted file: {$this->filePath}\n";
    }
}

class FileReadTool extends Tool
{
    public string $name = 'read_file';
    public string $description = 'Read contents of a file';

    public function __construct(
        public string $filePath = ''
    ) {
    }

    public function execute(): void
    {
        $this->result = "Contents of '{$this->filePath}': Sample file content...";
        echo "  [TOOL EXECUTED] Read file: {$this->filePath}\n";
    }
}

class CommandExecuteTool extends Tool
{
    public string $name = 'execute_command';
    public string $description = 'Execute a shell command';

    public function __construct(
        public string $command = ''
    ) {
    }

    public function execute(): void
    {
        $this->result = "Command '{$this->command}' executed successfully.";
        echo "  [TOOL EXECUTED] Executed command: {$this->command}\n";
    }
}

// Setup
$apiKey = \getenv('ANTHROPIC_API_KEY');
if (!$apiKey) {
    die("Please set ANTHROPIC_API_KEY environment variable\n");
}

$provider = new Anthropic($apiKey, 'claude-3-5-sonnet-20241022');
$persistence = new FilePersistence(__DIR__);
$workflowId = 'agent_with_tool_approval_' . \uniqid();

echo "=== Agent Middleware: Tool Approval Example ===\n\n";

// Create agent with ToolApprovalMiddleware
// Only 'delete_file' and 'execute_command' require approval
$state = new AgentState(chatHistory: new InMemoryChatHistory());

$agent = Agent::make($state)
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
$state = new AgentState(chatHistory: new InMemoryChatHistory());
$agent = Agent::make($state)
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
    $message = UserMessage::make('Delete the old_logs.txt file');
    echo "User: {$message->getContent()}\n\n";

    $response = $agent->chat($message);
    echo "Agent: {$response->getContent()}\n\n";

} catch (WorkflowInterrupt $interrupt) {
    echo "⚠️  WORKFLOW INTERRUPTED - Approval Required\n\n";

    $data = $interrupt->getData();
    echo "Message: {$data['message']}\n";
    echo "Tools requiring approval:\n";

    foreach ($data['tools'] as $tool) {
        echo "  - {$tool['name']}: {$tool['description']}\n";
        echo "    Arguments: " . \json_encode($tool['arguments']) . "\n";
    }

    echo "\n";

    // Simulate user approval decision
    $approved = promptUserForApproval();

    // Store feedback in state
    $state = $interrupt->getState();
    $state->set('tool_approval_feedback', [
        'approved' => $approved,
        'reason' => $approved ? null : 'User rejected the operation for safety reasons'
    ]);

    // Continue with the same agent state (it will resume automatically)
    if ($approved) {
        echo "\n✅ User APPROVED the operation\n";
        echo "Resuming workflow...\n\n";

        // The agent needs to be rebuilt with the updated state for resumption
        // In a real application, you'd use persistence to save/load workflow state
        $response = $agent->chat([]); // Empty message triggers resume
        echo "Agent: {$response->getContent()}\n";
    } else {
        echo "\n❌ User DENIED the operation\n";
        echo "Resuming workflow with denial...\n\n";

        $response = $agent->chat([]); // Empty message triggers resume
        echo "Agent: {$response->getContent()}\n";
    }
}

// Helper function to simulate user input
function promptUserForApproval(): bool
{
    // In a real application, this would prompt the user via CLI, web UI, etc.
    // For this example, we'll automatically approve for demonstration
    echo "\n[Simulating user decision...]\n";
    \usleep(500000); // 0.5 second delay for effect

    // Randomly approve or deny for demonstration
    return (bool) \rand(0, 1);
}

echo "\n\n=== Example Complete ===\n";
echo "This demonstrates how middleware can intercept workflow execution\n";
echo "for human-in-the-loop patterns like tool approval.\n";
