<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use DateTimeImmutable;
use DateTimeInterface;

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
 *
 * An optional $expiresAt turns the wait into a bounded one: if no event satisfies
 * it by the deadline, the scheduler's timer wheel resumes the workflow with the
 * request marked expired (see markExpired()). Expiration is coordination state —
 * the scheduler owns the deadline timer; persistence merely stores this request.
 */
class WaitForEventRequest extends InterruptRequest
{
    /**
     * Internal timeout flag, set by the scheduler when the wait is satisfied by its
     * deadline elapsing rather than by a delivered event. This is plumbing: the
     * framework's awaitEvent() reads it and surfaces a timeout to the node as a
     * null return, so node code branches on null and never calls isExpired()
     * directly. It is NOT derived from a clock comparison (that would be fragile to
     * skew between the scheduler firing and the node checking).
     */
    protected bool $expired = false;

    /**
     * @param string                 $eventName  The external occurrence to wait for.
     * @param mixed                  $payload    The matched event data; null on first pass, populated on resume.
     * @param DateTimeImmutable|null $expiresAt  Optional deadline; if set, the scheduler resumes the
     *                                           workflow with markExpired() when it elapses.
     */
    public function __construct(
        protected string $eventName,
        protected mixed $payload = null,
        protected ?DateTimeImmutable $expiresAt = null,
    ) {
    }

    public function type(): InterruptType
    {
        return InterruptType::WaitForEvent;
    }

    public function getMessage(): string
    {
        $message = "Waiting for event '{$this->eventName}'";
        if ($this->expiresAt instanceof DateTimeImmutable) {
            $message .= " (expires at {$this->expiresAt->format(DateTimeInterface::ATOM)})";
        }
        return $message;
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    public function getPayload(): mixed
    {
        return $this->payload;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Mark this wait as satisfied by its deadline elapsing. Internal: called by the
     * scheduler / step endpoint on a timeout resume, not by node code. The node
     * sees the timeout as a null return from awaitEvent().
     */
    public function markExpired(): void
    {
        $this->expired = true;
    }

    /**
     * Whether the resume was produced by the deadline elapsing rather than by a
     * delivered event. Internal: read by the framework's awaitEvent() to decide the
     * null return; node code branches on null instead of calling this.
     */
    public function isExpired(): bool
    {
        return $this->expired;
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
            'expires_at' => $this->expiresAt?->format(DateTimeInterface::ATOM),
            'expired' => $this->expired,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $expiresAt = isset($data['expires_at'])
            ? new DateTimeImmutable((string) $data['expires_at'])
            : null;

        $request = new self(
            (string) ($data['event'] ?? ''),
            $data['payload'] ?? null,
            $expiresAt,
        );

        if (!empty($data['expired'])) {
            $request->markExpired();
        }

        return $request;
    }
}
