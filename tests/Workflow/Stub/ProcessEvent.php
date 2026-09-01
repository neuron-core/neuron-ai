<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use NeuronAI\Workflow\Events\Event;

class ProcessEvent implements Event
{
    public function __construct(public readonly string $value)
    {
    }
}
