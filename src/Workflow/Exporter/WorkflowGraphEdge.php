<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

final class WorkflowGraphEdge
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $label = null,
    ) {
    }
}
