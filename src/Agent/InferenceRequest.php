<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;

use function is_string;
use function serialize;
use function unserialize;

/**
 * Working input for inference: pending messages, enriched instructions and run
 * options. AgentState owns the request throughout the run; the tools offered
 * come from the segment's resources.
 */
class InferenceRequest
{
    public SystemMessage $instructions;

    /**
     * @param Message[] $messages Messages awaiting inference and history commit.
     */
    public function __construct(
        SystemMessage|string $instructions,
        public array $messages = [],
        public AgentRunOptions $options = new AgentRunOptions(),
    ) {
        $this->instructions = is_string($instructions) ? new SystemMessage($instructions) : $instructions;
    }

    public function __clone(): void
    {
        // A cloned state must not share mutable request data with its original.
        [$this->instructions, $this->messages, $this->options] = unserialize(serialize([
            $this->instructions,
            $this->messages,
            $this->options,
        ]));
    }
}
