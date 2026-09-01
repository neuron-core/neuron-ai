<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

use function str_replace;

class MermaidExporter implements ExporterInterface
{
    public function export(WorkflowGraph $graph): string
    {
        $output = "graph TD\n";

        foreach ($graph->getVertices() as $vertex) {
            $output .= '    ' . $this->renderVertex($vertex) . "\n";
        }

        foreach ($graph->getEdges() as $edge) {
            $label = $edge->label === null
                ? '-->'
                : '-->|"' . $this->escape($edge->label) . '"|';
            $output .= "    {$edge->from} {$label} {$edge->to}\n";
        }

        return $output;
    }

    protected function renderVertex(WorkflowGraphVertex $vertex): string
    {
        $label = $this->escape($vertex->label);

        return match ($vertex->type) {
            WorkflowGraphVertexType::Event => "{$vertex->id}[\"{$label}\"]",
            WorkflowGraphVertexType::Node => "{$vertex->id}[[\"{$label}\"]]",
            WorkflowGraphVertexType::ParallelSplit,
            WorkflowGraphVertexType::ParallelJoin => "{$vertex->id}{\"{$label}\"}",
        };
    }

    protected function escape(string $value): string
    {
        return str_replace(['&', '"'], ['&amp;', '&quot;'], $value);
    }
}
