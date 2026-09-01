<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Exporter;

enum WorkflowGraphVertexType: string
{
    case Event = 'event';
    case Node = 'node';
    case ParallelSplit = 'parallel_split';
    case ParallelJoin = 'parallel_join';
}
