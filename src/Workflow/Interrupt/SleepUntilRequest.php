<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use NeuronAI\Exceptions\WorkflowException;

/**
 * Suspends the workflow until a clock time. Every wait is persisted first;
 * inputless continuation then evaluates the clock and resumes due waits. An
 * external platform schedules the invocation but never asserts that the timer
 * elapsed.
 */
class SleepUntilRequest extends InterruptRequest
{
    /**
     * @param DateTimeImmutable $wakeAt Earliest time at which continuation may resolve the wait.
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

    public function validate(ResumeInput $input): void
    {
        if ($input->kind !== ResumeKind::Timer) {
            throw new WorkflowException(
                "Resume input '{$input->kind->value}' is incompatible with interrupt {$this->getId()} "
                . "of type '{$this->type()->value}'."
            );
        }
    }

    protected function coordinationData(): array
    {
        return [
            'wakeAt' => $this->wakeAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws Exception
     */
    public static function fromArray(array $data): self
    {
        $request = new self(
            new DateTimeImmutable((string) ($data['wakeAt'] ?? 'now')),
        );

        return isset($data['interruptId'])
            ? $request->withId((int) $data['interruptId'])
            : $request;
    }
}
