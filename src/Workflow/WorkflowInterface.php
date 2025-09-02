<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

interface WorkflowInterface extends \SplSubject
{
    public function start(bool $resume = false, mixed $externalFeedback = null): WorkflowHandler;

    public function wakeup(mixed $feedback = null): WorkflowHandler;

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
