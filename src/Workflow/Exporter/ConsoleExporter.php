<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

use NeuronAI\Workflow\Events\StopEvent;

use function is_a;
use function str_repeat;

class ConsoleExporter implements ExporterInterface
{
    public function export(WorkflowGraph $graph): string
    {
        $output = "Workflow Structure:\n";
        $output .= str_repeat('=', 50) . "\n\n";
        $rendered = [];
        $path = [];
        $output .= $this->renderVertex($graph, $graph->startVertexId, $rendered, $path);

        foreach ($graph->getVertices() as $vertex) {
            if ($vertex->type !== WorkflowGraphVertexType::Event || isset($rendered[$vertex->id])) {
                continue;
            }

            $output .= "\n" . str_repeat('─', 30) . "\n";
            $output .= "Orphaned Node:\n";
            $output .= $this->renderVertex($graph, $vertex->id, $rendered, $path);
        }

        return $output;
    }

    /**
     * @param array<string, true> $rendered
     * @param array<string, true> $path
     */
    protected function renderVertex(
        WorkflowGraph $graph,
        string $vertexId,
        array &$rendered,
        array &$path,
        int $depth = 0,
    ): string {
        $vertex = $graph->getVertex($vertexId);
        $indent = str_repeat('  ', $depth);

        if (isset($path[$vertexId])) {
            return $indent . "↻ {$vertex->label} [Cycle detected]\n";
        }

        if (isset($rendered[$vertexId])) {
            return $indent . "↳ {$vertex->label} [Already shown]\n";
        }

        $path[$vertexId] = true;
        $rendered[$vertexId] = true;
        $output = $indent . $this->icon($graph, $vertex) . ' ' . $vertex->label . "\n";

        foreach ($graph->getOutgoingEdges($vertexId) as $edge) {
            $output .= $indent . '   ';
            $output .= $edge->label === null ? "↓\n" : "[{$edge->label}] ↓\n";
            $output .= $this->renderVertex($graph, $edge->to, $rendered, $path, $depth + 1);
        }

        unset($path[$vertexId]);

        return $output;
    }

    protected function icon(WorkflowGraph $graph, WorkflowGraphVertex $vertex): string
    {
        if (
            $vertex->id === $graph->startVertexId
            || ($vertex->class !== null && is_a($vertex->class, StopEvent::class, true))
        ) {
            return '🏁';
        }

        return match ($vertex->type) {
            WorkflowGraphVertexType::Event => '🔗',
            WorkflowGraphVertexType::Node => '⚡',
            WorkflowGraphVertexType::ParallelSplit => '⑂',
            WorkflowGraphVertexType::ParallelJoin => '⑃',
        };
    }
}
