<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\WorkflowInterrupt;

require_once __DIR__ . '/../../vendor/autoload.php';

// Create some example tools that we want to gate with approval
class FileDeleteTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'delete_file',
            'Delete a file from the filesystem'
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make('path', PropertyType::STRING, 'The path to the file to delete', true),
        ];
    }

    public function __invoke(string $path): string
    {
        return "File '{$path}' has been deleted.";
    }
}

echo "=== Agent Middleware: Tool Approval Example ===\n";
echo "-------------------------------------------------------------------\n\n";

$provider = new Anthropic\Anthropic(
    '',
    'claude-3-7-sonnet-latest'
);
$persistence = new FilePersistence(__DIR__);

// Reset state for new conversation
$id = 'workflow_1';
$agent = Agent::make(
    persistence: $persistence,
    workflowId: $id
)
    ->setAiProvider($provider)
    ->setInstructions('You are a helpful assistant with access to file and command tools. Be concise.')
    ->addTool([
        new FileDeleteTool(),
    ])
    ->addMiddleware(
        ToolNode::class,
        new ToolApproval()
    );

$interruptRequest = null;

try {
    chat:
    $message = new UserMessage('Delete the C:/old_logs.txt file');
    echo "User: {$message->getContent()}\n\n";

    if ($interruptRequest == null) {
        $response = $agent->chat(messages: $message)->getMessage();
    } else {
        echo "Resuming workflow...\n\n";
        $response = $agent->chat(interrupt: $interruptRequest)->getMessage();
    }

    echo "Agent: ".$response->getContent()."\n\n";
} catch (WorkflowInterrupt $interrupt) {
    echo "⚠️  WORKFLOW INTERRUPTED - Approval Required\n\n";

    $interruptRequest = $interrupt->getRequest();

    echo "Message: {$interruptRequest->getMessage()}\n\n";
    echo "Actions requiring approval:\n";

    foreach ($interruptRequest->getPendingActions() as $action) {
        echo "  - {$action->name}: {$action->description}}";
    }

    echo "\n";

    foreach ($interruptRequest->getPendingActions() as $action) {
        if (promptUserForApproval()) {
            $action->approve();
        } else {
            $action->reject('User denied operation');
        }
    }

    goto chat;
}
$persistence->delete($id);

// Helper function to simulate user input
function promptUserForApproval(): bool
{
    // In a real application, this would prompt the user via CLI, web UI, etc.
    // For this example, we'll automatically approve for demonstration
    echo "\n[Simulating user decision...]\n\n";
    \sleep(1);

    // Randomly approve or deny for demonstration
    return (bool) \rand(0, 1);
}

echo "\n\n=== Example Complete ===\n";
