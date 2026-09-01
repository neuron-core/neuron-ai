<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

use Generator;
use InvalidArgumentException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\NodeInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;

use function is_a;
use function sha1;

final class WorkflowGraphBuilder
{
    protected WorkflowGraph $graph;

    /** @var array<class-string<Event>, NodeInterface> */
    protected array $eventNodeMap = [];

    /** @var array<string, true> */
    protected array $visited = [];

    /** @var array<class-string<Event>, true> */
    protected array $discoveredEvents = [];

    /** @var array<class-string<Event>, list<ExporterTransition>> */
    protected array $transitions = [];

    /**
     * @param class-string<Event> $startEvent
     * @param array<class-string<Event>, NodeInterface> $eventNodeMap
     */
    public function build(string $startEvent, array $eventNodeMap): WorkflowGraph
    {
        $this->eventNodeMap = $eventNodeMap;
        $this->visited = [];
        $this->discoveredEvents = [];
        $this->transitions = [];
        $this->graph = new WorkflowGraph($this->eventId($startEvent));

        foreach ($eventNodeMap as $event => $node) {
            $this->addEvent($event);
            $this->addNode($node);
            $this->graph->addEdge(new WorkflowGraphEdge($this->eventId($event), $this->nodeId($node)));
        }

        $this->walk($startEvent);

        foreach ($eventNodeMap as $event => $node) {
            if (!isset($this->discoveredEvents[$event])) {
                $this->walk($event);
            }
        }

        return $this->graph;
    }

    /**
     * @param class-string<Event> $event
     */
    protected function walk(string $event, ?string $completionVertexId = null): void
    {
        $visitKey = $event . "\0" . $completionVertexId;
        if (isset($this->visited[$visitKey])) {
            return;
        }

        $this->visited[$visitKey] = true;
        $this->discoveredEvents[$event] = true;
        $node = $this->eventNodeMap[$event] ?? null;

        if (!$node instanceof NodeInterface) {
            return;
        }

        foreach ($this->transitionsFor($event, $node) as $index => $transition) {
            if ($transition instanceof ParallelTransition) {
                $this->addParallelTransition($node, $transition, $index, $completionVertexId);
                continue;
            }

            if (!$transition instanceof EventTransition) {
                throw new InvalidArgumentException('Exporter transitions must implement ' . ExporterTransition::class);
            }

            $this->addEventTransition($node, $transition->event, $completionVertexId);
        }
    }

    /**
     * @param class-string<Event> $event
     */
    protected function addEventTransition(
        NodeInterface $node,
        string $event,
        ?string $completionVertexId,
    ): void {
        if (!is_a($event, Event::class, true)) {
            throw new InvalidArgumentException($event . ' must implement ' . Event::class);
        }

        if (is_a($event, StopEvent::class, true) && $completionVertexId !== null) {
            $this->graph->addEdge(new WorkflowGraphEdge($this->nodeId($node), $completionVertexId));
            return;
        }

        $this->addEvent($event);
        $this->graph->addEdge(new WorkflowGraphEdge($this->nodeId($node), $this->eventId($event)));

        if (!is_a($event, StopEvent::class, true)) {
            $this->walk($event, $completionVertexId);
        }
    }

    protected function addParallelTransition(
        NodeInterface $node,
        ParallelTransition $transition,
        int $index,
        ?string $completionVertexId,
    ): void {
        if (!is_a($transition->event, ParallelEvent::class, true)) {
            throw new InvalidArgumentException($transition->event . ' must extend ' . ParallelEvent::class);
        }

        $context = $node::class . "\0" . $transition->event . "\0" . $index . "\0" . $completionVertexId;
        $splitId = 'parallel_split_' . sha1($context);
        $joinId = 'parallel_join_' . sha1($context);
        $eventLabel = $this->shortName($transition->event);

        $this->graph->addVertex(new WorkflowGraphVertex(
            $splitId,
            $eventLabel . ' split',
            WorkflowGraphVertexType::ParallelSplit,
        ));
        $this->graph->addVertex(new WorkflowGraphVertex(
            $joinId,
            $eventLabel . ' join',
            WorkflowGraphVertexType::ParallelJoin,
        ));
        $this->graph->addEdge(new WorkflowGraphEdge($this->nodeId($node), $splitId));

        foreach ($transition->branches as $branch => $event) {
            if (!is_a($event, Event::class, true)) {
                throw new InvalidArgumentException($event . ' must implement ' . Event::class);
            }

            $this->addEvent($event);
            $this->graph->addEdge(new WorkflowGraphEdge($splitId, $this->eventId($event), $branch));
            $this->walk($event, $joinId);
        }

        $this->addEvent($transition->event);
        $this->graph->addEdge(new WorkflowGraphEdge($joinId, $this->eventId($transition->event)));
        $this->walk($transition->event, $completionVertexId);
    }

    /**
     * @param class-string<Event> $event
     * @return list<ExporterTransition>
     */
    protected function transitionsFor(string $event, NodeInterface $node): array
    {
        if (isset($this->transitions[$event])) {
            return $this->transitions[$event];
        }

        if ($node instanceof DescibeExporterTransitions) {
            return $this->transitions[$event] = $node->describe();
        }

        $method = (new ReflectionClass($node))->getMethod('__invoke');
        $returnType = $method->getReturnType();
        $types = $returnType instanceof ReflectionUnionType
            ? $returnType->getTypes()
            : [$returnType];
        $transitions = [];

        foreach ($types as $type) {
            if (
                $type instanceof ReflectionNamedType
                && !$type->isBuiltin()
                && !is_a($type->getName(), Generator::class, true)
                && is_a($type->getName(), Event::class, true)
            ) {
                $transitions[] = new EventTransition($type->getName());
            }
        }

        return $this->transitions[$event] = $transitions;
    }

    /** @param class-string<Event> $event */
    protected function addEvent(string $event): void
    {
        $this->graph->addVertex(new WorkflowGraphVertex(
            $this->eventId($event),
            $this->shortName($event),
            WorkflowGraphVertexType::Event,
            $event,
        ));
    }

    protected function addNode(NodeInterface $node): void
    {
        $this->graph->addVertex(new WorkflowGraphVertex(
            $this->nodeId($node),
            $this->shortName($node::class),
            WorkflowGraphVertexType::Node,
            $node::class,
        ));
    }

    /** @param class-string<Event> $event */
    protected function eventId(string $event): string
    {
        return 'event_' . sha1($event);
    }

    protected function nodeId(NodeInterface $node): string
    {
        return 'node_' . sha1($node::class);
    }

    protected function shortName(string $class): string
    {
        return (new ReflectionClass($class))->getShortName();
    }
}
