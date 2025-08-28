<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Event;

class FirstEvent implements Event
{
    public function __construct(public string $message = 'First Event')
    {
    }
}
