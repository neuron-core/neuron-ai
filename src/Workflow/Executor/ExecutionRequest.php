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
        public readonly bool $recoverFailed = false,
        public readonly ?string $tag = null,
    ) {
        $this->input = $event instanceof \NeuronAI\Workflow\Events\Event ? serialize($event) : null;
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

    /**
     * A fresh start; omitting the event uses the workflow's default input.
     *
     * @param string|null $tag The caller's own label for the run, kept with it unchanged and shown by inspect().
     */
    public static function start(
        ?Event $event = null,
        ?string $runId = null,
        bool $recoverFailed = false,
        ?string $tag = null,
    ): self {
        if ($runId !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D', $runId) !== 1) {
            throw new WorkflowException('Invalid reserved run ID: use 1-128 ASCII letters, digits, underscores or hyphens, starting with a letter or digit.');
        }
        if ($tag !== null && preg_match('/^[^\x00-\x1F\x7F]{1,255}$/uD', $tag) !== 1) {
            throw new WorkflowException('Invalid run tag: use 1-255 characters without control characters.');
        }
        return new self(true, event: $event, runId: $runId, recoverFailed: $recoverFailed, tag: $tag);
    }

    /** @param array<string, mixed>|null $payload */
    public static function resume(
        ?array $payload = null,
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
    ): self {
        return new self(false, runId: $expectedRunId, executionAttempt: $expectedExecutionAttempt, payload: $payload);
    }

    /** @param array<string, mixed> $payload */
    public static function signal(
        string $event,
        array $payload = [],
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
    ): self {
        return new self(false, runId: $expectedRunId, executionAttempt: $expectedExecutionAttempt, payload: $payload, signal: $event);
    }
}
