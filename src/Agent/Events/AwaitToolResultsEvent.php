<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\Event;

class AwaitToolResultsEvent implements Event
{
    /**
     * Both groups retain their original batch indexes to preserve result order.
     *
     * @param array<int, ToolCall> $completedCalls
     * @param array<int, ToolCall> $deferredCalls
     */
    public function __construct(
        public array $completedCalls,
        public array $deferredCalls,
    ) {
    }
}
