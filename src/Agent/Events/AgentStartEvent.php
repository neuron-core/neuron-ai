<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Events\Event;

class AgentStartEvent implements Event
{
    /**
     * @param Message[] $messages
     */
    public function __construct(
        public array $messages = [],
        public AgentRunOptions $options = new AgentRunOptions(),
    ) {
    }
}
