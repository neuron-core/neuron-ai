<?php

declare(strict_types=1);

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

require_once __DIR__ . '/../../vendor/autoload.php';

final class OperationPrepared implements Event
{
}

final class PrepareOperation extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): OperationPrepared
    {
        $state->set('operation', 'delete temporary uploads');

        return new OperationPrepared();
    }
}

final class ApproveOperation extends Node
{
    public function __invoke(OperationPrepared $event, WorkflowState $state): StopEvent
    {
        $decisions = $this->interrupt(new ApprovalRequest(
            'This operation requires approval.',
            [new Action('delete_files', 'Delete temporary uploads')],
        ));

        $state->set(
            'result',
            ($decisions['delete_files'] ?? null) === 'approve' ? 'completed' : 'rejected',
        );

        return new StopEvent();
    }
}

$persistence = new FilePersistence(
    \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'neuron-workflow-example',
);
$workflowId = 'workflow-interrupt-demo-' . \bin2hex(\random_bytes(4));

$makeWorkflow = static function () use ($persistence, $workflowId): Workflow {
    $workflow = Workflow::make(workflowId: $workflowId);
    $workflow->setPersistence($persistence);
    $workflow->addNodes([new PrepareOperation(), new ApproveOperation()]);

    return $workflow;
};

// The first segment returns normally with a suspended state.
$suspended = $makeWorkflow()->run();
$request = $suspended->getInterruptRequest();

if (!$request instanceof ApprovalRequest) {
    throw new \RuntimeException('The workflow did not expose the expected interruption.');
}

echo "Paused: {$request->getMessage()}\n";

/*
 * Imagine a new process starts here. Application code reconstructs the graph
 * from its workflow ID, delivers the domain signal, and chooses run() or
 * events() depending on whether it needs eager or streaming consumption.
 */
$completed = $makeWorkflow()
    ->signal('approval', ['delete_files' => 'approve'])
    ->run();

echo 'Result: ' . $completed->get('result') . "\n";
