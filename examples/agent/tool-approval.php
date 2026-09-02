<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Workflow\Persistence\FilePersistence;

require_once __DIR__ . '/../../vendor/autoload.php';

final class FileDeleteTool extends Tool
{
    protected string $name = 'delete_file';

    protected function properties(): array
    {
        return [
            ToolProperty::make('path', PropertyType::STRING, 'The path to the file to delete', true),
        ];
    }

    protected function approvalPolicy(array $inputs): string
    {
        return 'Deleting a file is irreversible';
    }

    public function __invoke(string $path): string
    {
        return "File '{$path}' has been deleted.";
    }
}

$apiKey = \getenv('ANTHROPIC_API_KEY');
if (!\is_string($apiKey) || $apiKey === '') {
    throw new \RuntimeException('Set ANTHROPIC_API_KEY before running this example.');
}

$storage = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'neuron-agent-tool-approval';
$threadId = 'tool-approval-demo-' . \bin2hex(\random_bytes(4));

/*
 * Rebuild the Agent for every request from the application-owned thread ID.
 * Workflow persistence stores execution state; chat history stores the pending
 * ToolCallMessage used by the approval UI.
 */
$makeAgent = function () use ($storage, $threadId, $apiKey): Agent {
    $agent = Agent::make(threadId: $threadId);
    $agent->setPersistence(new FilePersistence($storage . \DIRECTORY_SEPARATOR . 'workflow'));
    $agent->setChatHistory(new FileChatHistory($storage . \DIRECTORY_SEPARATOR . 'chat'));
    $agent->setAiProvider(new Anthropic($apiKey, 'claude-3-7-sonnet-latest'));
    $agent->setInstructions('You are a helpful assistant. Be concise.');
    $agent->addTool(new FileDeleteTool());

    return $agent;
};

$message = new UserMessage('Delete the C:/old_logs.txt file');
echo "User: {$message->getContent()}\n\n";

$agent = $makeAgent();
$state = $agent->chat($message);

while ($state->isInterrupted()) {
    /*
     * A real approval page can load this message directly from durable chat
     * history. It does not need the Workflow interruption request or its ID.
     */
    $tail = $agent->getChatHistory()->getLastMessage();
    if (!$tail instanceof ToolCallMessage) {
        throw new \RuntimeException('Expected a pending tool call in chat history.');
    }

    echo "Approval required:\n";
    $decisions = [];

    foreach ($tail->getToolCalls() as $call) {
        if ($call->getApprovalState()?->isPending() !== true) {
            continue;
        }

        $callId = $call->getCallId();
        if ($callId === null) {
            throw new \RuntimeException('A pending tool call must have a call ID.');
        }

        echo "  Tool: {$call->getName()}\n";
        echo '  Inputs: ' . \json_encode($call->getInputs(), \JSON_PRETTY_PRINT) . "\n";

        if ($call->getApprovalReason() !== null) {
            echo "  Reason: {$call->getApprovalReason()}\n";
        }

        $decisions[$callId] = promptUserForApproval()
            ? 'approve'
            : ['reject', 'User denied operation'];
    }

    if ($decisions === []) {
        throw new \RuntimeException('The interrupted Agent exposed no pending approvals.');
    }

    /*
     * An approve/deny endpoint receives only the thread ID and the complete
     * decision map. Agent hides the underlying Workflow signal and request IDs.
     */
    echo "\nContinuing Agent...\n\n";
    $agent = $makeAgent();
    $state = $agent->toolApprovalDecisions($decisions)->run();
}

echo 'Agent: ' . $state->getMessage()->getContent() . "\n";

function promptUserForApproval(): bool
{
    echo "  [Simulating approval]\n";

    return true;
}
