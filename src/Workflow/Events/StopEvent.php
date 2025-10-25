<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Events;

use NeuronAI\Workflow\Events\Event;

class StopEvent implements Event
{
    public function __construct(protected mixed $result = null)
    {
    }

    public function getResult(): mixed
    {
        return $this->result;
    }
}
