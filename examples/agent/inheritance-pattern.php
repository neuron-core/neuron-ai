<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Workflow\WorkflowInterrupt;

class DataAnalystAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            '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',
            'claude-3-7-sonnet-latest'
        );
    }

    protected function instructions(): string
    {
        return <<<'INSTRUCTIONS'
You are a data analyst AI assistant. Your role is to:
- Analyze data and provide insights
- Perform calculations accurately
- Explain your reasoning clearly
- Present findings in a structured format
INSTRUCTIONS;
    }

    protected function tools(): array
    {
        return [
            CalculatorToolkit::make(),
        ];
    }

    protected function agentMiddleware(): array
    {
        return [
            ToolNode::class => new ToolApproval(),
        ];
    }

    protected function agentState(): AgentState
    {
        return new AgentState([
            'analyst_name' => 'DataBot',
            'session_id' => \uniqid('session_'),
        ]);
    }
}

// Usage: Simply instantiate and use
$agent = DataAnalystAgent::make();

$interruptRequest = null;

try {
    chat:
    if ($interruptRequest == null) {
        $response = $agent->chat(
            new UserMessage('Calculate the compound annual growth rate if initial value is 1000 and final value is 1500 over 3 years')
        );
    } else {
        $response = $agent->chat(interrupt: $interruptRequest);
    }

    echo "Agent Response:\n";
    echo $response->getContent() . "\n";

} catch (WorkflowInterrupt $interrupt) {
    echo "Workflow interrupted for approval\n";

    // Handle approval flow
    $interruptRequest = $interrupt->getRequest();
    foreach ($interruptRequest->actions as $action) {
        echo "Action: {$action->name} - {$action->description}\n";
        // In a real app, prompt user for approval
        $action->approve();
    }

    goto chat;
}
