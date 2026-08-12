<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;

use function array_merge;
use function is_array;

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
     * Define the global middleware.
     *
     * @return WorkflowMiddleware[]
     */
    protected function globalMiddleware(): array
    {
        return [];
    }

    /**
     * Define node middleware here.
     *
     * @return array<class-string<NodeInterface>, WorkflowMiddleware|WorkflowMiddleware[]>
     */
    protected function middleware(): array
    {
        return [];
    }

    /**
     * Register global middleware that runs on all nodes.
     *
     * @param WorkflowMiddleware|WorkflowMiddleware[] $middleware Middleware instance(s)
     * @throws WorkflowException
     */
    public function addGlobalMiddleware(WorkflowMiddleware|array $middleware): static
    {
        $middlewareArray = is_array($middleware) ? $middleware : [$middleware];

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
     * @param class-string<NodeInterface>|array<class-string<NodeInterface>> $node Node class name or array of node class names
     * @param WorkflowMiddleware|WorkflowMiddleware[] $middleware Middleware instance(s)
     * @throws WorkflowException
     */
    public function addMiddleware(string|array $node, WorkflowMiddleware|array $middleware): static
    {
        $nodeClasses = is_array($node) ? $node : [$node];
        $middlewareList = is_array($middleware) ? $middleware : [$middleware];

        foreach ($nodeClasses as $class) {
            if (!isset($this->nodeMiddleware[$class])) {
                $this->nodeMiddleware[$class] = [];
            }

            foreach ($middlewareList as $m) {
                if (! $m instanceof WorkflowMiddleware) {
                    throw new WorkflowException('Middleware must be an instance of WorkflowMiddleware');
                }

                $this->nodeMiddleware[$class][] = $m;
            }
        }

        return $this;
    }

    /**
     * Get all registered middleware for the given node.
     *
     * Matching is subclass-aware: middleware registered for a class also
     * applies to any of its subclasses (via instanceof). This lets callers
     * target a shared base class (e.g. an InferenceNode) so a middleware fires
     * for every node type that extends it, rather than being silently dropped
     * when a sibling class is instantiated.
     *
     * @return WorkflowMiddleware[]
     */
    public function getMiddlewareForNode(NodeInterface $node): array
    {
        // Global middleware runs first, then node-specific middleware in
        // registration order, for every registered class the node matches.
        $middlewares = $this->globalMiddleware;

        foreach ($this->nodeMiddleware as $class => $list) {
            if ($node instanceof $class) {
                $middlewares = array_merge($middlewares, $list);
            }
        }

        return $middlewares;
    }
}
