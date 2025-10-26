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

        // Propagate callbacks to the provider so it can emit events
        $this->propagateCallbacks($provider);

        return $this;
    }

    protected function provider(): AIProviderInterface
    {
        return $this->provider;
    }

    /**
     * Get the current instance of the chat history.
     */
    public function resolveProvider(): AIProviderInterface
    {
        if (!isset($this->provider)) {
            $this->provider = $this->provider();

            // Propagate callbacks to the provider so it can emit events
            $this->propagateCallbacks($this->provider);
        }

        return $this->provider;
    }
}
