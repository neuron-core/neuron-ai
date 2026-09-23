<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\Event;

use function preg_match;
use function serialize;
use function unserialize;

/** One invocation's input, independent of a mutable workflow instance. */
final class ExecutionRequest
{
    protected readonly ?string $input;
    protected readonly ?string $response;

    /** @param array<string, mixed>|null $payload */
    protected function __construct(
        public readonly bool $starting,
        ?Event $event = null,
        public readonly ?string $runId = null,
        public readonly ?int $executionAttempt = null,
        ?array $payload = null,
        public readonly ?string $signal = null,
        public readonly ?string $idempotencyKey = null,
        public readonly bool $recoverFailed = false,
    ) {
        $this->input = $event === null ? null : serialize($event);
        $this->response = $payload === null ? null : serialize($payload);
    }

    public function payload(): ?array
    {
        return $this->response === null ? null : unserialize($this->response);
    }

    public function event(): ?Event
    {
        return $this->input === null ? null : unserialize($this->input);
    }

    /** A fresh start; omitting the event uses the workflow's default input. */
    public static function start(
        ?Event $event = null,
        ?string $runId = null,
        ?string $idempotencyKey = null,
        bool $recoverFailed = false,
    ): self {
        if ($runId !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D', $runId) !== 1) {
            throw new WorkflowException('Invalid reserved run ID: use 1-128 ASCII letters, digits, underscores or hyphens, starting with a letter or digit.');
        }
        return new self(true, event: $event, runId: $runId, idempotencyKey: $idempotencyKey, recoverFailed: $recoverFailed);
    }

    /** @param array<string, mixed>|null $payload */
    public static function resume(
        ?array $payload = null,
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
        ?string $idempotencyKey = null,
    ): self {
        return new self(false, runId: $expectedRunId, executionAttempt: $expectedExecutionAttempt, payload: $payload, idempotencyKey: $idempotencyKey);
    }

    /** @param array<string, mixed> $payload */
    public static function signal(
        string $event,
        array $payload = [],
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
        ?string $idempotencyKey = null,
    ): self {
        return new self(false, runId: $expectedRunId, executionAttempt: $expectedExecutionAttempt, payload: $payload, signal: $event, idempotencyKey: $idempotencyKey);
    }
}
