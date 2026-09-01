<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Resume;

use JsonException;
use JsonSerializable;
use NeuronAI\Exceptions\WorkflowException;

use function is_array;
use function is_int;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class ResumeInput implements JsonSerializable
{
    /** @param array<string, mixed>|null $payload */
    protected function __construct(
        public readonly int $interruptId,
        public readonly ResumeKind $kind,
        public readonly ?array $payload = null,
    ) {
        if ($this->interruptId < 1) {
            throw new WorkflowException('An interrupt ID must be a positive integer.');
        }

        if ($this->kind === ResumeKind::Event) {
            try {
                json_encode($this->payload, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new WorkflowException('A resume event payload must be JSON-compatible.', $e->getCode(), previous: $e);
            }
        }
    }

    /** @param array<string, mixed> $payload */
    public static function event(int $interruptId, array $payload): self
    {
        return new self($interruptId, ResumeKind::Event, $payload);
    }

    public static function expired(int $interruptId): self
    {
        return new self($interruptId, ResumeKind::Expired);
    }

    public static function timer(int $interruptId): self
    {
        return new self($interruptId, ResumeKind::Timer);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!is_int($data['interruptId'] ?? null)) {
            throw new WorkflowException('Resume input interruptId must be an integer.');
        }

        return match ($data['kind'] ?? null) {
            ResumeKind::Event->value => is_array($data['payload'] ?? null)
                ? self::event($data['interruptId'], $data['payload'])
                : throw new WorkflowException('A resume event input requires an array payload.'),
            ResumeKind::Expired->value => self::expired($data['interruptId']),
            ResumeKind::Timer->value => self::timer($data['interruptId']),
            default => throw new WorkflowException('Unknown resume input kind.'),
        };
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = [
            'interruptId' => $this->interruptId,
            'kind' => $this->kind->value,
        ];

        if ($this->kind === ResumeKind::Event) {
            $data['payload'] = $this->payload;
        }

        return $data;
    }
}
