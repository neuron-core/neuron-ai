<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use JsonSerializable;

final class CountingPayload implements JsonSerializable
{
    public int $calls = 0;

    public function __construct(protected string $value = 'hello')
    {
    }

    public function jsonSerialize(): string
    {
        ++$this->calls;
        return $this->value;
    }
}
