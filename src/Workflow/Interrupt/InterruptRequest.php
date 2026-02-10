<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use JsonSerializable;

abstract class InterruptRequest implements JsonSerializable
{
    /**
     * @param string $message Human-readable reason for the interruption
     */
    public function __construct(protected string $message)
    {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function jsonSerialize(): array;
}
