<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use Psr\EventDispatcher\EventDispatcherInterface;

class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var object[] */
    public array $dispatched = [];

    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;

        return $event;
    }
}
