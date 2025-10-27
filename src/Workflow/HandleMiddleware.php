<?php

declare(strict_types=1);

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
     * Register middleware for a specific node class or multiple node classes.
     *
     * @param class-string<NodeInterface>|array<class-string<NodeInterface>> $nodeClass Node class name or array of node class names
     * @param WorkflowMiddleware|WorkflowMiddleware[] $middleware Middleware instance(s)
     * @throws WorkflowException
     */
    public function middleware(string|array $nodeClass, WorkflowMiddleware|array $middleware): self
    {
        $nodeClasses = \is_array($nodeClass) ? $nodeClass : [$nodeClass];
        $middlewareList = \is_array($middleware) ? $middleware : [$middleware];

        foreach ($nodeClasses as $class) {
            if (!isset($this->nodeMiddleware[$class])) {
                $this->nodeMiddleware[$class] = [];
            }

            foreach ($middlewareList as $m) {
                if (! $m instanceof WorkflowMiddleware) {
                    throw new WorkflowException('Middleware must be an instance of WorkflowMiddleware');
                }

                // If it is observable, we need to propagate the callbacks to the middleware
                $this->propagateObservers($m);

                $this->nodeMiddleware[$class][] = $m;
            }
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
        $middlewares = $this->nodeMiddleware[$nodeClass] ?? [];

        // Combine global and node-specific middleware
        return \array_merge($this->globalMiddleware, $middlewares);
    }
}
