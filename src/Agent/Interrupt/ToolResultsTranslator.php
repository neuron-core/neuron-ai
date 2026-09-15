<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Interrupt;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Translates native result/error entries keyed by tool call ID.
 *
 * ['tool_call_id' => ['result' => mixed]]
 */
class ToolResultsTranslator extends ToolInputTranslator
{
    public function translate(array $payload, InterruptRequest $request): array
    {
        return $this->translateCalls($payload, $request, ToolResultsRequest::class);
    }
}
