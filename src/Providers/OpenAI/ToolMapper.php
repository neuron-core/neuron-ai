<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolPropertyInterface;
use stdClass;

use function array_reduce;
use function array_merge;

class ToolMapper implements ToolMapperInterface
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
                $tool instanceof ProviderToolInterface => throw new ProviderException('OpenAI completions API does not support built-in Tools'),
                default => throw new ProviderException('Could not map tool type '.$tool::class),
            };
        }

        return $mapping;
    }

    protected function mapTool(ToolInterface $tool): array
    {
        $payload = [
            'type' => 'function',
            'function' => [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => new stdClass(),
                    'required' => [],
                ],
            ]
        ];

        $properties = array_reduce($tool->getProperties(), function (array $carry, ToolPropertyInterface $property): array {
            $carry[$property->getName()] = $property->getJsonSchema();
            return $carry;
        }, []);

        if (!empty($properties)) {
            $payload['function']['parameters'] = [
                'type' => 'object',
                'properties' => $properties,
                'required' => $tool->getRequiredProperties(),
            ];
        }

        if ($tool->getParameters() !== []) {
            $payload['function'] = array_merge($payload['function'], $tool->getParameters());
        }

        return $payload;
    }
}
