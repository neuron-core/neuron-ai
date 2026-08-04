<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\Event;

class Step2Event implements Event
{
    public function __construct(public readonly string $message = 'step2')
    {
    }
}
