<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Exceptions\AgentException;
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

    /**
     * Provide the default AI provider. Subclasses override this hook —
     * never resolveProvider(), which memoizes the resolved instance.
     *
     * @throws AgentException
     */
    protected function provider(): AIProviderInterface
    {
        throw new AgentException(
            'No AI provider configured: override the provider() method in your agent, or call setAiProvider().'
        );
    }

    /**
     * Get the current provider instance.
     */
    final public function resolveProvider(): AIProviderInterface
    {
        return $this->provider ??= $this->provider();
    }
}
