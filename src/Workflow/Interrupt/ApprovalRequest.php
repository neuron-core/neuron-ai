<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use DateTimeImmutable;

use function array_values;

/**
 * Outbound request carrying the tool calls (or other actions) that require a human decision before
 * the workflow may proceed.
 *
 * This is a pure OUTBOUND value (see ADR 0001): it describes what the caller must render to a human
 * and is never handed back into the workflow. The human's decisions travel inbound as a separate
 * resume payload, which {@see generatePayload()} produces from the decisions recorded on this
 * request's actions.
 *
 * The request is not mutated after construction — there are no per-action add/set helpers. A caller
 * that round-trips it through a UI reconstructs a fresh instance with {@see fromArray()} (carrying
 * the decisions) and turns that into the resume payload with {@see generatePayload()}.
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

    /**
     * Reconstruct from a serialized shape (e.g. a UI submission). Symmetric with
     * {@see jsonSerialize()}: `actions` is a nested array of action data.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $instance = new self($data['message']);

        foreach ($data['actions'] ?? [] as $actionData) {
            $action = $actionData instanceof Action ? $actionData : Action::fromArray($actionData);
            $instance->actions[$action->id] = $action;
        }

        return $instance;
    }

    /**
     * Build the inbound resume payload from the decisions recorded on this request's actions.
     *
     * Emits one entry per DECIDED action, keyed by action id; pending actions are omitted (a tool
     * runs only on explicit approval — silence is never consent, see ADR 0002). The shape is the
     * ToolApproval payload contract:
     *
     *   ['<id>' => 'approve' | 'reject' | ['reject', $reason]]
     *
     * Because pending actions are omitted, a request progressively filled with decisions yields a
     * cumulative payload — which is what the stateless ToolApproval gate requires to recognize a
     * resume as complete.
     *
     * @return array<string, mixed>
     */
    public function generatePayload(): array
    {
        $payload = [];

        foreach ($this->actions as $action) {
            if ($action->decision === ActionDecision::Pending) {
                continue;
            }

            if ($action->decision === ActionDecision::Rejected) {
                $payload[$action->id] = $action->feedback !== null
                    ? ['reject', $action->feedback]
                    : 'reject';
            } else {
                $payload[$action->id] = 'approve';
            }
        }

        return $payload;
    }
}
