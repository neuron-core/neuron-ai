<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use NeuronAI\Workflow\Events\Event;

class IgnitionStartEvent implements Event
{
    public function __construct(public string $message = 'ignite')
    {
    }
}
