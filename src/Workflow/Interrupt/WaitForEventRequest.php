<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use NeuronAI\Exceptions\WorkflowException;
use function time;

/**
 * Suspends the workflow until an external event named $eventName is delivered.
 * The OUTBOUND pause description only: the matched event data arrives as the
 * inbound payload on resume. Subclasses specialize the outbound context
 * (ApprovalRequest is the shipped example).
 *
 * An optional $expiresAt bounds the wait. Inputless continuation evaluates the
 * clock and produces the expiry internally; an external platform only schedules
 * when to invoke the workflow again.
 */
class WaitForEventRequest extends InterruptRequest
{
    /**
     * @param string                 $eventName The external occurrence to wait for.
     * @param DateTimeImmutable|null $expiresAt Optional deadline evaluated on continuation.
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

    public function validate(ResumeInput $input): void
    {
        $valid = $input->kind === ResumeKind::Event
            || ($input->kind === ResumeKind::Expired && $this->expiresAt instanceof DateTimeImmutable);

        if (!$valid) {
            throw new WorkflowException(
                "Resume input '{$input->kind->value}' is incompatible with interrupt {$this->getId()} "
                . "of type '{$this->type()->value}'."
            );
        }

        if (
            $input->kind === ResumeKind::Expired
            && $this->expiresAt instanceof DateTimeImmutable
            && $this->expiresAt->getTimestamp() > time()
        ) {
            throw new WorkflowException(
                "Expiry input for interrupt {$this->getId()} arrived before its deadline "
                . $this->expiresAt->format(DateTimeInterface::ATOM) . '.'
            );
        }
    }

    protected function coordinationData(): array
    {
        return [
            'eventName' => $this->eventName,
            'expiresAt' => $this->expiresAt?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws Exception
     */
    public static function fromArray(array $data): self
    {
        $expiresAt = isset($data['expiresAt'])
            ? new DateTimeImmutable((string) $data['expiresAt'])
            : null;

        $request = new self(
            (string) ($data['eventName'] ?? ''),
            $expiresAt,
        );

        return isset($data['interruptId'])
            ? $request->withId((int) $data['interruptId'])
            : $request;
    }
}
