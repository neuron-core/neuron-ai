<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Interrupt\Stub;

use NeuronAI\Agent\Interrupt\ToolInputTranslator;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Exposes the shared validation helpers protocol translators build on.
 */
class ExposedToolInputTranslator extends ToolInputTranslator
{
    public function translate(array $payload, InterruptRequest $request): array
    {
        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public function exposeEntries(array $payload, string $key): array
    {
        return $this->entries($payload, $key);
    }

    /**
     * @return 'approve'|'reject'|array{string, string}
     */
    public function exposeApproval(mixed $payload): string|array
    {
        return $this->approval($payload);
    }

    /**
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    public function exposeAnswer(array $answers, string $callId, mixed $value): array
    {
        $this->answer($answers, $callId, $value);

        return $answers;
    }
}
