<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Events\Event;

class SecondEvent implements Event
{
    public function __construct(public string $message = 'Second Event')
    {
    }
}
