<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;

trait HandleInstructions
{
    protected string|array $instructions;

    protected function instructions(): string|array
    {
        return [
            new SystemContent(
                'Your are a helpful and friendly AI agent built with Neuron AI - the first agentic framework for the PHP ecosystem.'
            ),
        ];
    }

    public function setInstructions(string|array $instructions): self
    {
        $this->instructions = $instructions;
        return $this;
    }

    public function resolveInstructions(): string|array
    {
        return $this->instructions ?? $this->instructions();
    }
}
