<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\Node;

class ReviewedAgent extends Agent
{
    /** @return Node[] */
    protected function exitNodes(): array
    {
        return [new ReviewNode()];
    }
}
