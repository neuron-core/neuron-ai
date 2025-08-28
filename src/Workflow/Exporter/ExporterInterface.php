<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

use NeuronAI\Workflow\Workflow;

interface ExporterInterface
{
    public function export(Workflow $workflow): string;
}
