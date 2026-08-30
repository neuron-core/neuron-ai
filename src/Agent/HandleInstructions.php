<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\Messages\SystemMessage;

use function is_string;

trait HandleInstructions
{
    protected SystemMessage $instructions;

    protected function instructions(): SystemMessage|string
    {
        return new SystemMessage(
            'Your are a helpful and friendly AI agent built with Neuron AI - the first agentic framework for the PHP ecosystem.'
        );
    }

    public function setInstructions(SystemMessage|string $instructions): self
    {
        $this->instructions = is_string($instructions) ? new SystemMessage($instructions) : $instructions;
        return $this;
    }

    final public function getInstructions(): SystemMessage
    {
        if (!isset($this->instructions)) {
            $instructions = $this->instructions();
            $this->instructions = is_string($instructions) ? new SystemMessage($instructions) : $instructions;
        }

        return $this->instructions;
    }
}
