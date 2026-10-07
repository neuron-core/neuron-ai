<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use Closure;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\StaleWorkflowRunException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Workflow\Executor\Stub\MemoizingNode;
use NeuronAI\Tests\Workflow\Stub\KeyedWorkflow;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\SleepUntilNode;
use NeuronAI\Workflow\Executor\ActiveInterrupt;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function time;

/**
 * Whatever the engine refuses because of the state a run is in names its
 * reason, so a caller routes on the reason and never on the message.
 */
class WorkflowRefusalTest extends TestCase
{
    protected InMemoryPersistence $persistence;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
    }

    protected function workflow(): KeyedWorkflow
    {
        return KeyedWorkflow::make('order')->setPersistence($this->persistence);
    }

    /** The run waits for the approval InterruptableNode asks for. */
    protected function suspend(): WorkflowState
    {
        return $this->workflow()->run();
    }

    protected function completeAndRetain(): WorkflowState
    {
        $workflow = $this->workflow()->retainCompletionUntilAcknowledged();
        $workflow->run();

        return $workflow->run(ExecutionRequest::resume([]));
    }

    /** As if another process had claimed the suspended run and were executing it. */
    protected function claimUnderLease(): void
    {
        $serializer = new PhpSerializer();
        $expected = (string) $this->persistence->get('order', '__control');
        $control = $serializer->unserialize($expected);
        $this->assertInstanceOf(WorkflowControl::class, $control);

        $this->persistence->writeIfUnchanged('order', '__control', $expected, [
            '__control' => $serializer->serialize($control->claim(time() + 300)),
        ]);
    }

    /** @param Closure(): mixed $request */
    protected function assertRefused(RefusalReason $reason, Closure $request): WorkflowRefusedException
    {
        try {
            $request();
        } catch (WorkflowRefusedException $refused) {
            $this->assertSame($reason, $refused->reason);

            return $refused;
        }

        $this->fail("Expected a refusal for reason '{$reason->value}'.");
    }

    public function test_a_start_meeting_a_live_run_reports_run_in_flight(): void
    {
        $this->suspend();

        $refused = $this->assertRefused(RefusalReason::RunInFlight, fn (): WorkflowState => $this->workflow()->run());

        $this->assertInstanceOf(RunInFlightException::class, $refused);
    }

    public function test_a_continuation_without_a_run_reports_no_run(): void
    {
        $this->assertRefused(RefusalReason::NoRun, fn (): WorkflowState => $this->workflow()->run(ExecutionRequest::resume()));
    }

    public function test_a_fence_naming_another_run_reports_stale_run(): void
    {
        $this->suspend();

        $refused = $this->assertRefused(
            RefusalReason::StaleRun,
            fn (): WorkflowState => $this->workflow()->run(ExecutionRequest::resume([], 'run_other')),
        );

        $this->assertInstanceOf(StaleWorkflowRunException::class, $refused);
    }

    public function test_a_fence_naming_another_attempt_reports_stale_attempt(): void
    {
        $state = $this->suspend();
        $other = (int) $state->getExecutionAttempt() + 1;

        $this->assertRefused(
            RefusalReason::StaleAttempt,
            fn (): WorkflowState => $this->workflow()->run(ExecutionRequest::resume([], $state->getRunId(), $other)),
        );
        $this->assertRefused(
            RefusalReason::StaleAttempt,
            fn (): bool => $this->workflow()->abandon($state->getRunId(), $other),
        );
    }

    public function test_a_segment_that_lost_its_run_reports_stale_attempt(): void
    {
        $this->suspend();
        $intruder = FakeMiddleware::make()->setBeforeHandler(function (): void {
            // Another process discards the run while the resumed segment executes it.
            (new WorkflowEngine($this->persistence))->abandon('order');
        });

        $this->assertRefused(
            RefusalReason::StaleAttempt,
            fn (): WorkflowState => $this->workflow()
                ->addMiddleware(NodeThree::class, $intruder)
                ->run(ExecutionRequest::resume([])),
        );
    }

    public function test_requests_meeting_an_executing_run_report_executing(): void
    {
        $this->suspend();
        $this->claimUnderLease();

        $this->assertRefused(
            RefusalReason::Executing,
            fn (): WorkflowState => $this->workflow()->run(ExecutionRequest::resume()),
        );
        $this->assertRefused(
            RefusalReason::Executing,
            fn (): WorkflowState => $this->workflow()->run(ExecutionRequest::resume(['action_id' => 'approve'])),
        );
        $this->assertRefused(RefusalReason::Executing, fn (): bool => $this->workflow()->abandon());
    }

    public function test_a_signal_the_run_does_not_wait_for_reports_not_awaited(): void
    {
        $this->suspend();

        $this->assertRefused(
            RefusalReason::NotAwaited,
            fn (): WorkflowState => $this->workflow()->run(ExecutionRequest::signal('payment.received')),
        );
    }

    public function test_a_signal_for_a_retained_completion_reports_not_awaited(): void
    {
        $this->completeAndRetain();

        $this->assertRefused(
            RefusalReason::NotAwaited,
            fn (): WorkflowState => $this->workflow()->run(ExecutionRequest::signal('approval')),
        );
    }

    public function test_an_answer_without_a_current_interruption_reports_not_awaited(): void
    {
        $failing = fn (): Workflow => Workflow::make('order')
            ->setPersistence($this->persistence)
            ->addNode(new MemoizingNode(shouldCrash: true));
        try {
            $failing()->run();
            $this->fail('Expected the node failure to propagate.');
        } catch (RuntimeException) {
        }

        $this->assertRefused(
            RefusalReason::NotAwaited,
            fn (): WorkflowState => $failing()->run(ExecutionRequest::resume(['late' => true])),
        );
    }

    public function test_an_answer_the_current_wait_cannot_take_reports_not_awaited(): void
    {
        $sleeping = fn (): Workflow => Workflow::make('order')
            ->setPersistence($this->persistence)
            ->addNodes([new NodeOne(), new SleepUntilNode(), new NodeThree()]);
        $sleeping()->run();

        $refused = $this->assertRefused(
            RefusalReason::NotAwaited,
            fn (): WorkflowState => $sleeping()->run(ExecutionRequest::resume(['wake' => true])),
        );

        $this->assertStringContainsString("Resume input 'event' is incompatible with interrupt 1", $refused->getMessage());
    }

    public function test_replacing_an_accepted_answer_reports_not_awaited(): void
    {
        $request = (new WaitForEventRequest('order.paid'))->withId(1);
        $answered = (new ActiveInterrupt($request, 'step-1'))->withInput(ResumeInput::event($request, ['paid' => true]));

        $this->assertRefused(
            RefusalReason::NotAwaited,
            fn (): ActiveInterrupt => $answered->withInput(ResumeInput::event($request, ['paid' => false])),
        );
    }

    public function test_abandoning_a_retained_completion_reports_completed(): void
    {
        $this->completeAndRetain();

        $this->assertRefused(RefusalReason::Completed, fn (): bool => $this->workflow()->abandon());
    }

    public function test_acknowledging_an_unfinished_run_reports_not_completed(): void
    {
        $state = $this->suspend();

        $this->assertRefused(RefusalReason::NotCompleted, function () use ($state): void {
            $this->workflow()->acknowledge((string) $state->getRunId());
        });
    }

    public function test_a_verb_that_loses_a_race_reports_conflict(): void
    {
        $this->persistence = new class () extends InMemoryPersistence {
            public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
            {
                // Another worker rewrites control between the read and the delete.
                $this->storage[$partition][$conditionKey] = $expectedValue . ' ';

                return parent::deleteIfUnchanged($partition, $conditionKey, $expectedValue);
            }
        };
        $this->suspend();

        $this->assertRefused(RefusalReason::Conflict, fn (): bool => $this->workflow()->abandon());
    }

    public function test_an_ignition_that_loses_a_race_reports_conflict(): void
    {
        $this->persistence = new class () extends InMemoryPersistence {
            public function initializeIfAbsent(string $partition, string $conditionKey, string $initialValue, array $records = []): bool
            {
                // Another worker ignited the run and swept it before it could be read.
                return false;
            }
        };

        $this->assertRefused(RefusalReason::Conflict, fn (): WorkflowState => $this->workflow()->run());
    }
}
