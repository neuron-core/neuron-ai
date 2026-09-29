<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use DateTimeImmutable;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A resumed node runs again from the top: every wait it already passed
 * returns its recorded answer, and the new answer reaches the wait that asked.
 */
class WaitAnswersTest extends TestCase
{
    protected InMemoryPersistence $persistence;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
    }

    protected function workflow(NodeInterface $node): Workflow
    {
        return Workflow::make('waits')->setPersistence($this->persistence)->addNode($node);
    }

    public function test_each_wait_receives_its_own_answer(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                $state->set('first', $this->awaitEvent('first'));
                $state->set('second', $this->awaitEvent('second'));

                return new StopEvent();
            }
        };

        $this->workflow($node)->run();
        $this->workflow($node)->run(ExecutionRequest::resume(['n' => 1]));
        $state = $this->workflow($node)->run(ExecutionRequest::signal('second', ['n' => 2]));

        $this->assertFalse($state->isInterrupted());
        $this->assertSame(['n' => 1], $state->get('first'));
        $this->assertSame(['n' => 2], $state->get('second'));
    }

    public function test_two_waits_for_the_same_event_each_receive_their_own_answer(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                $state->set('step1', $this->awaitEvent('approval'));
                $state->set('step2', $this->awaitEvent('approval'));

                return new StopEvent();
            }
        };

        $this->workflow($node)->run();
        $this->workflow($node)->run(ExecutionRequest::resume(['approved' => true]));
        $state = $this->workflow($node)->run(ExecutionRequest::resume(['approved' => false]));

        $this->assertFalse($state->isInterrupted());
        $this->assertSame(['approved' => true], $state->get('step1'));
        $this->assertSame(['approved' => false], $state->get('step2'));
    }

    public function test_a_wait_whose_condition_is_false_never_takes_an_answer(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                $state->set('review', $this->interruptIf(false, new WaitForEventRequest('review')));
                $state->set('payment', $this->awaitEvent('payment'));

                return new StopEvent();
            }
        };

        $this->workflow($node)->run();
        $state = $this->workflow($node)->run(ExecutionRequest::resume(['paid' => 10]));

        $this->assertFalse($state->isInterrupted());
        $this->assertNull($state->get('review'));
        $this->assertSame(['paid' => 10], $state->get('payment'));
    }

    public function test_a_loop_asking_again_receives_each_answer_in_turn(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                $codes = [];
                do {
                    $codes[] = $code = $this->awaitEvent('code')['code'] ?? null;
                } while ($code !== 'ok');
                $state->set('codes', $codes);

                return new StopEvent();
            }
        };

        $this->workflow($node)->run();
        $this->workflow($node)->run(ExecutionRequest::resume(['code' => 'wrong']));
        $state = $this->workflow($node)->run(ExecutionRequest::resume(['code' => 'ok']));

        $this->assertFalse($state->isInterrupted());
        $this->assertSame(['wrong', 'ok'], $state->get('codes'));
    }

    public function test_waits_around_a_memoized_closure_keep_their_answers(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                $state->set('before', $this->awaitEvent('before'));
                $state->set('inside', $this->memoize('inside', fn (): ?array => $this->awaitEvent('inside')));
                $state->set('after', $this->awaitEvent('after'));

                return new StopEvent();
            }
        };

        $this->workflow($node)->run();
        $this->workflow($node)->run(ExecutionRequest::resume(['for' => 'before']));
        $this->workflow($node)->run(ExecutionRequest::resume(['for' => 'inside']));
        $state = $this->workflow($node)->run(ExecutionRequest::resume(['for' => 'after']));

        $this->assertFalse($state->isInterrupted());
        $this->assertSame(['for' => 'before'], $state->get('before'));
        $this->assertSame(['for' => 'inside'], $state->get('inside'));
        $this->assertSame(['for' => 'after'], $state->get('after'));
    }

    public function test_a_wait_that_times_out_after_an_answered_one_returns_null(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                $state->set('order', $this->awaitEvent('order'));
                $state->set('payment', $this->awaitEvent('payment', new DateTimeImmutable('-1 minute')) ?? 'timed out');

                return new StopEvent();
            }
        };

        $this->workflow($node)->run();
        $this->workflow($node)->run(ExecutionRequest::resume(['order' => 7]));
        $state = $this->workflow($node)->run(ExecutionRequest::resume());

        $this->assertFalse($state->isInterrupted());
        $this->assertSame(['order' => 7], $state->get('order'));
        $this->assertSame('timed out', $state->get('payment'));
    }

    public function test_a_node_reaching_a_new_wait_before_the_answered_one_fails_instead_of_losing_the_answer(): void
    {
        $trace = (object) ['review' => false];
        $node = new class ($trace) extends Node {
            public function __construct(protected stdClass $trace)
            {
            }

            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                $this->interruptIf($this->trace->review, new WaitForEventRequest('review'));
                $state->set('payment', $this->awaitEvent('payment'));

                return new StopEvent();
            }
        };

        $this->workflow($node)->run();
        $trace->review = true;

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('A node must reach its waits in the same order every time it runs');

        $this->workflow($node)->run(ExecutionRequest::resume(['paid' => 10]));
    }
}
