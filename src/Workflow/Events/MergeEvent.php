<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Events;

class MergeEvent implements Event
{
    /**
     * @param Event[] $events
     */
    public function __construct(protected array $events)
    {
    }

    public function getEvent(string $nodeName): Event
    {
        return $this->events[$nodeName];
    }

    /**
     * @return Event[]
     */
    public function getEvents(): array
    {
        return $this->events;
    }
}
