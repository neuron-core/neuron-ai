<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ToolPayloadMapperInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolPropertyInterface;

class ToolPayloadMapper implements ToolPayloadMapperInterface
{
    /**
     * @throws ProviderException
     */
    public function map(array $tools): array
    {
        $mapping = [];

        foreach ($tools as $tool) {
            $mapping[] = match (true) {
                $tool instanceof ToolInterface => $this->mapTool($tool),
                $tool instanceof ProviderToolInterface => $this->mapProviderTool($tool),
                default => throw new ProviderException('Could not map tool type '.$tool::class),
            };
        }

        return $mapping;
    }

    protected function mapTool(ToolInterface $tool): array
    {
        $properties = \array_reduce($tool->getProperties(), function (array $carry, ToolPropertyInterface $property): array {
            $carry[$property->getName()] = $property->getJsonSchema();
            return $carry;
        }, []);

        return [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
            'input_schema' => [
                'type' => 'object',
                'properties' => empty($properties) ? null : $properties,
                'required' => $tool->getRequiredProperties(),
            ],
        ];
    }

    protected function mapProviderTool(ProviderToolInterface $tool): array
    {
        $payload = [
            'type' => $tool->getType(),
            ...$tool->getOptions()
        ];

        if (\is_string($tool->getName())) {
            $payload['name'] = $tool->getName();
        }

        return $payload;
    }
}
