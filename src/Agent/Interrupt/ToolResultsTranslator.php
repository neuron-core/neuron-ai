<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Interrupt;

/** Translates native result/error entries keyed by tool call ID. */
class ToolResultsTranslator extends ToolInputTranslator
{
    public function translate(array $payload, array $requests): array
    {
        return $this->translateCalls($payload, $requests, ToolResultsRequest::class);
    }
}
