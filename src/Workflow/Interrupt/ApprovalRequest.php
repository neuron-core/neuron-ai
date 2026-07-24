<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use DateTimeImmutable;

use function array_filter;
use function array_values;
use function is_array;
use function json_decode;
use function json_encode;

class ApprovalRequest extends WaitForEventRequest
{
    /**
     * @var array<string, Action> $actions
     */
    protected array $actions = [];

    /**
     * @param string                 $message   Human-readable reason for the interruption
     * @param Action[]               $actions   Actions requiring approval
     * @param DateTimeImmutable|null $expiresAt Optional auto-resolve deadline (e.g. auto-reject)
     */
    public function __construct(
        protected string $message,
        array $actions = [],
        ?DateTimeImmutable $expiresAt = null,
    ) {
        // A human decision is an external event delivered on the "approval"
        // channel; type() is inherited as WaitForEvent. Action[] lives in this
        // subclass as the specialized OUTBOUND payload (the decisions come back
        // inbound via the wake, decoded by ToolApproval).
        parent::__construct('approval', $expiresAt);

        foreach ($actions as $action) {
            $this->addAction($action);
        }
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function addAction(Action $action): self
    {
        $this->actions[$action->id] = $action;
        return $this;
    }

    public function getAction(string $id): ?Action
    {
        return $this->actions[$id] ?? null;
    }

    /**
     * @return Action[]
     */
    public function getActions(): array
    {
        return array_values($this->actions);
    }

    /**
     * @param Action[] $actions
     */
    public function setActions(array $actions): self
    {
        foreach ($actions as $action) {
            $this->addAction($action);
        }
        return $this;
    }

    /**
     * Get all pending actions.
     *
     * @return array<string, Action>
     */
    public function getPendingActions(): array
    {
        return array_filter($this->actions, fn (Action $a): bool => $a->isPending());
    }

    /**
     * Get all approved actions.
     *
     * @return array<string, Action>
     */
    public function getApprovedActions(): array
    {
        return array_filter($this->actions, fn (Action $a): bool => $a->isApproved());
    }

    /**
     * Get all rejected actions.
     *
     * @return array<string, Action>
     */
    public function getRejectedActions(): array
    {
        return array_filter($this->actions, fn (Action $a): bool => $a->isRejected());
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
            'actions' => json_encode(array_values($this->actions)),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $instance = new self($data['message']);

        if (!isset($data['actions'])) {
            return $instance;
        }

        $actionsData = is_array($data['actions'])
            ? $data['actions']
            : json_decode((string) $data['actions'], true);

        foreach ($actionsData as $actionData) {
            $instance->addAction(Action::fromArray($actionData));
        }
        return $instance;
    }
}
