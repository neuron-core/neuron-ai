<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Providers\AIProviderInterface;

trait ResolveProvider
{
    /**
     * The AI provider instance.
     */
    protected AIProviderInterface $provider;

    public function setAiProvider(AIProviderInterface $provider): AgentInterface
    {
        $this->provider = $provider;

        return $this;
    }

    protected function provider(): AIProviderInterface
    {
        return $this->provider;
    }

    /**
     * Get the current provider instance.
     */
    public function resolveProvider(): AIProviderInterface
    {
        return $this->provider ??= $this->provider();
    }
}
