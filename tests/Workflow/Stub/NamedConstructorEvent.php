<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use NeuronAI\Workflow\Events\Event;

final class NamedConstructorEvent implements Event
{
    protected function __construct(public readonly string $orderId)
    {
    }

    public static function forOrder(string $orderId): self
    {
        return new self($orderId);
    }
}
