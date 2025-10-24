<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

/**
 * Represents a single approvable action in an interrupt request.
 *
 * Actions describe operations that require human approval before execution.
 * Each action has:
 * - Unique identifier
 * - Human-readable name and description
 * - Decision state (pending, approved, rejected)
 * - Optional feedback from the approver
 *
 * Example:
 * ```php
 * $action = new Action(
 *     id: 'delete_file_1',
 *     name: 'Delete File',
 *     description: 'Delete /var/log/old_logs.txt',
 *     metadata: ['path' => '/var/log/old_logs.txt']
 * );
 *
 * // User approves
 * $action->approve('Approved - file is outdated');
 *
 * // Or rejects
 * $action->reject('Rejected - file still needed');
 * ```
 */
class Action implements \JsonSerializable
{
    /**
     * @param string $id Unique identifier for this action
     * @param string $name Short human-readable name
     * @param string $description Detailed description of what this action does
     * @param ActionDecision $decision Current decision state
     * @param string|null $feedback Optional feedback from the approver
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public ActionDecision $decision = ActionDecision::Pending,
        public ?string $feedback = null,
    ) {
    }

    /**
     * Mark this action as approved.
     *
     * @param string|null $feedback Optional feedback message
     */
    public function approve(?string $feedback = null): void
    {
        $this->decision = ActionDecision::Approved;
        if ($feedback !== null) {
            $this->feedback = $feedback;
        }
    }

    /**
     * Mark this action as rejected.
     *
     * @param string $feedback Reason for rejection
     */
    public function reject(string $feedback): void
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
        if ($feedback !== null) {
            $this->feedback = $feedback;
        }
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
            $data['description'],
            ActionDecision::from($data['decision']),
            $data['feedback'] ?? null,
        );
    }
}
