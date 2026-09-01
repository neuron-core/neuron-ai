<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

use NeuronAI\Workflow\Events\Event;

final class EventTransition implements ExporterTransition
{
    /**
     * @param class-string<Event> $event
     */
    public function __construct(public readonly string $event)
    {
    }
}
