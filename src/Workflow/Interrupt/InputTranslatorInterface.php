<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

interface InputTranslatorInterface
{
    /**
     * Translate a decoded transport payload against authoritative pending requests.
     * Translation does not execute the workflow or modify persistence.
     *
     * @param array<array-key, mixed> $payload
     * @param InterruptRequest[] $requests
     * @return list<ResumeInput>
     */
    public function translate(array $payload, array $requests): array;
}
