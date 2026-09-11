<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;

use function array_merge;

class ToolMapper implements ToolMapperInterface
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
                    'json' => $tool->getInputSchema(),
                ],
            ],
        ];

        if ($tool->getParameters() !== []) {
            $payload['toolSpec'] = array_merge($payload['toolSpec'], $tool->getParameters());
        }

        return $payload;
    }
}
