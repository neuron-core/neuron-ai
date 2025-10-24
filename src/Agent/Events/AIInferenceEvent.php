<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

use NeuronAI\Workflow\Event;

/**
 * Event carrying configuration for AI inference.
 *
 * This event is emitted before calling the AI provider and can be modified
 * by middleware to dynamically adjust instructions, tools, and other inference settings.
 */
class AIInferenceEvent implements Event
{
    /**
     * @param string $instructions System instructions for the agent
     * @param array $tools Available tools for the agent
     * @param string|null $outputClass Class name for structured output (StructuredOutputNode only)
     * @param int|null $maxRetries Maximum retry attempts for structured output (StructuredOutputNode only)
     */
    public function __construct(
        public string $instructions,
        public array $tools,
        public ?string $outputClass = null,
        public ?int $maxRetries = null,
    ) {
    }
}
