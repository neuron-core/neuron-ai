<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Interrupt;

use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function is_array;
use function is_bool;
use function is_string;

abstract class ToolInputTranslator implements InputTranslatorInterface
{
    /**
     * @return array<array-key, true>
     * @throws InputTranslationException
     */
    protected function toolCallIds(InterruptRequest $request): array
    {
        $ids = match (true) {
            $request instanceof ApprovalRequest => array_map(fn (Action $action): string => $action->id, $request->getActions()),
            $request instanceof ToolResultsRequest => array_merge(
                array_keys($request->getResults()),
                array_map(fn (ToolCall $call): ?string => $call->getCallId(), $request->getToolCalls()),
            ),
            default => [],
        };
        $calls = [];
        foreach ($ids as $id) {
            if ($id === null || $id === '') {
                throw new InputTranslationException('A tool call requires a non-empty call ID.');
            }
            $calls[$id] = true;
        }
        return $calls;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param class-string<ApprovalRequest|ToolResultsRequest> $requestClass
     * @return array<string, mixed>
     * @throws InputTranslationException
     * @throws WorkflowException
     */
    protected function translateCalls(array $payload, InterruptRequest $request, string $requestClass): array
    {
        $calls = $request instanceof $requestClass ? $this->toolCallIds($request) : [];
        foreach ($payload as $callId => $value) {
            if (!isset($calls[$callId])) {
                throw new InputTranslationException("No matching request for tool call '{$callId}'.");
            }
        }
        return $this->inputs($request, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     * @throws InputTranslationException
     */
    protected function entries(array $payload, string $key): array
    {
        $entries = array_key_exists($key, $payload) ? $payload[$key] : [];
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new InputTranslationException("'{$key}' must be a list.");
        }
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new InputTranslationException("Every '{$key}' entry must be an object.");
            }
        }
        return $entries;
    }

    /**
     * @return 'approve'|'reject'|array{string, string}
     * @throws InputTranslationException
     */
    protected function approval(mixed $payload): string|array
    {
        if (!is_array($payload) || !is_bool($payload['approved'] ?? null)) {
            throw new InputTranslationException('An approval response requires a boolean approved field.');
        }
        if (array_key_exists('editedArgs', $payload)) {
            throw new InputTranslationException('Approval with edited arguments is not supported.');
        }
        if (isset($payload['reason']) && !is_string($payload['reason'])) {
            throw new InputTranslationException('An approval reason must be a string.');
        }
        return $payload['approved'] ? 'approve' : (isset($payload['reason']) ? ['reject', $payload['reason']] : 'reject');
    }

    /**
     * @param array<string, mixed> $answers
     * @throws InputTranslationException
     */
    protected function answer(array &$answers, string $callId, mixed $value): void
    {
        if (array_key_exists($callId, $answers) && $answers[$callId] !== $value) {
            throw new InputTranslationException("Conflicting responses for tool call '{$callId}'.");
        }
        $answers[$callId] = $value;
    }

    /**
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     * @throws WorkflowException
     * @throws InputTranslationException
     */
    protected function inputs(InterruptRequest $request, array $answers): array
    {
        if ($answers === []) {
            throw new InputTranslationException('The payload contains no matching continuation input.');
        }
        if ($request instanceof ToolResultsRequest) {
            $request->validateResults($answers);
        }
        return $answers;
    }
}
