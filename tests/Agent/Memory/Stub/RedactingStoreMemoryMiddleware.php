<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Memory\Stub;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\StoreMemoryEvent;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Events\Event;

class RedactingStoreMemoryMiddleware extends AgentMiddleware
{
    protected function beforeAgentNode(
        AgentNodeInterface $node,
        Event $event,
        AgentState $state,
    ): void {
        if (!$event instanceof StoreMemoryEvent) {
            return;
        }

        foreach ($event->messages as $index => $message) {
            if ($message instanceof UserMessage) {
                $event->messages[$index] = new UserMessage('[redacted]');
                return;
            }
        }
    }
}
