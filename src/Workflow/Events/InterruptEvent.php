<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Events;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/** The workflow's current request for external input. */
class InterruptEvent implements Event
{
    public function __construct(public readonly InterruptRequest $request)
    {
    }

    public static function fromRequest(InterruptRequest $request): self
    {
        return new self($request);
    }
}
