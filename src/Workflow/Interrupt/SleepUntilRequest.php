<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;

/**
 * Suspends the workflow until a clock time. The wakeup is produced by the
 * an external timer service — self-generated, with no external emitter.
 *
 * The engine does NOT enforce timeliness: a $wakeAt in the past still suspends.
 * Whether and when to fire belongs to the external caller or coordination
 * platform; the Workflow core only describes the suspension.
 */
class SleepUntilRequest extends InterruptRequest
{
    /**
     * @param DateTimeImmutable $wakeAt When an external coordinator should wake the workflow.
     */
    public function __construct(
        protected DateTimeImmutable $wakeAt,
    ) {
    }

    public function type(): InterruptType
    {
        return InterruptType::SleepUntil;
    }

    public function getMessage(): string
    {
        return 'Sleeping until ' . $this->wakeAt->format(DateTimeInterface::ATOM);
    }

    public function getWakeAt(): DateTimeImmutable
    {
        return $this->wakeAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type()->value,
            'wake_at' => $this->wakeAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws Exception
     */
    public static function fromArray(array $data): self
    {
        return new self(
            new DateTimeImmutable((string) ($data['wake_at'] ?? 'now')),
        );
    }
}
