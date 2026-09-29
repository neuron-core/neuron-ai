<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stub\ConditionalNode;
use NeuronAI\Tests\Workflow\Stub\CustomState;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class WorkflowValidationTest extends TestCase
{
    use ExecutorTestHelpers;

    public function test_validation_fails_with_empty_workflow(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that handle ' . StartEvent::class);

        $workflow = Workflow::make('workflow_1');
        $this->execute($workflow);
    }

    public function test_validation_fails_with_missing_start_node(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that handle ' . StartEvent::class);

        $workflow = Workflow::make('workflow_1')
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

        $workflow = Workflow::make('workflow_1')->addNode($invalidNode);
        $this->execute($workflow);
    }

    public function test_two_nodes_handling_the_same_event_are_rejected(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Node for event ' . FirstEvent::class . ' already exists');

        $this->execute(Workflow::make('workflow_1')->addNodes([new NodeOne(), new NodeTwo(), new ConditionalNode(), new NodeThree()]));
    }

    public function test_an_invalid_node_signature_fails_the_run_naming_the_node(): void
    {
        $invalid = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): string
            {
                return 'not an event';
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Failed to validate ' . $invalid::class . ': __invoke method must return a type that implements ' . Event::class);

        $this->execute(Workflow::make('workflow_1')->addNode($invalid));
    }

    public function test_validation_custom_state(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, CustomState $state): StopEvent
            {
                return new StopEvent();
            }
        };

        $workflow = Workflow::make('workflow_1', new CustomState())->addNode($node);
        $state = $this->execute($workflow);
        $this->assertInstanceOf(CustomState::class, $state);
        $this->assertEquals('custom property', $state->custom);
    }

    public function test_a_node_needing_a_state_the_workflow_does_not_provide_fails_the_run_before_any_node_executes(): void
    {
        $first = new class () extends Node {
            public bool $executed = false;

            public function __invoke(StartEvent $event, WorkflowState $state): FirstEvent
            {
                $this->executed = true;
                return new FirstEvent();
            }
        };
        $needsCustomState = new class () extends Node {
            public function __invoke(FirstEvent $event, CustomState $state): StopEvent
            {
                return new StopEvent();
            }
        };
        $workflow = Workflow::make('missing-state')->setPersistence(new InMemoryPersistence())
            ->addNodes([fn (): Node => $first, $needsCustomState]);

        try {
            $workflow->run();
            $this->fail('The graph must refuse a node whose state is not provided.');
        } catch (WorkflowException $e) {
            $this->assertSame(
                'Failed to validate ' . $needsCustomState::class . ': __invoke method needs ' . CustomState::class
                . ', but the workflow provides ' . WorkflowState::class,
                $e->getMessage(),
            );
        }

        $this->assertFalse($first->executed);
        $this->assertSame(WorkflowStatus::Failed, $workflow->inspect()?->status);
    }

    #[DataProvider('invalidLeaseTimeoutProvider')]
    public function test_explicit_lease_timeout_must_be_positive(int $seconds): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Lease timeout must be a positive number of seconds or null.');

        Workflow::make()->setLeaseTimeout($seconds);
    }

    /** @return array<string, array{int}> */
    public static function invalidLeaseTimeoutProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    public function test_default_lease_timeout_must_be_positive(): void
    {
        $workflow = new class (workflowId: 'workflow_1') extends Workflow {
            protected function leaseTimeout(): int
            {
                return 0;
            }
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Lease timeout must be a positive number of seconds or null.');

        $workflow->run();
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    public function test_explicit_max_steps_must_be_positive(int $steps): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Max steps must be a positive number of steps or null.');

        Workflow::make()->setMaxSteps($steps);
    }

    public function test_an_invalid_default_max_steps_is_refused_before_a_run_is_claimed(): void
    {
        $workflow = (new class (workflowId: 'invalid-budget') extends Workflow {
            protected function maxSteps(): int
            {
                return 0;
            }
        })->setPersistence(new InMemoryPersistence());

        try {
            $workflow->run();
            $this->fail('An invalid default budget must be refused.');
        } catch (WorkflowException $e) {
            $this->assertSame('Max steps must be a positive number of steps or null.', $e->getMessage());
        }

        $this->assertNull($workflow->inspect());
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
