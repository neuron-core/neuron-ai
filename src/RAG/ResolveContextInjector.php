<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use NeuronAI\RAG\ContextInjector\ContextInjectorInterface;
use NeuronAI\RAG\ContextInjector\SystemPromptInjector;

trait ResolveContextInjector
{
    protected ContextInjectorInterface $contextInjector;

    public function setContextInjector(ContextInjectorInterface $injector): static
    {
        $this->contextInjector = $injector;
        return $this;
    }

    protected function contextInjector(): ContextInjectorInterface
    {
        return new SystemPromptInjector();
    }

    public function resolveContextInjector(): ContextInjectorInterface
    {
        return $this->contextInjector ??= $this->contextInjector();
    }
}
