<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Events\Event;

class FirstEvent extends Event
{
    public function __construct(public string $message = 'First Event')
    {
    }
}
