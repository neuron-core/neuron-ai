<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Models\Segment;
use NeuronAI\AgentInterface;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Observability\Events\ToolsBootstrapped;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;

trait HandleToolEvents
{
    protected Segment $toolBootstrap;

    /**
     * @var array<Segment>
     */
    protected array $toolCalls;

    public function toolsBootstrapping(AgentInterface $agent, string $event, mixed $data): void
    {
        if (!$this->inspector->canAddSegments() || $agent->getTools() === []) {
            return;
        }

        $this->toolBootstrap = $this->inspector
            ->startSegment(
                self::SEGMENT_TYPE.'.tool',
                "tools_bootstrap()"
            )
            ->setColor(self::STANDARD_COLOR);
    }

    public function toolsBootstrapped(AgentInterface $agent, string $event, ToolsBootstrapped $data): void
    {
        if (isset($this->toolBootstrap)) {
            $this->toolBootstrap->end();
            $this->toolBootstrap->addContext('Tools', \array_reduce($data->tools, function (array $carry, ToolInterface|ProviderToolInterface $tool): array {
                if ($tool instanceof ProviderToolInterface) {
                    $carry[$tool->getType()] = $tool->getOptions();
                } else {
                    $carry[$tool->getName()] = $tool->getDescription();
                }
                return $carry;
            }, []));
            $this->toolBootstrap->addContext('Guidelines', $data->guidelines);
        }
    }

    public function toolCalling(AgentInterface $agent, string $event, ToolCalling $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $this->toolCalls[$data->tool::class] = $this->inspector
            ->startSegment(
                self::SEGMENT_TYPE.'.tool',
                "tool_call( {$data->tool->getName()} )"
            )
            ->setColor(self::STANDARD_COLOR);
    }

    public function toolCalled(AgentInterface $agent, string $event, ToolCalled $data): void
    {
        if (!\array_key_exists($data->tool::class, $this->toolCalls)) {
            return;
        }

        $this->toolCalls[$data->tool::class]->end()
            ->addContext('Properties', $data->tool->getProperties())
            ->addContext('Inputs', $data->tool->getInputs())
            ->addContext('Output', $data->tool->getResult());
    }
}
