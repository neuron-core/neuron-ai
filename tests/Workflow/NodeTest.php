<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use DateTimeImmutable;
use NeuronAI\Tests\Observability\Stub\CustomTestEvent;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Stub\ExposedNode;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\NodeCheckpoint;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\ProvidedResources;
use NeuronAI\Tests\Workflow\Stub\RecordingEventDispatcher;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

class NodeTest extends TestCase
{
    use ExecutorTestHelpers;

    public function test_node_run_method_signature(): void
    {
        $node = new NodeOne();
        $event = new StartEvent();
        $state = new WorkflowState();

        $result = $node->run($event, $state, new WorkflowResources());

        $this->assertInstanceOf(FirstEvent::class, $result);
        $this->assertSame('First complete', $result->message);
    }

    public function test_run_hands_the_resources_to_a_node_declaring_them(): void
    {
        $resources = new ProvidedResources(['client' => 'shared-client']);
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state, ProvidedResources $resources): StopEvent
            {
                return new StopEvent($resources);
            }
        };

        $result = $node->run(new StartEvent(), new WorkflowState(), $resources);

        $this->assertInstanceOf(StopEvent::class, $result);
        $this->assertSame($resources, $result->getResult());
    }

    public function test_node_state_modification(): void
    {
        $node = new NodeOne();
        $state = new WorkflowState(['existing' => 'data']);
        $event = new StartEvent();

        $node->setWorkflowContext(new NodeContext());

        $node->run($event, $state, new WorkflowResources());

        $this->assertTrue($state->get('node_one_executed'));
        $this->assertSame('data', $state->get('existing'));
    }

    public function test_node_checkpoint(): void
    {
        $workflow = Workflow::make('test-execution')->addNode(new NodeCheckpoint());

        $state = $this->execute($workflow);

        // Paused: the checkpoint ran, the node blocked at interrupt() before writing feedback
        $this->assertTrue($state->isInterrupted());
        $this->assertSame('test', $state->get('checkpoint'));
        $this->assertNull($state->get('feedback'));

        // Resume delivers the payload; interrupt() returns it on resume.
        $state = $this->resume($workflow, payload: ['message' => 'what do you mean?']);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('test', $state->get('checkpoint'));
        $this->assertSame('what do you mean?', $state->get('feedback'));
    }

    public function test_memoize_without_an_executor_runs_every_operation_inline(): void
    {
        $node = new ExposedNode();
        $calls = 0;
        $operation = function () use (&$calls): int {
            return ++$calls;
        };

        $this->assertSame(1, $node->callMemoize('op', $operation));
        $this->assertSame(2, $node->callMemoize('op', $operation));
        $this->assertNull($node->callRecallMemo('op'));
    }

    public function test_interrupt_without_a_resume_throws_the_request_outbound(): void
    {
        $node = new ExposedNode();
        $node->setWorkflowContext(new NodeContext());
        $request = new WaitForEventRequest('order.approved');

        try {
            $node->callInterrupt($request);
            $this->fail('A first pass must suspend.');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertSame($request, $interrupt->getRequest());
        }
    }

    public function test_interrupt_returns_the_payload_once_and_a_second_interrupt_suspends_again(): void
    {
        $node = new ExposedNode();
        $node->setWorkflowContext(new NodeContext(payload: ['approved' => true]));

        $this->assertSame(['approved' => true], $node->callInterrupt(new WaitForEventRequest('first')));

        $second = new WaitForEventRequest('second');
        try {
            $node->callInterrupt($second);
            $this->fail('A consumed answer cannot satisfy a later interruption.');
        } catch (WorkflowInterrupt $interrupt) {
            $this->assertSame($second, $interrupt->getRequest());
        }
    }

    public function test_interrupt_if_false_continues_without_suspending(): void
    {
        $node = new ExposedNode();
        $node->setWorkflowContext(new NodeContext());

        $this->assertNull($node->callInterruptIf(false, new WaitForEventRequest('never')));
        $this->assertNull($node->callInterruptIf(fn (): bool => false, new WaitForEventRequest('never')));
    }

    public function test_interrupt_if_evaluates_a_callable_condition(): void
    {
        $node = new ExposedNode();
        $node->setWorkflowContext(new NodeContext());

        $this->expectException(WorkflowInterrupt::class);
        $node->callInterruptIf(fn (): bool => true, new WaitForEventRequest('now'));
    }

    public function test_interrupt_if_does_not_reevaluate_the_condition_on_resume(): void
    {
        $node = new ExposedNode();
        $node->setWorkflowContext(new NodeContext(payload: ['answer' => 42]));

        $payload = $node->callInterruptIf(function (): bool {
            $this->fail('The condition belongs to the first pass only.');
        }, new WaitForEventRequest('question'));

        $this->assertSame(['answer' => 42], $payload);
    }

    public function test_await_event_returns_the_delivered_payload(): void
    {
        $node = new ExposedNode();
        $node->setWorkflowContext(new NodeContext(payload: ['id' => 7]));

        $this->assertSame(['id' => 7], $node->callAwaitEvent('user.signup'));
    }

    public function test_await_event_resumed_without_a_payload_returns_an_empty_answer(): void
    {
        $node = new ExposedNode();
        $node->setWorkflowContext(new NodeContext(resuming: true));

        $this->assertSame([], $node->callAwaitEvent('user.signup'));
    }

    public function test_await_event_returns_null_on_timeout_and_a_later_wait_suspends_again(): void
    {
        $node = new ExposedNode();
        $node->setWorkflowContext(new NodeContext(timedOut: true));

        $this->assertNull($node->callAwaitEvent('user.signup', new DateTimeImmutable('-1 minute')));

        $this->expectException(WorkflowInterrupt::class);
        $node->callAwaitEvent('user.signup');
    }

    public function test_await_event_suspends_with_its_name_and_deadline(): void
    {
        $node = new ExposedNode();
        $node->setWorkflowContext(new NodeContext());
        $expiresAt = new DateTimeImmutable('+1 hour');

        try {
            $node->callAwaitEvent('user.signup', $expiresAt);
            $this->fail('A first pass must suspend.');
        } catch (WorkflowInterrupt $interrupt) {
            $request = $interrupt->getRequest();
            $this->assertInstanceOf(WaitForEventRequest::class, $request);
            $this->assertSame('user.signup', $request->getEventName());
            $this->assertSame($expiresAt, $request->getExpiresAt());
        }
    }

    public function test_sleep_until_suspends_with_its_wake_time_and_returns_null_on_resume(): void
    {
        $node = new ExposedNode();
        $node->setWorkflowContext(new NodeContext());
        $wakeAt = new DateTimeImmutable('+1 hour');

        try {
            $node->callSleepUntil($wakeAt);
            $this->fail('A first pass must suspend.');
        } catch (WorkflowInterrupt $interrupt) {
            $request = $interrupt->getRequest();
            $this->assertInstanceOf(SleepUntilRequest::class, $request);
            $this->assertSame($wakeAt->getTimestamp(), $request->getWakeAt()->getTimestamp());
        }

        $node->setWorkflowContext(new NodeContext(resuming: true));
        $this->assertNull($node->callSleepUntil($wakeAt));
    }

    public function test_emit_without_a_dispatcher_is_a_no_op(): void
    {
        $node = new ExposedNode();
        $event = new CustomTestEvent('isolated');

        $node->callEmit($event);

        $this->assertNull($event->source);
        $this->assertNull($event->branchId);
    }

    public function test_emit_stamps_observability_events_with_the_node_and_its_branch(): void
    {
        $dispatcher = new RecordingEventDispatcher();
        $node = new ExposedNode();
        $node->setWorkflowContext(new NodeContext(dispatcher: $dispatcher, branchId: 'left'));
        $event = new CustomTestEvent('from-branch');
        $foreign = new stdClass();

        $node->callEmit($event);
        $node->callEmit($foreign);

        $this->assertSame([$event, $foreign], $dispatcher->dispatched);
        $this->assertSame($node, $event->source);
        $this->assertSame('left', $event->branchId);
        $this->assertSame([], (array) $foreign);
    }

    /** @return iterable<string, array{NodeContext, bool}> */
    public static function contexts(): iterable
    {
        yield 'fresh' => [new NodeContext(), false];
        yield 'delivered payload' => [new NodeContext(payload: ['a' => 1]), true];
        yield 'delivered empty payload' => [new NodeContext(payload: []), true];
        yield 'timed out' => [new NodeContext(timedOut: true), true];
        yield 'timer resume without payload' => [new NodeContext(resuming: true), true];
        yield 'explicitly not resuming' => [new NodeContext(payload: ['a' => 1], resuming: false), false];
    }

    #[DataProvider('contexts')]
    public function test_node_context_derives_whether_the_node_is_resuming(NodeContext $context, bool $resuming): void
    {
        $this->assertSame($resuming, $context->resuming);
    }
}
