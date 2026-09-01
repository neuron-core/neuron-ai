<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use DateTimeImmutable;
use NeuronAI\Exceptions\WorkflowException;

use function array_values;
use function array_map;
use function sprintf;

/**
 * Outbound request carrying the actions that require a human decision before
 * the workflow may proceed. A pure OUTBOUND snapshot for the caller to render
 * — never handed back into the workflow. The decisions travel inbound as a
 * resume payload keyed by action id:
 *
 *   ['<callId>' => 'approve' | 'reject' | ['reject', $reason]]
 *
 * The request is persisted while active. Its actions and all custom metadata
 * must therefore remain portable values; live services and other runtime
 * objects belong on the node, not in the request.
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
     * @throws WorkflowException When two actions share an id — the decision payload is keyed
     *                           by action id, so a duplicate action would be forever
     *                           undecidable (a silent deadlock).
     */
    public function __construct(
        protected string $message,
        array $actions = [],
        ?DateTimeImmutable $expiresAt = null,
    ) {
        // A human decision is an external event on the "approval" channel;
        // type() is inherited as WaitForEvent.
        parent::__construct('approval', $expiresAt);

        foreach ($actions as $action) {
            if (isset($this->actions[$action->id])) {
                throw new WorkflowException(sprintf(
                    'Duplicate approval action id "%s": every action needs a unique id, or its decision cannot be delivered.',
                    $action->id
                ));
            }

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
    protected function metadata(): array
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
