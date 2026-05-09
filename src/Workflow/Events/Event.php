<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Events;

use function serialize;
use function unserialize;

abstract class Event
{
    public function toSnapshot(): string
    {
        return serialize($this);
    }

    public static function fromSnapshot(string $snapshot): static
    {
        return unserialize($snapshot);
    }
}
