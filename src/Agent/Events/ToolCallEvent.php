<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Workflow\Event;

/**
 * Event triggered when the AI provider requests tool execution.
 */
class ToolCallEvent implements Event
{
    public function __construct(
        public readonly ToolCallMessage $toolCallMessage,
    ) {
    }
}
