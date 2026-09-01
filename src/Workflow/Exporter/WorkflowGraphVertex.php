<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

final class WorkflowGraphVertex
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly WorkflowGraphVertexType $type,
        public readonly ?string $class = null,
    ) {
    }
}
