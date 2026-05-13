<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\Event;

class ChunkEvent implements Event
{
    public function __construct(public readonly string $payload)
    {
    }
}
