<?php

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;

trait HandleMiddleware
{
    /**
     * Global middleware applied to all nodes.
     *
     * @var WorkflowMiddleware[]
     */
    protected array $globalMiddleware = [];

    /**
     * Node-specific middleware.
     *
     * @var array<class-string<NodeInterface>, WorkflowMiddleware[]>
     */
    protected array $nodeMiddleware = [];

    /**
     * Register global middleware that runs on all nodes.
     *
     * @param WorkflowMiddleware|WorkflowMiddleware[] $middleware Middleware instance(s)
     * @throws WorkflowException
     */
    public function globalMiddleware(WorkflowMiddleware|array $middleware): self
    {
        $middlewareArray = \is_array($middleware) ? $middleware : [$middleware];

        foreach ($middlewareArray as $m) {
            if (! $m instanceof WorkflowMiddleware) {
                throw new WorkflowException('Middleware must be an instance of WorkflowMiddleware');
            }
            $this->globalMiddleware[] = $m;
        }

        return $this;
    }

    /**
     * Register middleware for a specific node class.
     *
     * @param class-string<NodeInterface> $nodeClass Node class name or array of node classes with middleware
     * @param WorkflowMiddleware|WorkflowMiddleware[] $middleware Middleware instance(s) (required when $nodeClass is a string)
     * @throws WorkflowException
     */
    public function middleware(string $nodeClass, WorkflowMiddleware|array $middleware): self
    {
        $middlewareArray = \is_array($middleware) ? $middleware : [$middleware];

        if (!isset($this->nodeMiddleware[$nodeClass])) {
            $this->nodeMiddleware[$nodeClass] = [];
        }

        foreach ($middlewareArray as $m) {
            if (! $m instanceof WorkflowMiddleware) {
                throw new WorkflowException('Middleware must be an instance of WorkflowMiddleware');
            }
            $this->nodeMiddleware[$nodeClass][] = $m;
        }
        return $this;
    }

    /**
     * Get all registered middleware for the given node.
     *
     * @return WorkflowMiddleware[]
     */
    protected function getMiddlewareForNode(NodeInterface $node): array
    {
        $nodeClass = $node::class;
        $nodeSpecific = $this->nodeMiddleware[$nodeClass] ?? [];

        // Combine global and node-specific middleware
        return \array_merge($this->globalMiddleware, $nodeSpecific);
    }
}
