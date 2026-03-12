<?php

declare(strict_types=1);

namespace NeuronAI\Providers\ZAI;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\ToolMapper as OpenAIToolMapper;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;

class ToolMapper extends OpenAIToolMapper
{
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

    protected function mapProviderTool(ProviderToolInterface $tool): array
    {
        return [
            'type' => $tool->getType(),
            $tool->getType() => $tool->getOptions(),
        ];
    }
}
