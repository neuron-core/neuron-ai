<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Exceptions\InspectorException;
use Inspector\Models\Segment;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Observability\Events\ToolsBootstrapped;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use TypeError;

use function array_key_exists;
use function array_reduce;
use function is_string;

trait HandleToolEvents
{
    /**
     * Open tool bootstrap segments keyed by branch scope key.
     *
     * @var array<string, Segment>
     */
    protected array $toolBootstraps = [];

    /**
     * @var array<string, Segment>
     */
    protected array $toolCalls = [];

    public function toolsBootstrapping(AgentInterface $agent, string $event, mixed $data, ?string $branchId = null): void
    {
        if (!$this->inspector->canAddSegments() || $agent->getTools() === []) {
            return;
        }

        $this->toolBootstraps[$branchId] = $this->resolveScope($branchId)
            ->startSegment(
                self::SEGMENT_TYPE.'.tool',
                "tools_bootstrap()"
            )
            ->setColor(self::STANDARD_COLOR);
    }

    public function toolsBootstrapped(object $source, string $event, ToolsBootstrapped $data, ?string $branchId = null): void
    {
        if (is_string($branchId) && !array_key_exists($branchId, $this->toolBootstraps)) {
            return;
        }

        $this->toolBootstraps[$branchId]->end();
        $this->toolBootstraps[$branchId]->addContext('Tools', array_reduce($data->tools, function (array $carry, ToolInterface|ProviderToolInterface $tool): array {
            if ($tool instanceof ProviderToolInterface) {
                $carry[$tool->getType()] = $tool->getOptions();
            } else {
                $carry[$tool->getName()] = $tool->getDescription();
            }
            return $carry;
        }, []));
        $this->toolBootstraps[$branchId]->addContext('Guidelines', $data->guidelines);
        unset($this->toolBootstraps[$branchId]);
    }

    /**
     * @throws InspectorException
     */
    public function toolCalling(object $source, string $event, ToolCalling $data, ?string $branchId = null): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        // $data->fork is true when tools run in parallel child processes (pcntl_fork).
        // In that case we fork from the current branch scope so the tool segment is
        // correctly nested under the branch, not the main Inspector.
        $scope = $data->fork ? $this->resolveScope($branchId)->fork() : $this->resolveScope($branchId);

        $key = $branchId.'::'.$data->tool::class;

        $this->toolCalls[$key] = $scope->startSegment(
            self::SEGMENT_TYPE.'.tool',
            "tool_call( {$data->tool->getName()} )"
        )
            ->setColor(self::STANDARD_COLOR);
    }

    public function toolCalled(object $source, string $event, ToolCalled $data, ?string $branchId = null): void
    {
        $key = $branchId.'::'.$data->tool::class;

        if (!array_key_exists($key, $this->toolCalls)) {
            return;
        }

        $segment = $this->toolCalls[$key]->end()
            ->addContext('Properties', $data->tool->getProperties())
            ->addContext('Inputs', $data->tool->getInputs());

        try {
            $segment->addContext('Output', $data->tool->getResult());
        } catch (TypeError) {
            // The tool may not have run due to an error, like ToolMaxRuns.
            // In that case getResult will throw an error due to a null result.
        }

        unset($this->toolCalls[$key]);
    }
}
