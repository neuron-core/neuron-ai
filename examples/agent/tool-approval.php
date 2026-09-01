<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Resume\ResumeInput;

require_once __DIR__ . '/../../vendor/autoload.php';

// Create some example tools that we want to gate with approval
class FileDeleteTool extends Tool
{
    protected string $name = 'delete_file';

    protected function properties(): array
    {
        return [
            ToolProperty::make('path', PropertyType::STRING, 'The path to the file to delete', true),
        ];
    }

    // The tool declares its own intrinsic risk via the protected hook.
    // Returning a string counts as "true" and doubles as the approval reason
    // shown to the approver. Attach-time config (requireApproval(),
    // suppressApproval(), withApprovalPolicy()) overrides this declaration
    // in both directions.
    protected function approvalPolicy(array $inputs): bool|string
    {
        return 'Deleting a file is irreversible';
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
 * application owns: the chat thread. The thread IS the workflow ID of the run —
 * the engine binds threadId → runId in workflow persistence when the run
 * ignites, so a blank agent holding only the thread finds the suspended run.
 * No runId is stored anywhere by the application. The durable chat history
 * carries the suspension's conversation side (pending tools, decisions).
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
    ->addTool(new FileDeleteTool());

$agent = $makeAgent();

// Start each demo run with a clean thread.
$agent->getChatHistory()->flushAll();

$message = new UserMessage('Delete the C:/old_logs.txt file');
echo "User: {$message->getContent()}\n\n";

// chat() runs eagerly → AgentState. When a gated tool is requested, execution
// pauses and the state is marked interrupted — no exception is thrown.
$state = $agent->chat($message);

while ($state->isInterrupted()) {
    echo "⚠️  WORKFLOW INTERRUPTED - Approval Required\n\n";

    $approvalRequest = $state->getInterruptRequest();

    // The suspension is recorded in chat history as conversation: the annotated
    // tool call message carries the pending approval states, which is what lets
    // a cold process RENDER the pending approvals without booting a workflow.
    $tail = $agent->getChatHistory()->getLastMessage();
    if ($tail instanceof ToolCallMessage) {
        echo "Pending tool calls on the thread: " . \count($tail->getToolCalls()) . "\n";
    }

    echo "Message: {$approvalRequest->getMessage()}\n\n";
    echo "Actions requiring approval:\n";

    /*
     * Decisions travel inbound inside an addressed ResumeInput. Its payload is
     * keyed by action id (the tool callId). The outbound ApprovalRequest is never
     * passed back in. If approvals are collected over several UI interactions,
     * restate the complete current decision set on every resume.
     */
    $payload = [];
    foreach ($approvalRequest->getActions() as $action) {
        echo "  - {$action->name}: {$action->description}\n";

        if ($action->reason !== null) {
            echo "    Why approval is needed: {$action->reason}\n";
        }

        if (promptUserForApproval()) {
            $payload[$action->id] = 'approve';
        } else {
            $payload[$action->id] = ['reject', 'User denied operation'];
        }
    }

    /*
     * Imagine a new execution cycle starts here (e.g. the approve/deny HTTP
     * endpoint): rebuild the agent from the thread alone — NO runId — and
     * deliver the payload. The thread IS the run's workflow ID, so the engine
     * finds the suspended run there. Nothing else to store, nothing else to pass.
     */
    echo "\nResuming workflow...\n\n";

    $interruptId = $state->getInterruptRequest()->getId();
    $state = $makeAgent()->resume([ResumeInput::event($interruptId, $payload)]);
}

echo "Agent: " . $state->getMessage()->getContent() . "\n\n";

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
