<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

interface ExporterInterface
{
    public function export(WorkflowGraph $graph): string;
}
