<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Interrupt;

use NeuronAI\Exceptions\InputTranslationException;

/** Translates native decisions keyed by tool call ID. */
class ApprovalTranslator extends ToolInputTranslator
{
    public function translate(array $payload, array $requests): array
    {
        foreach ($payload as $decision) {
            if ($decision === 'approve' || $decision === 'reject') {
                continue;
            }
            if (!is_array($decision) || !array_is_list($decision) || count($decision) !== 2
                || $decision[0] !== 'reject' || !is_string($decision[1])) {
                throw new InputTranslationException("A decision must be 'approve', 'reject', or ['reject', reason].");
            }
        }
        return $this->translateCalls($payload, $requests, ApprovalRequest::class);
    }
}
