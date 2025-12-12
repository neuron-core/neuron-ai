<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use Generator;

interface WorkflowHandlerInterface
{
    public function streamEvents(?StreamAdapterInterface $adapter = null): Generator;

    public function getResult(): WorkflowState;
}
