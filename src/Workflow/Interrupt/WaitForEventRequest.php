<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

/**
 * Suspends the workflow until an external event named $eventName is delivered.
 *
 * The event NAME is the sole selector — there is no persisted filter, callable, or
 * correlation predicate. Correlation identity is (eventName, workflowId); the
 * scheduler's event router delivers an occurrence of $eventName to the suspended
 * workflow, and finer filtering (e.g. "for userId = 42") happens in the node after
 * resume, re-executing naturally because the workflow replays by re-running.
 *
 * The matched event data is carried in $payload, which is null on first pass and
 * populated by the caller/scheduler on resume. Subclasses specialize the payload
 * — ApprovalRequest is the shipped example, carrying Action[] decisions instead of
 * a generic event body.
 */
class WaitForEventRequest extends InterruptRequest
{
    /**
     * @param string $eventName The external occurrence to wait for.
     * @param mixed  $payload   The matched event data; null on first pass, populated on resume.
     */
    public function __construct(
        protected string $eventName,
        protected mixed $payload = null,
    ) {
    }

    public function type(): InterruptType
    {
        return InterruptType::WaitForEvent;
    }

    public function getMessage(): string
    {
        return "Waiting for event '{$this->eventName}'";
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    public function getPayload(): mixed
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type()->value,
            'event' => $this->eventName,
            'payload' => $this->payload,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['event'] ?? ''),
            $data['payload'] ?? null,
        );
    }
}
