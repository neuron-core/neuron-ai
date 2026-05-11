<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Tools\ToolInterface;

/**
 * Event carrying configuration for AI inference.
 *
 * This event is emitted before calling the AI provider and can be modified
 * by middleware to dynamically adjust instructions, tools, and other inference settings.
 */
class AIInferenceEvent extends AgentStartEvent
{
    /**
     * @param string|SystemContent[] $instructions System instructions for the agent
     * @param ToolInterface[] $tools Available tools for the agent
     * @param int|null $maxRetries Maximum retry attempts for structured output (StructuredOutputNode only)
     */
    public function __construct(
        public string|array $instructions,
        public array $tools,
        public ?int $maxRetries = null,
    ) {
    }
}
