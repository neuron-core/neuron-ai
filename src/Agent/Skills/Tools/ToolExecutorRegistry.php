<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills\Tools;

use NeuronAI\Exceptions\AgentException;

class ToolExecutorRegistry
{
    /** @var ToolExecutorInterface[] */
    private array $executors = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function register(ToolExecutorInterface $executor): self
    {
        $this->executors[] = $executor;
        return $this;
    }

    public function getExecutor(string $type): ToolExecutorInterface
    {
        foreach ($this->executors as $executor) {
            if ($executor->supports($type)) {
                return $executor;
            }
        }

        throw new AgentException("No executor registered for tool type '{$type}'.");
    }

    public function hasExecutor(string $type): bool
    {
        foreach ($this->executors as $executor) {
            if ($executor->supports($type)) {
                return true;
            }
        }

        return false;
    }

    private function registerDefaults(): void
    {
        $this->register(new ToolExecutor\ShellToolExecutor());
        $this->register(new ToolExecutor\HttpToolExecutor());
        $this->register(new ToolExecutor\PhpToolExecutor());
        $this->register(new ToolExecutor\QueueToolExecutor());
        $this->register(new ToolExecutor\McpToolExecutor());
    }
}
