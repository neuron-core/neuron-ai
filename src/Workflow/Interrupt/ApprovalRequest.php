<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use function array_filter;
use function array_values;
use function json_encode;

class ApprovalRequest extends InterruptRequest
{
    /**
     * @var array<string, Action> $actions
     */
    protected array $actions = [];

    /**
     * @param string $message Human-readable reason for the interruption
     * @param Action[] $actions Actions requiring approval
     */
    public function __construct(string $message, array $actions = [])
    {
        parent::__construct($message);

        foreach ($actions as $action) {
            $this->addAction($action);
        }
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
        foreach ($data['actions'] as $actionData) {
            $instance->addAction(Action::fromArray($actionData));
        }
        return $instance;
    }
}
