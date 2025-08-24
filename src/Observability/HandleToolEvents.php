<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use NeuronAI\AgentInterface;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Observability\Events\ToolsBootstrapped;
use NeuronAI\Tools\ToolInterface;

trait HandleToolEvents
{
    public function toolsBootstrapping(AgentInterface $agent, string $event, mixed $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $this->segments[$agent::class.'_tools_bootstrap'] = $this->inspector
            ->startSegment(
                self::SEGMENT_TYPE.'.tool',
                "tools_bootstrap()"
            )
            ->setColor(self::SEGMENT_COLOR);
    }

    public function toolsBootstrapped(AgentInterface $agent, string $event, ToolsBootstrapped $data): void
    {
        if (\array_key_exists($agent::class.'_tools_bootstrap', $this->segments) && $data->tools !== []) {
            $segment = $this->segments[$agent::class.'_tools_bootstrap']->end();
            $segment->addContext('Tools', \array_reduce($data->tools, function (array $carry, ToolInterface $tool): array {
                $carry[$tool->getName()] = $tool->getDescription();
                return $carry;
            }, []));
            $segment->addContext('Guidelines', $data->guidelines);
        }
    }

    public function toolCalling(AgentInterface $agent, string $event, ToolCalling $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $this->segments[$data->tool->getName()] = $this->inspector
            ->startSegment(
                self::SEGMENT_TYPE.'.tool',
                "tool_call( {$data->tool->getName()} )"
            )
            ->setColor(self::SEGMENT_COLOR);
    }

    public function toolCalled(AgentInterface $agent, string $event, ToolCalled $data): void
    {
        if (\array_key_exists($data->tool->getName(), $this->segments)) {
            $this->segments[$data->tool->getName()]
                ->end()
                ->addContext('Properties', $data->tool->getProperties())
                ->addContext('Inputs', $data->tool->getInputs())
                ->addContext('Output', $data->tool->getResult());
        }
    }
}
