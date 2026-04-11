<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Agent\Agent;
use NeuronAI\Observability\Events\BranchEnd;
use NeuronAI\Observability\Events\BranchStart;
use NeuronAI\Observability\Events\MiddlewareEnd;
use NeuronAI\Observability\Events\MiddlewareStart;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Workflow\NodeInterface;
use Exception;
use NeuronAI\Workflow\Workflow;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function str_contains;

trait HandleWorkflowEvents
{
    /**
     * @throws Exception
     */
    public function workflowStart(Workflow $workflow, string $event, WorkflowStart $data, ?string $branchId = null): void
    {
        if (!$this->inspector->isRecording()) {
            return;
        }

        if ($this->inspector->needTransaction()) {
            $this->inspector->startTransaction($workflow::class)
                ->setResult('success') // success by default, it can be changed during execution
                ->addContext('Mapping', array_map(fn (string $eventClass, NodeInterface $node): array => [
                    $eventClass => $node::class,
                ], array_keys($data->eventNodeMap), array_values($data->eventNodeMap)));
        } elseif ($this->inspector->canAddSegments()) {
            $this->segments[$workflow::class] = $this->inspector->startSegment(self::SEGMENT_TYPE.'.workflow', $this->getBaseClassName($workflow::class))
                ->setColor(self::STANDARD_COLOR);
        }

        $this->inspector->transaction()->setType('agent');
    }

    /**
     * @throws Exception
     */
    public function workflowEnd(Workflow $workflow, string $event, WorkflowEnd $data, ?string $branchId = null): void
    {
        if (array_key_exists($workflow::class, $this->segments)) {
            $this->segments[$workflow::class]
                ->end()
                ->addContext('State', $data->state->all());
        } elseif ($this->inspector->canAddSegments()) {
            $transaction = $this->inspector->transaction();
            $transaction->addContext('State', $data->state->all());

            if ($workflow instanceof Agent) {
                foreach ($this->getAgentContext($workflow) as $key => $value) {
                    $transaction->addContext($key, $value);
                }
            }

            if ($this->autoFlush) {
                $this->inspector->flush();
            }
        }
    }

    /**
     * @throws InspectorException
     */
    public function branchStart(object $workflow, string $event, BranchStart $data, ?string $branchId = null): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        // Fork at the moment the branch starts, while the triggering node's segment
        // is still open in the parent scope — this gives branches correct nesting.
        $this->branchScopes[$data->branchId] = $this->inspector->fork();
    }

    public function branchEnd(object $workflow, string $event, BranchEnd $data, ?string $branchId = null): void
    {
        unset($this->branchScopes[$data->branchId]);
    }

    /**
     * @throws InspectorException
     */
    public function nodeStart(object $workflow, string $event, WorkflowNodeStart $data, ?string $branchId = null): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $segment = $this->resolveScope($branchId)->startSegment(
            self::SEGMENT_TYPE.'.node',
            $this->getBaseClassName($data->node)
        )
            ->setColor(self::STANDARD_COLOR);

        $segment->addContext('State Before', $data->state->except('__steps'));

        $key = $branchId !== null ? "{$branchId}::{$data->node}" : $data->node;
        $this->segments[$key] = $segment;
    }

    public function nodeEnd(object $workflow, string $event, WorkflowNodeEnd $data, ?string $branchId = null): void
    {
        $key = $branchId !== null ? "{$branchId}::{$data->node}" : $data->node;

        if (array_key_exists($key, $this->segments)) {
            $segment = $this->segments[$key]->end();
            $segment->addContext('State After', $data->state->except('__steps'));
            unset($this->segments[$key]);
        }
    }

    public function middlewareStart(object $workflow, string $event, MiddlewareStart $data, ?string $branchId = null): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $class = $data->middleware::class;
        $action = str_contains($event, 'before') ? 'before' : 'after';
        $key = $branchId !== null ? "{$branchId}::{$class}" : $class;

        $segment = $this->resolveScope($branchId)
            ->startSegment(self::SEGMENT_TYPE.'.middleware', $this->getBaseClassName($class)."::{$action}()")
            ->setColor(self::STANDARD_COLOR);
        $segment->addContext('Event', $data->event);
        $this->segments[$key] = $segment;
    }

    public function middlewareEnd(object $workflow, string $event, MiddlewareEnd $data, ?string $branchId = null): void
    {
        $class = $data->middleware::class;
        $key = $branchId !== null ? "{$branchId}::{$class}" : $class;

        if (array_key_exists($key, $this->segments)) {
            $this->segments[$key]->end();
        }
    }
}
