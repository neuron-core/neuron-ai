<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class ReplayedAnswerTest extends TestCase
{
    protected InMemoryPersistence $persistence;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
    }

    protected function twoUnmemoizedWaits(): Workflow
    {
        return Workflow::make('two-waits')->setPersistence($this->persistence)->addNode(new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                $state->set('first', $this->awaitEvent('first'));
                $state->set('second', $this->awaitEvent('second'));

                return new StopEvent();
            }
        });
    }

    public function test_an_answer_is_never_silently_handed_to_a_different_wait(): void
    {
        $this->twoUnmemoizedWaits()->run();
        $this->twoUnmemoizedWaits()->run(ExecutionRequest::resume(['n' => 1]));

        try {
            $state = $this->twoUnmemoizedWaits()->run(ExecutionRequest::signal('second', ['n' => 2]));
        } catch (WorkflowException $mismatch) {
            $this->assertStringContainsString("'second'", $mismatch->getMessage());
            $this->assertStringContainsString("'first'", $mismatch->getMessage());
            return;
        }

        $this->assertFalse(
            $state->isInterrupted(),
            "The answer to 'second' was consumed by awaitEvent('first'), which re-suspended on #"
            . $state->getInterruptRequest()?->getId() . '.'
        );
        $this->assertSame(['n' => 2], $state->get('second'));
    }

    public function test_the_first_wait_still_receives_its_own_answer(): void
    {
        $this->twoUnmemoizedWaits()->run();

        $state = $this->twoUnmemoizedWaits()->run(ExecutionRequest::resume(['n' => 1]));

        $this->assertSame(['n' => 1], $state->get('first'));
        $this->assertSame('second', $state->getInterruptRequest()->getEventName());
    }
}
