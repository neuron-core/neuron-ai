<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;

/**
 * Suspends the workflow until an external event named $eventName is delivered.
 *
 * This is the OUTBOUND pause description only — the event name to wait for and an
 * optional deadline. The matched event data (the answer) is NOT carried here; it
 * arrives as the inbound payload on resume (see Workflow::resume()). Subclasses
 * specialize the outbound context — ApprovalRequest is the shipped example,
 * carrying Action[] the caller must render to a human.
 *
 * An optional $expiresAt turns the wait into a bounded one: if no event satisfies
 * it by the deadline, the scheduler resumes the workflow with $timedOut and the
 * node's awaitEvent() surfaces null. Expiration is coordination state — the
 * scheduler owns the deadline timer; persistence stores neither this request nor
 * the timeout flag (the request is fire-and-forget, rebuilt by re-running the node).
 */
class WaitForEventRequest extends InterruptRequest
{
    /**
     * @param string                 $eventName The external occurrence to wait for.
     * @param DateTimeImmutable|null $expiresAt Optional deadline; if set, the scheduler resumes
     *                                          the workflow with $timedOut when it elapses.
     */
    public function __construct(
        protected string $eventName,
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

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type()->value,
            'event' => $this->eventName,
            'expires_at' => $this->expiresAt?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return WaitForEventRequest
     * @throws Exception
     */
    public static function fromArray(array $data): self
    {
        $expiresAt = isset($data['expires_at'])
            ? new DateTimeImmutable((string) $data['expires_at'])
            : null;

        return new self(
            (string) ($data['event'] ?? ''),
            $expiresAt,
        );
    }
}
