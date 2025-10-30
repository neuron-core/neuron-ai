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
     * @param string $message Human-readable reason for the interruption
     */
    public function __construct(
        public array $actions,
        protected string $message = ''
    ) {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get all pending actions.
     *
     * @return Action[]
     */
    public function getPendingActions(): array
    {
        return \array_filter($this->actions, fn (Action $a): bool => $a->isPending());
    }

    /**
     * Get all approved actions.
     *
     * @return Action[]
     */
    public function getApprovedActions(): array
    {
        return \array_filter($this->actions, fn (Action $a): bool => $a->isApproved());
    }

    /**
     * Get all rejected actions.
     *
     * @return Action[]
     */
    public function getRejectedActions(): array
    {
        return \array_filter($this->actions, fn (Action $a): bool => $a->isRejected());
    }

    /**
     * Serialize to JSON.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'reason' => $this->message,
            'actions' => \array_map(fn (Action $a): array => $a->jsonSerialize(), $this->actions),
        ];
    }
}
