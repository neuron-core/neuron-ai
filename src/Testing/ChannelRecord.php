<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

class ChannelRecord
{
    /**
     * @param string $method The method called: 'send', 'suspended', 'completed' or 'failed'
     * @param ProtocolEvent|null $event The protocol event delivered (send only)
     * @param WorkflowState|null $state The segment state (suspended and completed only)
     * @param string|null $workflowId The workflow identity (completed and failed only)
     * @param Throwable|null $exception The unhandled throwable (failed only)
     */
    public function __construct(
        public readonly string $method,
        public readonly ?ProtocolEvent $event = null,
        public readonly ?WorkflowState $state = null,
        public readonly ?string $workflowId = null,
        public readonly ?Throwable $exception = null,
    ) {
    }
}
