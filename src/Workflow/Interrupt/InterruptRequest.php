<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

/**
 * Container for interrupt actions that require human approval.
 *
 * When a workflow needs human input, it throws a WorkflowInterrupt containing
 * an InterruptRequest. The request includes:
 * - One or more actions requiring decisions
 * - A reason for the interruption
 *
 * The application processes these actions, gets user decisions, and resumes
 * the workflow by passing the updated request back.
 *
 * Example:
 * ```php
 * // Middleware creates request
 * $request = new InterruptRequest(
 *     actions: [
 *         new Action('delete_1', 'Delete File', 'Delete /var/log/old.txt'),
 *         new Action('exec_1', 'Execute Command', 'Run: rm -rf /tmp/*'),
 *     ],
 *     reason: 'Dangerous operations require approval'
 * );
 *
 * throw new WorkflowInterrupt($request, ...);
 *
 * // Application handles
 * foreach ($request->actions as $action) {
 *     if (askUser($action->name)) {
 *         $action->approve();
 *     } else {
 *         $action->reject('User denied');
 *     }
 * }
 *
 * // Resume workflow
 * $workflow->start($request);
 * ```
 */
class InterruptRequest implements \JsonSerializable
{
    /**
     * @param Action[] $actions Actions requiring approval
     * @param string $reason Human-readable reason for the interruption
     */
    public function __construct(
        public array $actions,
        protected string $reason = ''
    ) {
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Get an action by its ID.
     *
     * @param string $id Action ID
     * @return Action|null The action or null if not found
     */
    public function getAction(string $id): ?Action
    {
        foreach ($this->actions as $action) {
            if ($action->id === $id) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Check if all actions are approved.
     */
    public function allApproved(): bool
    {
        foreach ($this->actions as $action) {
            if (!$action->isApproved()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if any action is rejected.
     */
    public function hasRejections(): bool
    {
        foreach ($this->actions as $action) {
            if ($action->isRejected()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if all actions have been decided (no pending actions).
     */
    public function isComplete(): bool
    {
        foreach ($this->actions as $action) {
            if ($action->isPending()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all pending actions.
     *
     * @return Action[]
     */
    public function getPendingActions(): array
    {
        return \array_filter($this->actions, fn (Action $a) => $a->isPending());
    }

    /**
     * Get all approved actions.
     *
     * @return Action[]
     */
    public function getApprovedActions(): array
    {
        return \array_filter($this->actions, fn (Action $a) => $a->isApproved());
    }

    /**
     * Get all rejected actions.
     *
     * @return Action[]
     */
    public function getRejectedActions(): array
    {
        return \array_filter($this->actions, fn (Action $a) => $a->isRejected());
    }

    /**
     * Serialize to JSON.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'reason' => $this->reason,
            'actions' => \array_map(fn (Action $a) => $a->jsonSerialize(), $this->actions),
        ];
    }

    /**
     * Create from array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $actions = \array_map(
            fn (array $actionData) => Action::fromArray($actionData),
            $data['actions']
        );

        return new self($actions, $data['reason'] ?? '');
    }
}
