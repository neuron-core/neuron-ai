<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;

abstract class Node implements NodeInterface
{
    protected WorkflowContext $context;

    public function setContext(WorkflowContext $context): void
    {
        $this->context = $context;
    }

    /**
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    protected function interrupt(array $data): mixed
    {
        if (!isset($this->context)) {
            throw new WorkflowException('WorkflowContext not set on node');
        }

        return $this->context->interrupt($data);
    }
}
