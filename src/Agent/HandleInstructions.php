<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;

use function is_string;

trait HandleInstructions
{
    protected SystemMessage $instructions;

    protected function instructions(): SystemMessage|string
    {
        return new SystemMessage(
            (new SystemContent(
                'Your are a helpful and friendly AI agent built with Neuron AI - the first agentic framework for the PHP ecosystem.'
            ))->cache()
        );
    }

    public function setInstructions(SystemMessage|string $instructions): self
    {
        $this->instructions = is_string($instructions) ? new SystemMessage($instructions) : $instructions;
        return $this;
    }

    public function resolveInstructions(): SystemMessage
    {
        $instructions = $this->instructions ?? $this->instructions();

        return is_string($instructions) ? new SystemMessage($instructions) : $instructions;
    }
}
