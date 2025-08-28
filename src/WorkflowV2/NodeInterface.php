<?php

declare(strict_types=1);

namespace NeuronAI\WorkflowV2;

use NeuronAI\WorkflowV2\WorkflowContext;
use NeuronAI\WorkflowV2\WorkflowState;

interface NodeInterface
{
    public function run(WorkflowState $state): WorkflowState;
    public function setContext(WorkflowContext $context): void;
}
