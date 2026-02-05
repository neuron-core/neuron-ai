<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use JsonSerializable;

class Action implements JsonSerializable
{
    /**
     * @param string $id Unique identifier for this action
     * @param string $name Short human-readable name
     * @param string|null $description Detailed description of what this action does
     * @param ActionDecision $decision Current decision state
     * @param string|null $feedback Optional feedback from the approver
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $description = null,
        public ActionDecision $decision = ActionDecision::Pending,
        public ?string $feedback = null,
    ) {
    }

    public function decision(?ActionDecision $decision = null): ActionDecision
    {
        if ($decision instanceof ActionDecision) {
            $this->decision = $decision;
        }
        return $this->decision;
    }

    public function feedback(?string $feedback = null): ?string
    {
        $this->feedback = $feedback;
        return $this->feedback;
    }

    /**
     * Mark this action as approved.
     *
     * @param string|null $feedback Optional feedback message
     */
    public function approve(?string $feedback = null): void
    {
        $this->decision = ActionDecision::Approved;
        $this->feedback = $feedback;
    }

    /**
     * Mark this action as rejected.
     *
     * @param string|null $feedback Optional reason for rejection
     */
    public function reject(?string $feedback = null): void
    {
        $this->decision = ActionDecision::Rejected;
        $this->feedback = $feedback;
    }

    /**
     * Mark this action as edited.
     *
     * @param string|null $feedback Optional explanation of the edit
     */
    public function edit(?string $feedback = null): void
    {
        $this->decision = ActionDecision::Edit;
        $this->feedback = $feedback;
    }

    /**
     * Check if this action is pending.
     */
    public function isPending(): bool
    {
        return $this->decision->isPending();
    }

    /**
     * Check if this action is approved.
     */
    public function isApproved(): bool
    {
        return $this->decision->isApproved();
    }

    /**
     * Check if this action is rejected.
     */
    public function isRejected(): bool
    {
        return $this->decision->isRejected();
    }

    /**
     * Check if this action is edited.
     */
    public function isEdited(): bool
    {
        return $this->decision === ActionDecision::Edit;
    }

    /**
     * Serialize to JSON.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'decision' => $this->decision->value,
            'feedback' => $this->feedback,
        ];
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['name'],
            $data['description'] ?? null,
            ActionDecision::from($data['decision']),
            $data['feedback'] ?? null,
        );
    }
}
