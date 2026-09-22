<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\Node;

class OutputAgent extends Agent
{
    /** @return Node[] */
    protected function exitNodes(\NeuronAI\Workflow\WorkflowExecution $execution): array
    {
        return [new OutputNode()];
    }
}
