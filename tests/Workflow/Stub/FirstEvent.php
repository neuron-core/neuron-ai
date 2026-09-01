<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use NeuronAI\Workflow\Events\Event;

class FirstEvent implements Event
{
    public function __construct(public string $message = 'First Event')
    {
    }
}
