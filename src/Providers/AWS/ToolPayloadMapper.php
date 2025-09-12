<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ToolPayloadMapperInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolPropertyInterface;

class ToolPayloadMapper implements ToolPayloadMapperInterface
{
    public function map(array $tools): array
    {
        $mapping = [];

        foreach ($tools as $tool) {
            $mapping[] = match (true) {
                $tool instanceof ToolInterface => $this->mapTool($tool),
                $tool instanceof ProviderToolInterface => throw new ProviderException('Bedrock Runtime does not support Provider Tools'),
                default => throw new ProviderException('Could not map tool type '.$tool::class),
            };
        }

        return $mapping;
    }

    protected function mapTool(ToolInterface $tool): array
    {
        $payload = [
            'toolSpec' => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => [
                    'json' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'required' => [],
                    ]
                ],
            ],
        ];

        $properties = \array_reduce($tool->getProperties(), function (array $carry, ToolPropertyInterface $property): array {
            $carry[$property->getName()] = $property->getJsonSchema();
            return $carry;
        }, []);

        if (!empty($properties)) {
            $payload['toolSpec']['inputSchema']['json'] = [
                'type' => 'object',
                'properties' => $properties,
                'required' => $tool->getRequiredProperties(),
            ];
        }

        return $payload;
    }
}
