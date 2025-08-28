<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

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
