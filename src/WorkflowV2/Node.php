<?php

declare(strict_types=1);

namespace NeuronAI\WorkflowV2;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\WorkflowV2\NodeInterface;
use NeuronAI\WorkflowV2\WorkflowContext;

abstract class Node implements NodeInterface
{
    protected WorkflowContext $context;

    public function setContext(WorkflowContext $context): void
    {
        $this->context = $context;
    }

    protected function interrupt(array $data): mixed
    {
        if (!isset($this->context)) {
            throw new WorkflowException('WorkflowContext not set on node');
        }

        return $this->context->interrupt($data);
    }
}
