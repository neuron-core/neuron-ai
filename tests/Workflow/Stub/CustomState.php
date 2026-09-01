<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use NeuronAI\Workflow\WorkflowState;

class CustomState extends WorkflowState
{
    public string $custom = 'custom property';
}
