<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\Node;

class CountingStartAgent extends Agent
{
    /** @return Node[] */
    protected function entryNodes(): array
    {
        return [new CountingStartNode()];
    }
}
