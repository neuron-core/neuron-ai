<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

interface DescibeExporterTransitions
{
    /**
     * Describe topology that cannot be inferred from the node return type.
     * Implementing this interface replaces return-type inference for the node.
     *
     * @return list<ExporterTransition>
     */
    public function describe(): array;
}
