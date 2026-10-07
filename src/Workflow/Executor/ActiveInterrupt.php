<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use JsonException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\RefusalReason;
use stdClass;

use function array_map;
use function get_object_vars;
use function is_array;
use function json_decode;
use function json_encode;
use function ksort;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const SORT_STRING;

final class ActiveInterrupt
{
    public function __construct(
        public readonly InterruptRequest $request,
        public readonly string $stepId,
        public readonly ?ResumeInput $input = null,
    ) {
    }

    public function withInput(ResumeInput $input): self
    {
        if ($this->input instanceof ResumeInput) {
            if (
                $this->input->kind !== $input->kind
                || self::canonical($this->input->payload) !== self::canonical($input->payload)
            ) {
                throw new WorkflowRefusedException(
                    "Interrupt {$this->request->getId()} already has an accepted input; its answer cannot change.",
                    RefusalReason::NotAwaited,
                );
            }

            return $this;
        }

        return new self($this->request, $this->stepId, $input);
    }

    /**
     * The payload as JSON with the members of every object sorted: a redelivered
     * answer may come re-encoded with its keys in another order, while list order
     * and value types still tell answers apart.
     *
     * @param array<string, mixed>|null $payload
     * @throws JsonException
     */
    protected static function canonical(?array $payload): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

        return json_encode(self::sortMembers(json_decode(json_encode($payload, $flags), false, 512, JSON_THROW_ON_ERROR)), $flags);
    }

    protected static function sortMembers(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $members = get_object_vars($value);
            ksort($members, SORT_STRING);

            return (object) array_map(self::sortMembers(...), $members);
        }

        return is_array($value) ? array_map(self::sortMembers(...), $value) : $value;
    }
}
