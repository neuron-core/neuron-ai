<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;

use function array_filter;

trait HandleWithTools
{
    /**
     * It can contain Neuron Tool instances or tool providers definitions
     *
     * @var array<ToolInterface|ProviderToolInterface>
     */
    protected array $tools = [];

    public function setTools(array $tools): AIProviderInterface
    {
        $this->tools = $tools;
        return $this;
    }

    /**
     * Build the ToolCall record for a tool invocation requested by the model
     * (ADR 0010): validates the name against the registered tools (unknown names
     * throw, exactly like findTool()) and copies the live tool's description onto
     * the call for rendering. The call is pure conversation data — execution
     * resolves against the live registry later, in ToolNode.
     *
     * @param array<string, mixed> $inputs
     * @throws ProviderException
     */
    public function newToolCall(string $name, ?string $callId, array $inputs): ToolCall
    {
        $tool = $this->findTool($name);

        return new ToolCall($name, $callId, $inputs, $tool->getDescription());
    }

    /**
     * @throws ProviderException
     */
    public function findTool(string $name): ToolInterface
    {
        // Remove provider tools
        $tools = array_filter($this->tools, fn (ToolInterface|ProviderToolInterface $tool): bool => $tool instanceof ToolInterface);

        foreach ($tools as $tool) {
            if ($tool->getName() === $name) {
                // We return a copy to allow multiple call to the same tool without rewriting the previous tool call result.
                return clone $tool;
            }
        }

        throw new ProviderException(
            "The model is asking for a non-existing tool: {$name}. You could try writing more verbose tool descriptions and prompts to help the model in the task."
        );
    }
}
