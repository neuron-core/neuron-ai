<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WorkflowObservabilityFailureTest extends TestCase
{
    public function test_workflow_end_listener_cannot_turn_completion_into_failure(): void
    {
        $persistence = new InMemoryPersistence();
        $errors = [];
        $workflow = Workflow::make(workflowId: 'observer-failure')
            ->setPersistence($persistence)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->subscribe(WorkflowEnd::class, function (): void {
                throw new RuntimeException('observer failed');
            })
            ->subscribe(AgentError::class, function (AgentError $error) use (&$errors): void {
                $errors[] = $error;
            });

        $state = $workflow->run();

        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->assertNull($persistence->get('observer-failure', '__control'));
        $this->assertCount(1, $errors);
        $this->assertSame('observer failed', $errors[0]->exception->getMessage());
    }
}
