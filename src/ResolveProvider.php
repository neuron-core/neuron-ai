<?php

declare(strict_types=1);

namespace NeuronAI;

use NeuronAI\Providers\AIProviderInterface;

trait ResolveProvider
{
    /**
     * The AI provider instance.
     */
    protected AIProviderInterface $provider;

    /**
     * @deprecated
     */
    public function withProvider(AIProviderInterface $provider): AgentInterface
    {
        $this->provider = $provider;
        return $this;
    }

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
     * Get the current instance of the chat history.
     */
    public function resolveProvider(): AIProviderInterface
    {
        return $this->provider ?? $this->provider = $this->provider();
    }
}
