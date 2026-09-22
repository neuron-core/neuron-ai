<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

use function array_merge;
use function is_array;

trait HandleTools
{
    /**
     * @var ToolInterface[]|ToolkitInterface[]|ProviderToolInterface[]
     */
    protected array $tools = [];

    protected bool $toolsOverridden = false;

    /**
     * Global max runs for all tools.
     */
    protected int $toolMaxRuns = 10;

    /**
     * @var callable|null fn(Throwable $e, ToolCall $call): string|ToolOutput|null
     */
    protected $toolErrorHandler;

    /**
     * Handle exceptions that escape tool execution: a returned string or
     * ToolOutput becomes the tool result visible to the LLM and the loop
     * continues; returning null declines — the exception propagates.
     *
     * @param callable|null $handler fn(Throwable $e, ToolCall $call): string|ToolOutput|null
     */
    public function toolErrorHandler(?callable $handler): Agent
    {
        $this->toolErrorHandler = $handler;
        return $this;
    }

    /**
     * Override to provide a default error handler in your agent.
     *
     * @return callable|null fn(Throwable $e, ToolCall $call): string|ToolOutput|null
     */
    protected function resolveToolErrorHandler(): ?callable
    {
        return $this->toolErrorHandler;
    }

    public function toolMaxRuns(int $num): Agent
    {
        $this->toolMaxRuns = $num;
        return $this;
    }

    /**
     * Override to provide tools to the agent.
     *
     * @return array<ToolInterface|ToolkitInterface|ProviderToolInterface>
     */
    protected function tools(ExecutionContext $context): array
    {
        return [];
    }

    /**
     * @return array<ToolInterface|ToolkitInterface|ProviderToolInterface>
     */
    public function getTools(ExecutionContext $context): array
    {
        return $this->toolsOverridden ? $this->tools : array_merge($this->tools, $this->tools($context));
    }

    /**
     * Replace all tools, including the defaults declared by tools().
     * Changes apply to the next execution segment.
     *
     * @param array<ToolInterface|ToolkitInterface|ProviderToolInterface> $tools
     * @throws AgentException
     */
    public function setTools(array $tools): AgentInterface
    {
        $this->validateTools($tools);
        $this->tools = $tools;
        $this->toolsOverridden = true;

        return $this;
    }

    /**
     * @param  ToolInterface|ToolkitInterface|ProviderToolInterface|array<ToolInterface|ToolkitInterface|ProviderToolInterface>  $tools
     * @throws AgentException
     */
    public function addTool(ToolInterface|ToolkitInterface|ProviderToolInterface|array $tools): AgentInterface
    {
        $tools = is_array($tools) ? $tools : [$tools];

        $this->validateTools($tools);
        foreach ($tools as $tool) {
            $this->tools[] = $tool;
        }

        return $this;
    }

    /**
     * @param array<ToolInterface|ToolkitInterface|ProviderToolInterface> $tools
     * @throws AgentException
     */
    protected function validateTools(array $tools): void
    {
        foreach ($tools as $tool) {
            if (!$tool instanceof ToolInterface && !$tool instanceof ToolkitInterface && !$tool instanceof ProviderToolInterface) {
                throw new AgentException('Tools must be an instance of ToolInterface, ToolkitInterface, or ProviderToolInterface');
            }
        }
    }
}
