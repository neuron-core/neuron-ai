<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stub\CustomState;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

class WorkflowValidationTest extends TestCase
{
    use ExecutorTestHelpers;

    public function test_validation_fails_with_empty_workflow(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that handle ' . StartEvent::class);

        $workflow = Workflow::make();
        $this->execute($workflow);
    }

    public function test_validation_fails_with_missing_start_node(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that handle ' . StartEvent::class);

        $workflow = Workflow::make()
            ->addNode(new NodeTwo())
            ->addNode(new NodeThree());

        $this->execute($workflow);
    }

    public function test_validation_with_missing_handler(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No node found that handle event: ' . FirstEvent::class);

        $invalidNode = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): FirstEvent
            {
                return new FirstEvent('');
            }
        };

        $workflow = Workflow::make()->addNode($invalidNode);
        $this->execute($workflow);
    }

    public function test_validation_custom_state(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, CustomState $state): StopEvent
            {
                return new StopEvent();
            }
        };

        $workflow = Workflow::make(state: new CustomState())->addNode($node);
        $state = $this->execute($workflow);
        $this->assertInstanceOf(CustomState::class, $state);
        $this->assertEquals('custom property', $state->custom);
    }

    public function test_validation_failure_marks_the_owned_run_as_failed(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = Workflow::make(workflowId: 'invalid-workflow')
            ->setPersistence($persistence)
            ->setLeaseTimeout(300);

        try {
            $workflow->run();
            $this->fail('Expected validation to fail.');
        } catch (WorkflowException) {
        }

        $raw = $persistence->get('invalid-workflow', '__control');
        $control = $raw === null ? null : (new PhpSerializer())->unserialize($raw);

        $this->assertInstanceOf(WorkflowControl::class, $control);
        $this->assertSame(WorkflowStatus::Failed, $control->status);
        $this->assertNull($control->leaseExpiresAt);
    }
}
