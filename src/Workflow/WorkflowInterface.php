<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

interface WorkflowInterface
{
    /**
     * @deprecated Use init() instead
     */
    public function start(?InterruptRequest $resumeRequest = null): WorkflowHandler;

    public function init(?InterruptRequest $resumeRequest = null): WorkflowHandlerInterface;

    public function addNode(NodeInterface $node): Workflow;

    /**
     * @param NodeInterface[] $nodes
     */
    public function addNodes(array $nodes): Workflow;

    /**
     * @return array<string, NodeInterface>
     */
    public function getEventNodeMap(): array;

    public function getWorkflowId(): string;

    public function export(): string;
}
