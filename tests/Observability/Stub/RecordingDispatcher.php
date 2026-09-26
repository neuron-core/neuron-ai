<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability\Stub;

use Psr\EventDispatcher\EventDispatcherInterface;

class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var object[] */
    public array $events = [];

    public function __construct(protected ?object $returns = null)
    {
    }

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $this->returns ?? $event;
    }
}
