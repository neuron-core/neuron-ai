<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use JsonSerializable;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\StaticConstructor;
use stdClass;

use function is_array;
use function json_encode;

/**
 * A single tool invocation as conversation data.
 *
 * This is what travels — in messages, stream chunks, observability events,
 * persistence, and across fork boundaries: the call record (name, callId,
 * inputs) plus the call's outcome state (result, approval decision). It is
 * plain data and serializes natively.
 *
 * Execution capability stays on the live ToolInterface registry: ToolNode
 * resolves the call's name against it and stamps the result back here. A
 * ToolCall can never execute anything.
 *
 * @method static static make(string $name, ?string $callId = null, array $inputs = [], ?string $description = null)
 */
class ToolCall implements JsonSerializable
{
    use StaticConstructor;

    protected string|ToolOutput|null $result = null;

    /**
     * Approval state of this specific call. Null = not approval-gated.
     */
    protected ?ApprovalState $approvalState = null;

    /**
     * Rejection reason. Only ever non-null when $approvalState is Rejected.
     */
    protected ?string $rejectReason = null;

    /**
     * Why this call is asking for approval — the tool author's (or attach-time
     * policy's) outbound message to the approver.
     */
    protected ?string $approvalReason = null;

    /**
     * @param array<string, mixed> $inputs
     */
    public function __construct(
        protected string $name,
        protected ?string $callId = null,
        protected array $inputs = [],
        protected ?string $description = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCallId(): ?string
    {
        return $this->callId;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputs(): array
    {
        return $this->inputs;
    }

    public function getInput(string $key): mixed
    {
        return $this->inputs[$key] ?? null;
    }

    public function setCallId(?string $callId): self
    {
        $this->callId = $callId;
        return $this;
    }

    /**
     * @param array<string, mixed> $inputs
     */
    public function setInputs(array $inputs): self
    {
        $this->inputs = $inputs;
        return $this;
    }

    /**
     * Whether this call has settled with a result — false for a call that never
     * executed (e.g. still pending approval).
     */
    public function hasResult(): bool
    {
        return $this->result !== null;
    }

    /**
     * @throws ToolException When the call has no result — check hasResult() first.
     */
    public function getResult(): string|ToolOutput
    {
        if ($this->result === null) {
            throw new ToolException("Tool call {$this->name} has no result: it was never executed.");
        }

        return $this->result;
    }

    /**
     * Record the call's result. Mirrors Tool::setResult() normalization:
     * a ToolOutput is kept as-is, arrays are JSON-encoded, anything else is
     * cast to string.
     */
    public function setResult(mixed $result): self
    {
        if ($result instanceof ToolOutput) {
            $this->result = $result;
        } else {
            $this->result = is_array($result) ? (string) json_encode($result) : (string) $result;
        }

        return $this;
    }

    public function getApprovalState(): ?ApprovalState
    {
        return $this->approvalState;
    }

    /**
     * Record the approval state. $reason is meaningful only for rejections
     * (the approver's feedback); any other state clears it.
     */
    public function setApprovalState(ApprovalState $state, ?string $reason = null): self
    {
        $this->approvalState = $state;
        $this->rejectReason = $state === ApprovalState::Rejected ? $reason : null;
        return $this;
    }

    public function getRejectReason(): ?string
    {
        return $this->rejectReason;
    }

    public function getApprovalReason(): ?string
    {
        return $this->approvalReason;
    }

    public function setApprovalReason(?string $reason): self
    {
        $this->approvalReason = $reason;
        return $this;
    }

    /**
     * The wire shape is identical to the serialized tool entries of earlier
     * versions, so stored histories and approval UIs are unaffected.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'callId' => $this->callId,
            'name' => $this->name,
            'description' => $this->description,
            'inputs' => $this->inputs === [] ? new stdClass() : $this->inputs,
            'result' => $this->result instanceof ToolOutput ? $this->result->jsonSerialize() : $this->result,
            'approval' => $this->approvalState?->value,
            'approvalReason' => $this->approvalReason,
            'rejectReason' => $this->rejectReason,
        ];
    }
}
