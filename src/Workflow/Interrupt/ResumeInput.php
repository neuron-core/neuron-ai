<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use JsonException;
use JsonSerializable;
use NeuronAI\Exceptions\WorkflowException;

use function is_array;
use function is_int;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class ResumeInput implements JsonSerializable
{
    /**
     * @param array<string, mixed>|null $payload
     * @throws WorkflowException
     */
    protected function __construct(
        public readonly int        $interruptId,
        public readonly ResumeType $kind,
        public readonly ?array     $payload = null,
    ) {
        if ($this->interruptId < 1) {
            throw new WorkflowException('An interrupt ID must be a positive integer.');
        }

        if ($this->kind === ResumeType::Event) {
            try {
                json_encode($this->payload, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new WorkflowException('A resume event payload must be JSON-compatible.', $e->getCode(), previous: $e);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @throws WorkflowException
     */
    public static function event(InterruptRequest $request, array $payload): self
    {
        return new self($request->getId(), ResumeType::Event, $payload);
    }

    /**
     * @throws WorkflowException
     */
    public static function expired(InterruptRequest $request): self
    {
        return new self($request->getId(), ResumeType::Expired);
    }

    /**
     * @throws WorkflowException
     */
    public static function timer(InterruptRequest $request): self
    {
        return new self($request->getId(), ResumeType::Timer);
    }

    /**
     * @param array<string, mixed> $data
     * @throws WorkflowException
     */
    public static function fromArray(array $data): self
    {
        if (!is_int($data['interruptId'] ?? null)) {
            throw new WorkflowException('Resume input interruptId must be an integer.');
        }

        return match ($data['kind'] ?? null) {
            ResumeType::Event->value => is_array($data['payload'] ?? null)
                ? new self($data['interruptId'], ResumeType::Event, $data['payload'])
                : throw new WorkflowException('A resume event input requires an array payload.'),
            ResumeType::Expired->value => new self($data['interruptId'], ResumeType::Expired),
            ResumeType::Timer->value => new self($data['interruptId'], ResumeType::Timer),
            default => throw new WorkflowException('Unknown resume input kind.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'interruptId' => $this->interruptId,
            'kind' => $this->kind->value,
        ];

        if ($this->kind === ResumeType::Event) {
            $data['payload'] = $this->payload;
        }

        return $data;
    }
}
