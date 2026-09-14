<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

interface InputTranslatorInterface
{
    /**
     * Translate a decoded transport payload against the current interruption.
     * Translation does not execute the workflow or modify persistence.
     *
     * @param array<array-key, mixed> $payload
     * @return array<string, mixed>
     */
    public function translate(array $payload, InterruptRequest $request): array;
}
