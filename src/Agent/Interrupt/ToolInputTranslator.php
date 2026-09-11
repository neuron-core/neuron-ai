<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Interrupt;

use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;

abstract class ToolInputTranslator implements InputTranslatorInterface
{
    /**
     * @param InterruptRequest[] $requests
     * @return array<array-key, ApprovalRequest|ToolResultsRequest>
     */
    protected function toolRequests(array $requests): array
    {
        $byCallId = [];
        foreach ($requests as $request) {
            if ($request instanceof ApprovalRequest) {
                $ids = array_map(fn (Action $action): string => $action->id, $request->getActions());
            } elseif ($request instanceof ToolResultsRequest) {
                $ids = array_merge(
                    array_keys($request->getResults()),
                    array_map(fn (ToolCall $call): ?string => $call->getCallId(), $request->getToolCalls()),
                );
            } else {
                continue;
            }
            foreach ($ids as $id) {
                if ($id === null || $id === '') {
                    throw new InputTranslationException('A tool call requires a non-empty call ID.');
                }
                if (isset($byCallId[$id]) && $byCallId[$id] !== $request) {
                    throw new InputTranslationException("Tool call '{$id}' belongs to multiple pending requests.");
                }
                $byCallId[$id] = $request;
            }
        }
        return $byCallId;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param InterruptRequest[] $requests
     * @param class-string<ApprovalRequest|ToolResultsRequest> $requestClass
     * @return list<ResumeInput>
     */
    protected function translateCalls(array $payload, array $requests, string $requestClass): array
    {
        $byCallId = $this->toolRequests(array_filter(
            $requests,
            fn (InterruptRequest $request): bool => $request instanceof $requestClass,
        ));
        $answers = [];
        foreach ($payload as $callId => $value) {
            if (!isset($byCallId[$callId])) {
                throw new InputTranslationException("No matching request for tool call '{$callId}'.");
            }
            $this->answer($answers, $byCallId[$callId], (string) $callId, $value);
        }
        return $this->inputs($requests, $answers);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
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

    /** @return 'approve'|'reject'|array{string, string} */
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
     * @param array<int, array<string, mixed>> $answers
     */
    protected function answer(array &$answers, InterruptRequest $request, string $callId, mixed $value): void
    {
        $id = $request->getId();
        if (array_key_exists($callId, $answers[$id] ?? []) && $answers[$id][$callId] !== $value) {
            throw new InputTranslationException("Conflicting responses for tool call '{$callId}'.");
        }
        $answers[$id][$callId] = $value;
    }

    /**
     * @param InterruptRequest[] $requests
     * @param array<int, array<string, mixed>> $answers
     * @return list<ResumeInput>
     */
    protected function inputs(array $requests, array $answers): array
    {
        $inputs = [];
        foreach ($requests as $request) {
            if (!array_key_exists($request->getId(), $answers)) {
                continue;
            }
            $payload = $answers[$request->getId()];
            if ($request instanceof ApprovalRequest) {
                // ToolNode replays the approval gate with cumulative decisions.
                // Preserve decisions in the latest snapshot when only new ones arrive.
                foreach ($request->getActions() as $action) {
                    if (!$action->isPending()) {
                        $decision = $action->isApproved() ? 'approve'
                            : ($action->feedback === null ? 'reject' : ['reject', $action->feedback]);
                        $payload[$action->id] ??= $decision;
                    }
                }
            }
            $input = ResumeInput::event($request, $payload);
            $request->validate($input);
            $inputs[] = $input;
        }
        return $inputs;
    }
}
