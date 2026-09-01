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
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Resume\ResumeInput;

require_once __DIR__ . '/../../vendor/autoload.php';

// Create some example tools that we want to gate with approval
final class FileDeleteTool extends Tool
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
    protected function approvalPolicy(array $inputs): string
    {
        return 'Deleting a file is irreversible';
    }

    public function __invoke(string $path): string
    {
        return "File '{$path}' has been deleted.";
    }
}

echo "=== Agent Tool Approval Example ===\n";
echo "-------------------------------------------------------------------\n\n";

$apiKey = \getenv('ANTHROPIC_API_KEY');
if (!\is_string($apiKey) || $apiKey === '') {
    throw new \RuntimeException('Set ANTHROPIC_API_KEY before running this example.');
}

$storage = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'neuron-agent-tool-approval';
$threadId = 'tool-approval-demo-' . \bin2hex(\random_bytes(4));

/*
 * The agent is rebuilt on every execution cycle from the only identity the
 * application owns: the chat thread. The thread IS the workflow ID of the run —
 * the engine binds threadId → runId in workflow persistence when the run
 * ignites. Durable chat history carries the pending tool-call context; the
 * active InterruptRequest and its run fence come from the suspended state.
 */
$makeAgent = function () use ($storage, $threadId, $apiKey): Agent {
    $agent = Agent::make();
    $agent->setPersistence(new FilePersistence($storage . \DIRECTORY_SEPARATOR . 'workflow'));
    $agent->setChatHistory(new FileChatHistory(
        $storage . \DIRECTORY_SEPARATOR . 'chat',
        $threadId,
    ));
    $agent->setAiProvider(
        new Anthropic\Anthropic(
            $apiKey,
            'claude-3-7-sonnet-latest'
        )
    );
    $agent->setInstructions(
        'You are a helpful assistant with access to file and command tools. Be concise.'
    );
    $agent->addTool(new FileDeleteTool());

    return $agent;
};

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
    $runId = $state->getRunId();
    if (!$approvalRequest instanceof ApprovalRequest || $runId === null) {
        throw new \RuntimeException('The Agent did not expose the expected approval interruption.');
    }

    // The pending call is recorded in chat history as conversation: the annotated
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
     * Imagine a new execution cycle starts here, such as an approve/deny HTTP
     * endpoint. The endpoint receives the thread ID, interrupt ID, run ID, and
     * decision captured from the suspended outcome. The thread locates the
     * workflow partition; the run ID prevents a delayed decision from reaching
     * a newer generation.
     */
    echo "\nResuming workflow...\n\n";

    $agent = $makeAgent();
    $state = $agent->resume(
        [ResumeInput::event($approvalRequest->getId(), $payload)],
        expectedRunId: $runId,
    );
}

echo "Agent: " . $state->getMessage()->getContent() . "\n\n";

// Helper function to simulate user input
function promptUserForApproval(): bool
{
    // In a real application, this would prompt the user via CLI, web UI, etc.
    // For this example, automatically approve for deterministic output.
    echo "\n[Simulating user decision...]\n\n";

    return true;
}

echo "\n\n=== Example Complete ===\n";
