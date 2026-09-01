<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

class WorkflowProgress
{
    public function __construct(public readonly int $percentage)
    {
    }
}
