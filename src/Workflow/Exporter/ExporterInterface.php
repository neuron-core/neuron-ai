<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

use NeuronAI\Workflow\NodeInterface;

interface ExporterInterface
{
    /**
     * @param array<string, NodeInterface> $eventNodeMap
     */
    public function export(array $eventNodeMap): string;
}
