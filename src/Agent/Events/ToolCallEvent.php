<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Workflow\Events\Event;

class ToolCallEvent implements Event
{
    public function __construct(
        public ToolCallMessage $toolCallMessage,
    ) {
    }
}
