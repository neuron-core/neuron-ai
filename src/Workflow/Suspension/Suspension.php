<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Suspension;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\InterruptType;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Resume\ResumeInput;
use NeuronAI\Workflow\Resume\ResumeKind;

final class Suspension implements JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly InterruptType $type,
        public readonly ?string $eventName = null,
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly ?DateTimeImmutable $wakeAt = null,
    ) {
    }

    public static function fromRequest(int $id, object $request): self
    {
        if ($request instanceof WaitForEventRequest) {
            return new self(
                id: $id,
                type: InterruptType::WaitForEvent,
                eventName: $request->getEventName(),
                expiresAt: $request->getExpiresAt(),
            );
        }

        if ($request instanceof SleepUntilRequest) {
            return new self(
                id: $id,
                type: InterruptType::SleepUntil,
                wakeAt: $request->getWakeAt(),
            );
        }

        throw new WorkflowException('Unsupported interruption request: ' . $request::class);
    }

    public function validate(ResumeInput $input): void
    {
        $valid = match ($input->kind) {
            ResumeKind::Event => $this->type === InterruptType::WaitForEvent,
            ResumeKind::Expired => $this->type === InterruptType::WaitForEvent
                && $this->expiresAt instanceof DateTimeImmutable,
            ResumeKind::Timer => $this->type === InterruptType::SleepUntil,
        };

        if (!$valid) {
            throw new WorkflowException(
                "Resume input '{$input->kind->value}' is incompatible with suspension {$this->id} "
                . "of type '{$this->type->value}'."
            );
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = [
            'suspensionId' => $this->id,
            'type' => $this->type->value,
        ];

        if ($this->type === InterruptType::WaitForEvent) {
            $data['eventName'] = $this->eventName;
            if ($this->expiresAt instanceof DateTimeImmutable) {
                $data['expiresAt'] = $this->expiresAt->format(DateTimeInterface::ATOM);
            }
        } elseif ($this->wakeAt instanceof DateTimeImmutable) {
            $data['wakeAt'] = $this->wakeAt->format(DateTimeInterface::ATOM);
        }

        return $data;
    }
}
