<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use JsonException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Tools\DeferredToolInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;

use function json_decode;
use function is_array;
use function array_is_list;
use function array_filter;

use const JSON_THROW_ON_ERROR;

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
     * Build the ToolCall record for a tool invocation requested by the model:
     * validates the name against the registered tools (unknown names
     * throw, exactly like findTool()) and copies the live tool's description and
     * deferred execution flag onto the call. The call is pure conversation data — execution
     * resolves against the live registry later, in ToolNode.
     *
     * @param array<string, mixed> $inputs
     * @throws ProviderException
     */
    public function newToolCall(string $name, ?string $callId, array $inputs): ToolCall
    {
        $tool = $this->findTool($name);

        return new ToolCall($name, $callId, $inputs, $tool->getDescription(), $tool instanceof DeferredToolInterface);
    }

    /**
     * The model's arguments for a tool call. Anything but a JSON object would
     * run the tool with empty or wrong inputs, so it is refused.
     *
     * @param array<string, mixed>|string|null $arguments Already decoded by some vendors.
     * @return array<string, mixed>
     * @throws ProviderException
     */
    protected function decodeToolArguments(string $toolName, array|string|null $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if ($arguments === null || $arguments === '') {
            return [];
        }

        try {
            $decoded = json_decode($arguments, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProviderException("The model sent invalid arguments for tool \"{$toolName}\": {$exception->getMessage()}", $exception->getCode(), previous: $exception);
        }

        // A JSON object decodes to a map (or to [] when empty), never to a scalar or a list
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ProviderException("The model sent invalid arguments for tool \"{$toolName}\": expected a JSON object.");
        }

        return $decoded;
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
