<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Models\Segment;
use NeuronAI\Agent\Agent;
use NeuronAI\RAG\RAG;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Tools\ToolPropertyInterface;
use Exception;

use function array_key_exists;
use function array_map;
use function array_reverse;
use function uniqid;

/**
 * Handle Agent and RAG events.
 */
trait HandleAgentEvents
{
    /**
     * @var array<string, Segment>
     */
    protected array $agentSegments = [];

    /**
     * @throws Exception
     */
    public function start(Agent|RAG $source, string $event, mixed $data = null): void
    {
        if (!$this->inspector->isRecording()) {
            return;
        }

        $method = $this->getEventPrefix($event);
        $class = $source::class;

        if ($this->inspector->needTransaction()) {
            $this->inspector->startTransaction($class.'::'.$method)
                ->setType('ai-agent')
                ->setContext($this->getAgentContext($source));
        } elseif ($this->inspector->canAddSegments()) {
            $key = $class.$method;

            if (array_key_exists($key, $this->segments)) {
                $key .= '-'.uniqid();
            }

            $segment = $this->inspector->startSegment(self::SEGMENT_TYPE.'.'.$method, "{$class}::{$method}")
                ->setColor(self::STANDARD_COLOR);
            $segment->setContext($this->getAgentContext($source));
            $this->agentSegments[$key] = $segment;
        }
    }

    /**
     * @throws Exception
     */
    public function stop(Agent|RAG $agent, string $event, mixed $data = null): void
    {
        $method = $this->getEventPrefix($event);
        $class = $agent::class;

        $key = $class.$method;

        if (array_key_exists($key, $this->agentSegments)) {
            foreach (array_reverse($this->agentSegments) as $key => $segment) {
                if ($key === $class.$method) {
                    $segment->setContext($this->getAgentContext($agent));
                    $segment->end();
                    unset($this->agentSegments[$key]);
                    break;
                }
            }
        } elseif ($this->inspector->canAddSegments()) {
            $transaction = $this->inspector->transaction()->setResult('success');
            $transaction->setContext($this->getAgentContext($agent));

            if ($this->autoFlush) {
                $this->inspector->flush();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAgentContext(Agent $agent): array
    {
        $mapTool = fn (ToolInterface $tool): array => [
            $tool->getName() => [
                'description' => $tool->getDescription(),
                'properties' => array_map(
                    fn (ToolPropertyInterface $property) => $property->jsonSerialize(),
                    $tool->getProperties()
                )
            ]
        ];

        return [
            'Agent' => [
                'provider' => $agent->resolveProvider()::class,
                'instructions' => $agent->resolveInstructions(),
            ],
            'Tools' => array_map(fn (ToolInterface|ToolkitInterface|ProviderToolInterface $tool) => match (true) {
                $tool instanceof ToolInterface => $mapTool($tool),
                $tool instanceof ToolkitInterface => [$tool::class => array_map($mapTool, $tool->tools())],
                default => $tool->jsonSerialize(),
            }, $agent->getTools()),
        ];
    }
}
