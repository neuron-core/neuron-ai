<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Frontend;

use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolInputTranslator;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Exceptions\InputTranslationException;

class VercelAIInputTranslator extends ToolInputTranslator
{
    public function translate(array $payload, array $requests): array
    {
        $tools = $this->toolRequests($requests);
        $answers = [];
        foreach ($this->entries($payload, 'messages') as $message) {
            if (($message['role'] ?? null) !== 'assistant') {
                continue;
            }
            foreach ($this->entries($message, 'parts') as $part) {
                $type = $part['type'] ?? '';
                if (!is_string($type) || ($type !== 'dynamic-tool' && !str_starts_with($type, 'tool-'))) {
                    continue;
                }
                $callId = $part['toolCallId'] ?? null;
                if (!is_string($callId) || !isset($tools[$callId])) {
                    continue; // Full client history also contains earlier tool cycles.
                }
                $request = $tools[$callId];
                $state = $part['state'] ?? null;
                if ($state === 'approval-responded') {
                    if (!$request instanceof ApprovalRequest) {
                        continue; // A prior approval is not an external execution result.
                    }
                    $approval = $part['approval'] ?? null;
                    if (!is_array($approval) || ($approval['id'] ?? null) !== $callId) {
                        throw new InputTranslationException("Approval ID does not match tool call '{$callId}'.");
                    }
                    $this->answer($answers, $request, $callId, $this->approval($approval));
                } elseif ($state === 'output-available' || $state === 'output-error') {
                    if (!$request instanceof ToolResultsRequest) {
                        throw new InputTranslationException("Tool call '{$callId}' is awaiting approval, not execution results.");
                    }
                    if ($state === 'output-available') {
                        if (!array_key_exists('output', $part)) {
                            throw new InputTranslationException("Tool call '{$callId}' has no output.");
                        }
                        $result = ['result' => $part['output']];
                    } else {
                        if (!is_string($part['errorText'] ?? null)) {
                            throw new InputTranslationException("Tool call '{$callId}' requires errorText.");
                        }
                        $result = ['error' => $part['errorText']];
                    }
                    $this->answer($answers, $request, $callId, $result);
                }
            }
        }
        return $this->inputs($requests, $answers);
    }
}
