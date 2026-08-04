<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Conversation;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * The UserSimulator's structured decision at each step: the next user message,
 * or a stop signal — the simulator owns the goal, so it judges completion.
 */
class SimulatorOutput
{
    public function __construct(
        #[SchemaProperty(
            description: 'True when your goal has been satisfied or you are giving up — this ends the conversation.',
            required: true
        )]
        public bool $stop,
        #[SchemaProperty(
            description: 'Your next message to the assistant, written in character. Required when stop is false.',
            required: false
        )]
        public ?string $message = null,
        #[SchemaProperty(
            description: 'Brief explanation of the decision (goal satisfied, giving up, or why this message comes next).',
            required: false
        )]
        public ?string $reason = null,
    ) {
    }
}
