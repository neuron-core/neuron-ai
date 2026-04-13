<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

trait HandleInstructions
{
    protected string $instructions;

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ['Your are a helpful and friendly AI agent built with Neuron AI - the first agentic framework for the PHP ecosystem.']
        );
    }

    public function setInstructions(string $instructions): self
    {
        $this->instructions = $instructions;
        return $this;
    }

    public function resolveInstructions(): string
    {
        return $this->instructions ?? $this->instructions();
    }

}
