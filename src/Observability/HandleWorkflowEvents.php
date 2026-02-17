<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use NeuronAI\Agent\Agent;
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
    public function workflowStart(Workflow $workflow, string $event, WorkflowStart $data): void
    {
        if (!$this->inspector->isRecording()) {
            return;
        }

        if ($this->inspector->needTransaction()) {
            $this->inspector->startTransaction($workflow::class)
                ->setType('agent')
                ->addContext('Mapping', array_map(fn (string $eventClass, NodeInterface $node): array => [
                    $eventClass => $node::class,
                ], array_keys($data->eventNodeMap), array_values($data->eventNodeMap)));
        } elseif ($this->inspector->canAddSegments()) {
            $this->segments[$workflow::class] = $this->inspector->startSegment(self::SEGMENT_TYPE.'.workflow', $this->getBaseClassName($workflow::class))
                ->setColor(self::STANDARD_COLOR);
        }
    }

    /**
     * @throws Exception
     */
    public function workflowEnd(Workflow $workflow, string $event, WorkflowEnd $data): void
    {
        if (array_key_exists($workflow::class, $this->segments)) {
            $this->segments[$workflow::class]
                ->end()
                ->addContext('State', $data->state->all());
        } elseif ($this->inspector->canAddSegments()) {
            $transaction = $this->inspector->transaction()->setResult('success');
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

    public function nodeStart(object $workflow, string $event, WorkflowNodeStart $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $segment = $this->inspector
            ->startSegment(self::SEGMENT_TYPE.'.workflow', $this->getBaseClassName($data->node))
            ->setColor(self::STANDARD_COLOR);
        $segment->addContext('State Before', $data->state->all());
        $this->segments[$data->node] = $segment;
    }

    public function nodeEnd(object $workflow, string $event, WorkflowNodeEnd $data): void
    {
        if (array_key_exists($data->node, $this->segments)) {
            $segment = $this->segments[$data->node]->end();
            $segment->addContext('State After', $data->state->all());
            unset($this->segments[$data->node]);
        }
    }

    public function middlewareStart(object $workflow, string $event, MiddlewareStart $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $class = $data->middleware::class;
        $action = str_contains($event, 'before') ? 'before' : 'after';

        $segment = $this->inspector
            ->startSegment(self::SEGMENT_TYPE.'.middleware', $this->getBaseClassName($class)."::{$action}()")
            ->setColor(self::STANDARD_COLOR);
        $segment->addContext('Event', $data->event);
        $this->segments[$class] = $segment;
    }

    public function middlewareEnd(object $workflow, string $event, MiddlewareEnd $data): void
    {
        $class = $data->middleware::class;

        if (array_key_exists($class, $this->segments)) {
            $this->segments[$class]->end();
        }
    }
}
