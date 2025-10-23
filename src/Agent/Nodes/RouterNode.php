<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentCompleteEvent;
use NeuronAI\Agent\Events\AIResponseEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StopEvent;

/**
 * Node responsible for routing the AI response to either tool execution or completion.
 */
class RouterNode extends Node
{
    public function __invoke(AIResponseEvent $event, AgentState $state): ToolCallEvent|StopEvent
    {
        $response = $event->response;

        // If the response is a tool call, route to tool execution
        if ($response instanceof ToolCallMessage) {
            return new ToolCallEvent($response);
        }

        // Otherwise, the agent is complete
        // Signal completion by returning StopEvent
        return new StopEvent();
    }
}
