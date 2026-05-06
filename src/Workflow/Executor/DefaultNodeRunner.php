<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\MiddlewareEnd;
use NeuronAI\Observability\Events\MiddlewareStart;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

/**
 * Default node runner that handles the full node lifecycle.
 *
 * Responsibilities:
 *  - Set workflow context on the node
 *  - Emit node-start via EventBus
 *  - Run before-middleware
 *  - Invoke the node (unwrapping Generators for streaming)
 *  - Run after-middleware
 *  - Emit node-end via EventBus
 */
class DefaultNodeRunner implements NodeRunner
{
    public function run(
        NodeInterface $node,
        Event $event,
        WorkflowState $state,
        array $middleware = [],
        ?string $branchId = null,
        ?InterruptRequest $resumeRequest = null,
    ): Generator {
        $node->setWorkflowContext($state, $event, $resumeRequest);

        $workflowId = $state->get('__workflowId');

        EventBus::emit(
            'workflow-node-start',
            $node,
            new WorkflowNodeStart($node::class, $state),
            $workflowId,
            $branchId
        );

        foreach ($middleware as $m) {
            EventBus::emit('middleware-before-start', $node, new MiddlewareStart($m, $event), $workflowId, $branchId);
            $m->before($node, $event, $state);
            EventBus::emit('middleware-before-end', $node, new MiddlewareEnd($m), $workflowId, $branchId);
        }

        $result = $node->run($event, $state);

        if ($result instanceof Generator) {
            foreach ($result as $streamedEvent) {
                yield $streamedEvent;
            }
            $result = $result->getReturn();
        }

        foreach ($middleware as $m) {
            EventBus::emit('middleware-after-start', $node, new MiddlewareStart($m, $result), $workflowId, $branchId);
            $m->after($node, $result, $state);
            EventBus::emit('middleware-after-end', $node, new MiddlewareEnd($m), $workflowId, $branchId);
        }

        EventBus::emit(
            'workflow-node-end',
            $node,
            new WorkflowNodeEnd($node::class, $state),
            $workflowId,
            $branchId
        );

        return $result;
    }
}
