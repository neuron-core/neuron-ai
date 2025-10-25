<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Workflow\Events\Event;

/**
 * Event triggered when tool execution completes.
 */
class ToolResultEvent implements Event
{
    public function __construct(
        public readonly ToolCallResultMessage $toolCallResult,
    ) {
    }
}
