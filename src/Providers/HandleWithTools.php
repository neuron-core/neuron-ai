<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;

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

    public function findTool(string $name): ToolInterface
    {
        // Remove provider tools
        $tools = \array_filter($this->tools, fn (ToolInterface|ProviderToolInterface $tool): bool => $tool instanceof ToolInterface);

        foreach ($tools as $tool) {
            if ($tool->getName() === $name) {
                // We return a copy to allow multiple call to the same tool without rewriting the previous tool call result.
                return clone $tool;
            }
        }

        throw new ProviderException(
            "It seems the model is asking for a non-existing tool: {$name}. You could try writing more verbose tool descriptions and prompts to help the model in the task."
        );
    }
}
