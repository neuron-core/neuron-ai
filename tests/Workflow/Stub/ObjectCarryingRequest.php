<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use Closure;

/**
 * A custom WaitForEventRequest specialization that carries a non-serializable
 * object (a closure). Under fire-and-forget the request is never persisted, so
 * this must survive a FilePersistence serialize round-trip across a suspend.
 */
class ObjectCarryingRequest extends WaitForEventRequest
{
    public function __construct(string $eventName, public readonly Closure $callback)
    {
        parent::__construct($eventName);
    }
}
