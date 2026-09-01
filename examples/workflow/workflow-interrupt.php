<?php

declare(strict_types=1);

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Resume\ResumeInput;
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

$workflow = Workflow::make()
    ->setPersistence($persistence)
    ->addNodes([new PrepareOperation(), new ApproveOperation()]);

// The first segment returns normally with a suspended state. Its request is
// already ID-bound and persisted by the engine.
$suspended = $workflow->run();
$request = $suspended->getInterruptRequest();
$workflowId = $suspended->getWorkflowId();
$runId = $suspended->getRunId();

if (!$request instanceof ApprovalRequest || $workflowId === null || $runId === null) {
    throw new RuntimeException('The workflow did not expose the expected interruption.');
}

echo "Paused: {$request->getMessage()}" . \PHP_EOL;
echo "Workflow ID: {$workflowId}" . \PHP_EOL;
echo "Interrupt ID: {$request->getId()}" . \PHP_EOL;

/*
 * Imagine a new process starts here. Reconstruct the graph with the workflow
 * ID and persistence, then address this request. A delayed delivery also
 * supplies the run ID observed above so it cannot wake a newer generation.
 */
$workflow = Workflow::make(workflowId: $workflowId)
    ->setPersistence($persistence)
    ->addNodes([new PrepareOperation(), new ApproveOperation()]);

$completed = $workflow->resume(
    [ResumeInput::event($request->getId(), ['delete_files' => 'approve'])],
    expectedRunId: $runId,
);

echo $completed->get('result') . \PHP_EOL;
