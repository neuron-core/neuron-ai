<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolPropertyInterface;
use stdClass;

use function array_filter;
use function array_map;
use function array_reduce;

class ToolMapper implements ToolMapperInterface
{
    public function map(array $tools): array
    {
        $providerTools = array_filter($tools, fn (ProviderToolInterface|ToolInterface $tool): bool => $tool instanceof ProviderToolInterface);
        $functionTools = array_filter($tools, fn (ProviderToolInterface|ToolInterface $tool): bool => $tool instanceof ToolInterface);

        $mapping = [];

        // Gemini does not support functions and provider tool at the same time
        if ($functionTools !== []) {
            $mapping['functionDeclarations'] = array_map($this->mapTool(...), $functionTools);
        } else {
            foreach ($providerTools as $tool) {
                $mapping[] = $this->mapProviderTool($tool);
            }
        }

        return $mapping;
    }

    protected function mapTool(ToolInterface $tool): array
    {
        $payload = [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
            'parameters' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'required' => [],
            ],
        ];

        $properties = array_reduce($tool->getProperties(), function (array $carry, ToolPropertyInterface $property): array {
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
            $tool->getType() => new stdClass(),
        ];

        if ($tool->getOptions() !== []) {
            $payload[$tool->getType()] = $tool->getOptions();
        }

        return $payload;
    }
}
