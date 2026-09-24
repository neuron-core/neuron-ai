<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowResources;

/** Provides a dependency that cannot be serialized, and counts how often it is built. */
class ResourcefulWorkflow extends Workflow
{
    public int $resourceBuilds = 0;

    protected function resources(): WorkflowResources
    {
        $this->resourceBuilds++;

        return new WorkflowResources(['operation' => static fn (): string => 'segment']);
    }
}
