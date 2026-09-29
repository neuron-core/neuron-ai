<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Observability;

/** How a node, or a middleware phase around it, ended. */
enum NodeOutcome: string
{
    case Completed = 'completed';
    case Suspended = 'suspended';
    case Failed = 'failed';
}
