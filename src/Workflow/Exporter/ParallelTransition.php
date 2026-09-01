<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\ParallelEvent;

final class ParallelTransition implements ExporterTransition
{
    /**
     * @param class-string<ParallelEvent> $event
     * @param array<string, class-string<Event>> $branches
     */
    public function __construct(
        public readonly string $event,
        public readonly array $branches,
    ) {
    }
}
