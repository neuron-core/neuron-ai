<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Workflow\Edge;

trait HandleWorkflowEvents
{
    public function workflowStart(\SplSubject $workflow, string $event, WorkflowStart $data): void
    {
        if (!$this->inspector->isRecording()) {
            return;
        }

        if ($this->inspector->needTransaction()) {
            $this->inspector->startTransaction($workflow::class)
                ->setType('neuron-workflow')
                ->addContext('List', [
                    'nodes' => \array_keys($data->nodes),
                    'edges' => \array_map(fn (Edge $edge): array => [
                        'from' => $edge->getFrom(),
                        'to' => $edge->getTo(),
                        'has_condition' => $edge->hasCondition(),
                    ], $data->edges)
                ]);
        } elseif ($this->inspector->canAddSegments()) {
            $this->segments[$workflow::class] = $this->inspector->startSegment(self::SEGMENT_TYPE.'.workflow', $workflow::class)
                ->setColor(self::SEGMENT_COLOR);
        }
    }

    public function workflowEnd(\SplSubject $workflow, string $event, WorkflowEnd $data): void
    {
        if (\array_key_exists($workflow::class, $this->segments)) {
            $this->segments[$workflow::class]
                ->end()
                ->addContext('State', $data->state->all());
        } elseif ($this->inspector->canAddSegments()) {
            $transaction = $this->inspector->transaction();
            $transaction->addContext('State', $data->state->all());
            $transaction->setResult('success');
        }
    }

    public function workflowNodeStart(\SplSubject $workflow, string $event, WorkflowNodeStart $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $segment = $this->inspector
            ->startSegment(self::SEGMENT_TYPE.'.workflow', $data->node)
            ->setColor(self::SEGMENT_COLOR);
        $segment->addContext('Before', $data->state->all());
        $this->segments[$data->node] = $segment;
    }

    public function workflowNodeEnd(\SplSubject $workflow, string $event, WorkflowNodeEnd $data): void
    {
        if (\array_key_exists($data->node, $this->segments)) {
            $segment = $this->segments[$data->node]->end();
            $segment->addContext('After', $data->state->all());
        }
    }
}
