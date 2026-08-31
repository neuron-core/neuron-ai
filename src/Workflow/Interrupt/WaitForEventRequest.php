<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;

/**
 * Suspends the workflow until an external event named $eventName is delivered.
 * The OUTBOUND pause description only: the matched event data arrives as the
 * inbound payload on resume. Subclasses specialize the outbound context
 * (ApprovalRequest is the shipped example).
 *
 * An optional $expiresAt bounds the wait: an external caller or coordination
 * platform owns the deadline timer and delivers an expiry ResumeInput.
 */
class WaitForEventRequest extends InterruptRequest
{
    /**
     * @param string                 $eventName The external occurrence to wait for.
     * @param DateTimeImmutable|null $expiresAt Optional deadline for an external coordinator.
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
