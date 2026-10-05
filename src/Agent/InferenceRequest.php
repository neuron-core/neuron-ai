<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;

use function is_string;
use function serialize;
use function unserialize;

/**
 * Working input for inference: pending messages, enriched instructions, the
 * context of the turn and run options. AgentState owns the request throughout
 * the run; the tools offered come from the segment's resources.
 */
class InferenceRequest
{
    public SystemMessage $instructions;

    /**
     * Content of the turn sent with its question, after the question's own
     * content, on every request of the turn. It is never written to the chat
     * history. The default is declared here, not in the constructor, so that
     * a run saved before the context existed restores without one.
     *
     * @var array<int|string, ContentBlockInterface>
     */
    public array $context = [];

    /**
     * @param Message[] $messages Messages awaiting inference and history commit.
     * @param array<int|string, ContentBlockInterface> $context
     */
    public function __construct(
        SystemMessage|string $instructions,
        public array $messages = [],
        public AgentRunOptions $options = new AgentRunOptions(),
        array $context = [],
    ) {
        $this->instructions = is_string($instructions) ? new SystemMessage($instructions) : $instructions;
        $this->context = $context;
    }

    public function __clone(): void
    {
        // A cloned state must not share mutable request data with its original.
        [$this->instructions, $this->messages, $this->options, $this->context] = unserialize(serialize([
            $this->instructions,
            $this->messages,
            $this->options,
            $this->context,
        ]));
    }
}
