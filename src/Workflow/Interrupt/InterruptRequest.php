<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use JsonSerializable;

use function array_filter;
use function array_map;
use function array_values;

class InterruptRequest implements JsonSerializable
{
    /**
     * @var array<string, Action> $actions
     */
    protected array $actions = [];

    /**
     * @param string $message Human-readable reason for the interruption
     * @param Action[] $actions Actions requiring approval
     */
    public function __construct(protected string $message, array $actions = [])
    {
        foreach ($actions as $action) {
            $this->addAction($action);
        }
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function addAction(Action $action): InterruptRequest
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
    public function setActions(array $actions): InterruptRequest
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
     * Serialize to JSON.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
            'actions' => array_map(fn (Action $a): array => $a->jsonSerialize(), $this->actions),
        ];
    }

    public static function fromArray(array $data): InterruptRequest
    {
        $instance = new self($data['message']);
        foreach ($data['actions'] as $actionData) {
            $instance->addAction(Action::fromArray($actionData));
        }
        return $instance;
    }
}
