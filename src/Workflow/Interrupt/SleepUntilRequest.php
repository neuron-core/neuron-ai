<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Resume\ResumeInput;
use NeuronAI\Workflow\Resume\ResumeKind;

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
