<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use DateTimeImmutable;

use function array_values;
use function array_map;

/**
 * Outbound request carrying the tool calls (or other actions) that require a human decision
 * before the workflow may proceed.
 *
 * This is a pure OUTBOUND value (ADR 0001): a convenience snapshot of what the caller must
 * render to a human. It describes the actions and their current decision state — it is never
 * handed back into the workflow and carries no round-trip machinery.
 *
 * The human's decisions travel inbound as a separate, INCREMENTAL resume payload keyed by
 * action id (the tool callId), not via this object:
 *
 *   ['<callId>' => 'approve' | 'reject' | ['reject', $reason]]
 *
 * Pending approval state itself is persisted in chat history (ADR 0003) — the system of
 * record the UI already reads. This request is rebuilt fresh by the middleware on every
 * pass (replay-by-rerun), so it is not persisted and may safely reference real objects.
 */
class ApprovalRequest extends WaitForEventRequest
{
    /**
     * @var array<string, Action>
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
        // A human decision is an external event delivered on the "approval" channel; type() is
        // inherited as WaitForEvent. Action[] is the specialized OUTBOUND payload.
        parent::__construct('approval', $expiresAt);

        foreach ($actions as $action) {
            $this->actions[$action->id] = $action;
        }
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return Action[]
     */
    public function getActions(): array
    {
        return array_values($this->actions);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
            'actions' => array_map(
                static fn (Action $action): array => $action->jsonSerialize(),
                array_values($this->actions),
            ),
        ];
    }
}
