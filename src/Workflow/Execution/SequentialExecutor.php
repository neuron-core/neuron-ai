<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Execution;

use Generator;
use Inspector\Exceptions\InspectorException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\MiddlewareEnd;
use NeuronAI\Observability\Events\MiddlewareStart;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Default executor that processes nodes sequentially.
 */
class SequentialExecutor implements WorkflowExecutorInterface
{
    /**
     * Execute the workflow starting from the given event and node.
     *
     * @return Generator<int, Event, mixed, void>
     */
    public function execute(
        Workflow $workflow,
        Event $currentEvent,
        NodeInterface $currentNode,
        ?InterruptRequest $resumeRequest = null
    ): Generator {
        try {
            while (!($currentEvent instanceof StopEvent)) {
                $nodeGenerator = $this->executeNode($workflow, $currentEvent, $currentNode, $resumeRequest);
                yield from $nodeGenerator;
                $currentEvent = $nodeGenerator->getReturn();

                if ($currentEvent instanceof StopEvent) {
                    break;
                }

                $nextEventClass = $currentEvent::class;
                if (!$workflow->hasNodeForEvent($nextEventClass)) {
                    throw new WorkflowException(
                        "No node found that handle event: " . $nextEventClass
                    );
                }

                $currentNode = $workflow->getNodeForEvent($nextEventClass);
                $resumeRequest = null;
            }

            $workflow->deletePersistedState();
        } catch (WorkflowInterrupt $interrupt) {
            $workflow->persistInterrupt($interrupt);
            EventBus::emit('error', $workflow, new AgentError($interrupt, false), $workflow->getWorkflowId());
            throw $interrupt;
        } catch (Throwable $exception) {
            EventBus::emit('error', $workflow, new AgentError($exception), $workflow->getWorkflowId());
            throw $exception;
        } finally {
            $workflow->emitWorkflowEnd();
        }
    }

    /**
     * Execute a single node, yielding any streamed events and returning the next event.
     *
     * @return Generator<int, Event, mixed, Event>
     * @throws InspectorException
     */
    protected function executeNode(
        Workflow $workflow,
        Event $currentEvent,
        NodeInterface $currentNode,
        ?InterruptRequest $resumeRequest = null
    ): Generator {
        $currentNode->setWorkflowContext(
            $workflow->resolveState(),
            $currentEvent,
            $resumeRequest
        );

        EventBus::emit(
            'workflow-node-start',
            $workflow,
            new WorkflowNodeStart($currentNode::class, $workflow->resolveState()),
            $workflow->getWorkflowId()
        );

        try {
            $this->runBeforeMiddleware($workflow, $currentEvent, $currentNode);

            $result = $currentNode->run($currentEvent, $workflow->resolveState());

            if ($result instanceof Generator) {
                foreach ($result as $event) {
                    yield $event;
                }
                $currentEvent = $result->getReturn();
            } else {
                $currentEvent = $result;
            }

            $this->runAfterMiddleware($workflow, $currentEvent, $currentNode);
        } finally {
            EventBus::emit(
                'workflow-node-end',
                $workflow,
                new WorkflowNodeEnd($currentNode::class, $workflow->resolveState()),
                $workflow->getWorkflowId()
            );
        }

        return $currentEvent;
    }

    protected function runBeforeMiddleware(
        Workflow $workflow,
        Event $event,
        NodeInterface $node,
        ?WorkflowState $state = null
    ): void {
        $state ??= $workflow->resolveState();
        foreach ($workflow->getMiddlewareForNode($node) as $m) {
            EventBus::emit('middleware-before-start', $workflow, new MiddlewareStart($m, $event), $workflow->getWorkflowId());
            $m->before($node, $event, $state);
            EventBus::emit('middleware-before-end', $workflow, new MiddlewareEnd($m), $workflow->getWorkflowId());
        }
    }

    protected function runAfterMiddleware(
        Workflow $workflow,
        Event $event,
        NodeInterface $node,
        ?WorkflowState $state = null
    ): void {
        $state ??= $workflow->resolveState();
        foreach ($workflow->getMiddlewareForNode($node) as $m) {
            EventBus::emit('middleware-after-start', $workflow, new MiddlewareStart($m, $event), $workflow->getWorkflowId());
            $m->after($node, $event, $state);
            EventBus::emit('middleware-after-end', $workflow, new MiddlewareEnd($m), $workflow->getWorkflowId());
        }
    }
}
