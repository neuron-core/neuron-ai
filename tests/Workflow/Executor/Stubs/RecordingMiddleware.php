<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

class RecordingMiddleware implements WorkflowMiddleware
{
    /** @var array{node: class-string, branchId: string|null}[] */
    public array $beforeCalls = [];

    /** @var array{node: class-string, branchId: string|null}[] */
    public array $afterCalls = [];

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        $this->beforeCalls[] = [
            'node' => $node::class,
            'branchId' => $state->get('__branchId'),
        ];
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        $this->afterCalls[] = [
            'node' => $node::class,
            'branchId' => $state->get('__branchId'),
        ];
    }
}
