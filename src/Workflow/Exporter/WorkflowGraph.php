<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

use InvalidArgumentException;

use function array_values;

final class WorkflowGraph
{
    /** @var array<string, WorkflowGraphVertex> */
    protected array $vertices = [];

    /** @var array<string, WorkflowGraphEdge> */
    protected array $edges = [];

    public function __construct(public readonly string $startVertexId)
    {
    }

    public function addVertex(WorkflowGraphVertex $vertex): void
    {
        $this->vertices[$vertex->id] = $vertex;
    }

    public function addEdge(WorkflowGraphEdge $edge): void
    {
        $key = $edge->from . "\0" . $edge->to . "\0" . $edge->label;
        $this->edges[$key] = $edge;
    }

    /** @return list<WorkflowGraphVertex> */
    public function getVertices(): array
    {
        return array_values($this->vertices);
    }

    /** @return list<WorkflowGraphEdge> */
    public function getEdges(): array
    {
        return array_values($this->edges);
    }

    public function getVertex(string $id): WorkflowGraphVertex
    {
        return $this->vertices[$id] ?? throw new InvalidArgumentException("Unknown workflow graph vertex: {$id}");
    }

    /** @return list<WorkflowGraphEdge> */
    public function getOutgoingEdges(string $vertexId): array
    {
        $edges = [];

        foreach ($this->edges as $edge) {
            if ($edge->from === $vertexId) {
                $edges[] = $edge;
            }
        }

        return $edges;
    }
}
