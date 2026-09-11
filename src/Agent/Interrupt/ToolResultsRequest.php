<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Interrupt;

use DateTimeImmutable;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;

use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function json_encode;

class ToolResultsRequest extends WaitForEventRequest
{
    public const EVENT_NAME = 'tool_results';

    /**
     * @param ToolCall[] $pendingCalls External calls still awaiting results.
     * @param array<array-key, array{result?: mixed, error?: string}> $results Accepted results for validating repeat submissions.
     */
    public function __construct(
        protected array $pendingCalls,
        protected array $results = [],
        ?DateTimeImmutable $expiresAt = null,
    ) {
        parent::__construct(self::EVENT_NAME, $expiresAt);
    }

    /** @return ToolCall[] */
    public function getToolCalls(): array
    {
        return array_values($this->pendingCalls);
    }

    /** @return array<array-key, array{result?: mixed, error?: string}> */
    public function getResults(): array
    {
        return $this->results;
    }

    public function getMessage(): string
    {
        return 'Waiting for external tool results';
    }

    /**
     * @throws WorkflowException
     */
    public function validate(ResumeInput $input): void
    {
        parent::validate($input);
        $this->validateResults($input->payload ?? []);
    }

    /**
     * @param array<array-key, mixed> $results
     * @throws WorkflowException
     */
    public function validateResults(array $results): void
    {
        $ids = [];
        foreach ($this->pendingCalls as $call) {
            $ids[$call->getCallId()] = true;
        }

        foreach ($results as $id => $result) {
            if (!isset($ids[$id]) && !isset($this->results[$id])) {
                throw new WorkflowException("Tool result '{$id}' does not belong to this deferred batch.");
            }
            if (!is_array($result) || count($result) !== 1 || (
                !array_key_exists('result', $result) && !is_string($result['error'] ?? null)
            )) {
                throw new WorkflowException("Tool result '{$id}' must contain either 'result' or a string 'error'.");
            }
            if (isset($this->results[$id]) && json_encode($this->results[$id]) !== json_encode($result)) {
                throw new WorkflowException("Tool result '{$id}' has already been settled with a different outcome.");
            }
        }
    }

    protected function metadata(): array
    {
        return [
            'message' => $this->getMessage(),
            'toolCalls' => array_map(
                fn (ToolCall $call): array => $call->jsonSerialize(),
                $this->getToolCalls(),
            ),
        ];
    }
}
