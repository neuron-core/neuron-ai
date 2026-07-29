<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Tools\ToolInterface;

use function is_string;

/**
 * Event carrying configuration for AI inference.
 *
 * This event is emitted before calling the AI provider and can be modified
 * by middleware to dynamically adjust instructions, tools, and other inference settings.
 */
class AIInferenceEvent extends AgentStartEvent
{
    public SystemMessage $instructions;

    /**
     * @param SystemMessage|string $instructions System instructions for the agent
     * @param ToolInterface[] $tools Available tools for the agent
     * @param int|null $maxRetries Maximum retry attempts for structured output (StructuredOutputNode only)
     */
    public function __construct(
        SystemMessage|string $instructions,
        public array $tools,
        public ?int $maxRetries = null,
    ) {
        $this->instructions = is_string($instructions) ? new SystemMessage($instructions) : $instructions;
    }
}
