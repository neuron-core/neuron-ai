<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Workflow\Persistence\FilePersistence;

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

    // The tool declares its own risk (ADR 0004): with a bare ToolApproval()
    // each tool decides for itself, so gated tools must return true here.
    // Middleware config (e.g. new ToolApproval([FileDeleteTool::class]))
    // overrides this declaration in both directions.
    public function requiresApproval(array $inputs): bool
    {
        return true;
    }

    public function __invoke(string $path): string
    {
        return "File '{$path}' has been deleted.";
    }
}

echo "=== Agent Middleware: Tool Approval Example ===\n";
echo "-------------------------------------------------------------------\n\n";

/*
 * The agent is rebuilt on every execution cycle from the only identity the
 * application owns: the chat thread. The durable chat history is the system
 * of record for the suspension — pending tools, decisions, AND the resume
 * token (ADR 0005) — so no workflowId needs to be stored anywhere.
 */
$makeAgent = fn (): Agent => Agent::make()
    ->setPersistence(new FilePersistence(__DIR__))
    ->setChatHistory(new FileChatHistory(__DIR__, 'tool-approval-demo'))
    ->setAiProvider(
        new Anthropic\Anthropic(
            '',
            'claude-3-7-sonnet-latest'
        )
    )
    ->setInstructions(
        'You are a helpful assistant with access to file and command tools. Be concise.'
    )
    ->addTool(new FileDeleteTool())
    ->addMiddleware(ToolNode::class, new ToolApproval());

$agent = $makeAgent();

// Start each demo run with a clean thread.
$agent->getChatHistory()->flushAll();

$message = new UserMessage('Delete the C:/old_logs.txt file');
echo "User: {$message->getContent()}\n\n";

// Run the agent. When a gated tool is requested, execution pauses and the
// handler is marked interrupted — no exception is thrown to the caller.
$handler = $agent->chat($message);
$handler->run();

while ($handler->interrupted()) {
    echo "⚠️  WORKFLOW INTERRUPTED - Approval Required\n\n";

    $approvalRequest = $handler->getInterruptRequest();

    // The suspension is fully recorded in chat history: the annotated tool
    // call message carries the approval states and the resume token.
    $tail = $agent->getChatHistory()->getLastMessage();
    if ($tail instanceof ToolCallMessage) {
        echo "Resume token (stored in chat history): {$tail->getResumeToken()}\n";
    }

    echo "Message: {$approvalRequest->getMessage()}\n\n";
    echo "Actions requiring approval:\n";

    /*
     * Decisions travel inbound as a PAYLOAD — a plain array keyed by the action
     * id (the tool callId). The outbound ApprovalRequest is never passed back in.
     * The payload is INCREMENTAL: it carries only NEW decisions — chat history
     * is the system of record (ADR 0003).
     */
    $payload = [];
    foreach ($approvalRequest->getActions() as $action) {
        echo "  - {$action->name}: {$action->description}\n";

        if (promptUserForApproval()) {
            $payload[$action->id] = 'approve';
        } else {
            $payload[$action->id] = ['reject', 'User denied operation'];
        }
    }

    /*
     * Imagine a new execution cycle starts here (e.g. the approve/deny HTTP
     * endpoint): rebuild the agent from the thread alone and deliver the
     * payload. The resume token is adopted from the chat history tail —
     * nothing else to store, nothing else to pass.
     */
    echo "\nResuming workflow...\n\n";

    $handler = $makeAgent()->chat(payload: $payload)->run();
}

echo "Agent: " . $handler->getMessage()->getContent() . "\n\n";

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
