<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ToolPayloadMapperInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolPropertyInterface;

class ToolPayloadMapper implements ToolPayloadMapperInterface
{
    protected array $mapping = [];

    /**
     * @throws ProviderException
     */
    public function map(array $tools): array
    {
        $this->mapping = [];

        foreach ($tools as $tool) {
            $this->mapping[] = match (true) {
                $tool instanceof ToolInterface => $this->mapTool($tool),
                $tool instanceof ProviderToolInterface => $this->mapProviderTool($tool),
                default => throw new ProviderException('Could not map tool type '.$tool::class),
            };
        }

        return $this->mapping;
    }

    protected function mapTool(ToolInterface $tool): array
    {
        $payload = [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
            'parameters' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ];

        $properties = \array_reduce($tool->getProperties(), function (array $carry, ToolPropertyInterface $property) {
            $carry[$property->getName()] = $property->getJsonSchema();
            return $carry;
        }, []);

        if (!empty($properties)) {
            $payload['parameters'] = [
                'type' => 'object',
                'properties' => $properties,
                'required' => $tool->getRequiredProperties(),
            ];
        }

        return $payload;
    }

    protected function mapProviderTool(ProviderToolInterface $tool): array
    {
        $payload = [
            $tool->getType() => new \stdClass(),
        ];

        if ($tool->getOptions() !== []) {
            $payload[$tool->getType()] = $tool->getOptions();
        }

        return $payload;
    }
}
