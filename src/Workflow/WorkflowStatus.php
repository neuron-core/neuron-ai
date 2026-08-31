<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

enum WorkflowStatus: string
{
    case Running = 'running';
    case Suspended = 'suspended';
    case Completed = 'completed';
    case Failed = 'failed';
}
