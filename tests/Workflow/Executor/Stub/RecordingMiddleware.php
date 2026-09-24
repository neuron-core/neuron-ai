<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;

class RecordingMiddleware implements WorkflowMiddleware
{
    /** @var class-string[] */
    public array $beforeCalls = [];

    /** @var class-string[] */
    public array $afterCalls = [];

    public function before(NodeInterface $node, Event $event, WorkflowState $state, WorkflowResources $resources): void
    {
        $this->beforeCalls[] = $node::class;
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state, WorkflowResources $resources): void
    {
        $this->afterCalls[] = $node::class;
    }
}
