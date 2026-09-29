<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Events;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * The workflow's current request for external input, and the wait in its
 * node that raised it: the answer reaches that wait when the node runs again.
 */
class InterruptEvent implements Event
{
    public function __construct(
        public readonly InterruptRequest $request,
        public readonly ?string $wait = null,
    ) {
    }

    public static function fromRequest(InterruptRequest $request, ?string $wait = null): self
    {
        return new self($request, $wait);
    }
}
