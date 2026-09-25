<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;

use function array_merge;

/**
 * The nodes one segment runs, routed by the event each one handles, with the
 * middleware that wrap them and the resources they share.
 *
 * @internal
 */
final class Graph
{
    /** @var array<class-string<Event>, NodeInterface> */
    protected array $nodes = [];

    /**
     * @param NodeInterface[] $nodes
     * @param array<class-string<NodeInterface>, WorkflowMiddleware[]> $middleware
     * @param WorkflowMiddleware[] $globalMiddleware
     * @throws WorkflowException when a node is invalid, or none handles the start event.
     */
    public function __construct(
        Event $start,
        public readonly WorkflowResources $resources,
        array $nodes,
        protected array $middleware = [],
        protected array $globalMiddleware = [],
    ) {
        $signature = new NodeSignature();
        foreach ($nodes as $node) {
            $eventClass = $signature->eventClass($node, $resources);
            if (isset($this->nodes[$eventClass])) {
                throw new WorkflowException("Node for event {$eventClass} already exists");
            }
            $this->nodes[$eventClass] = $node;
        }

        if (!isset($this->nodes[$start::class])) {
            throw new WorkflowException('No nodes found that handle ' . $start::class);
        }
    }

    /** @return array<class-string<Event>, NodeInterface> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * @throws WorkflowException
     */
    public function nodeFor(Event $event): NodeInterface
    {
        return $this->nodes[$event::class] ?? throw new WorkflowException(
            'No node found that handle event: ' . $event::class
        );
    }

    /** @return WorkflowMiddleware[] */
    public function middlewareFor(NodeInterface $node): array
    {
        $middleware = $this->globalMiddleware;
        foreach ($this->middleware as $class => $list) {
            if ($node instanceof $class) {
                $middleware = array_merge($middleware, $list);
            }
        }
        return $middleware;
    }
}
