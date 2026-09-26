<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\Stub\MemoizingWaitNode;
use NeuronAI\Workflow\Executor\ActiveInterrupt;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReorderedAnswerTest extends TestCase
{
    public function test_a_retried_answer_with_reordered_keys_is_the_same_answer(): void
    {
        $request = (new WaitForEventRequest('order.paid'))->withId(1);
        $answered = (new ActiveInterrupt($request, 'step-1'))->withInput(ResumeInput::event($request, ['paid' => true, 'amount' => 10]));

        $this->assertSame($answered, $answered->withInput(ResumeInput::event($request, ['amount' => 10, 'paid' => true])));
    }

    public function test_nested_reordered_keys_are_the_same_answer_but_list_order_still_matters(): void
    {
        $request = (new WaitForEventRequest('order.paid'))->withId(1);
        $answered = (new ActiveInterrupt($request, 'step-1'))
            ->withInput(ResumeInput::event($request, ['order' => ['id' => 5, 'items' => ['a', 'b']], 'paid' => true]));

        $this->assertSame($answered, $answered->withInput(ResumeInput::event($request, ['paid' => true, 'order' => ['items' => ['a', 'b'], 'id' => 5]])));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Interrupt 1 already has an accepted input; its answer cannot change.');
        $answered->withInput(ResumeInput::event($request, ['paid' => true, 'order' => ['id' => 5, 'items' => ['b', 'a']]]));
    }

    public function test_a_redelivery_with_reordered_keys_recovers_a_failed_run_with_the_accepted_answer(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = fn (bool $fail): Workflow => Workflow::make('reordered')->setPersistence($persistence)->addNode(new MemoizingWaitNode($fail));
        $workflow(false)->run();

        try {
            $workflow(true)->run(ExecutionRequest::resume(['paid' => true, 'amount' => 10]));
            $this->fail('Expected the node to fail after accepting its input.');
        } catch (RuntimeException $e) {
            $this->assertSame('Failed after memoizing the accepted answer.', $e->getMessage());
        }

        $state = $workflow(false)->run(ExecutionRequest::resume(['amount' => 10, 'paid' => true]));

        $this->assertSame(['paid' => true, 'amount' => 10], $state->get('payload'));
    }
}
