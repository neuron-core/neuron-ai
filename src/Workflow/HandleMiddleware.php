<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use Closure;

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
     * @return WorkflowMiddleware[]
     */
    protected function globalMiddleware(): array
    {
        return [];
    }

    /**
     * @return array<class-string<NodeInterface>, WorkflowMiddleware|WorkflowMiddleware[]>
     */
    protected function middleware(): array
    {
        return [];
    }

    /**
     * @param WorkflowMiddleware|WorkflowMiddleware[] $middleware
     * @throws WorkflowException
     */
    public function addGlobalMiddleware(WorkflowMiddleware|Closure|array $middleware): static
    {
        $middlewareArray = is_array($middleware) ? $middleware : [$middleware];

        foreach ($middlewareArray as $m) {
            if (!$m instanceof WorkflowMiddleware && !$m instanceof Closure) {
                throw new WorkflowException('Middleware must be an instance of WorkflowMiddleware');
            }

            $this->globalMiddleware[] = $m;
        }

        return $this;
    }

    /**
     * @param class-string<NodeInterface>|array<class-string<NodeInterface>> $node
     * @param WorkflowMiddleware|WorkflowMiddleware[] $middleware
     * @throws WorkflowException
     */
    public function addMiddleware(string|array $node, WorkflowMiddleware|Closure|array $middleware): static
    {
        $nodeClasses = is_array($node) ? $node : [$node];
        $middlewareList = is_array($middleware) ? $middleware : [$middleware];

        foreach ($nodeClasses as $class) {
            $this->nodeMiddleware[$class] ??= [];

            foreach ($middlewareList as $m) {
                if (!$m instanceof WorkflowMiddleware && !$m instanceof Closure) {
                    throw new WorkflowException('Middleware must be an instance of WorkflowMiddleware');
                }

                $this->nodeMiddleware[$class][] = $m;
            }
        }

        return $this;
    }

}
