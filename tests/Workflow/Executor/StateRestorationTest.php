<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\RestorableStateNode;
use NeuronAI\Tests\Workflow\Executor\Stub\RestorableStateFork;
use NeuronAI\Tests\Workflow\Executor\Stub\RestoringStateWorkflow;
use NeuronAI\Tests\Workflow\Executor\Stub\StatefulTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StateRestorationTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_cached_branch_state_restores_dependencies_before_execution(bool $async): void
    {
        $persistence = new InMemoryPersistence();
        $make = function (bool $fail) use ($persistence, $async): RestoringStateWorkflow {
            $workflow = RestoringStateWorkflow::make('restorable-branch');
            $workflow->setPersistence($persistence)->addNodes([
                new DocumentParallelProcessing(),
                new StatefulTextProcessNode(),
                new RestorableStateNode(fail: $fail),
                new ImageProcessNode(),
                new MergeNode(),
            ]);
            if ($async) {
                $workflow->setExecutor(new AsyncExecutor());
            }

            return $workflow;
        };

        try {
            $make(true)->run();
            $this->fail('Expected failure after the branch state was recorded.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Probe failed.', $exception->getMessage());
        }

        $resumed = $make(false);
        $state = $resumed->resume()->run();
        $this->assertSame('restored:42', $state->get('analysis')['text']);
        $this->assertContains('text', $resumed->restorations);
        $this->assertFalse($state->has('branch_value'));
    }

    public function test_retained_outcome_restores_dependencies_without_running_nodes(): void
    {
        $persistence = new InMemoryPersistence();
        $first = RestoringStateWorkflow::make('restorable-outcome');
        $first->setPersistence($persistence)->retainCompletionUntilAcknowledged();
        $first->setStartEvent(new TextProcessEvent())->addNodes([
            new StatefulTextProcessNode(),
            new RestorableStateNode(),
        ]);
        $state = $first->run();
        $this->assertSame('live', ($state->operation)());
        $this->assertSame([], $first->restorations, 'Live state must not pass through restoration.');

        $resumed = RestoringStateWorkflow::make('restorable-outcome');
        $resumed->setPersistence($persistence)->retainCompletionUntilAcknowledged();
        $restored = $resumed->resume()->run();
        $this->assertSame('restored', ($restored->operation)());
        $this->assertSame(['main'], $resumed->restorations);
    }

    public function test_unaddressed_interrupt_and_stale_input_checkpoint_restore_dependencies(): void
    {
        $persistence = new InMemoryPersistence();
        $make = function () use ($persistence): RestoringStateWorkflow {
            $workflow = RestoringStateWorkflow::make('restorable-interrupts');
            $workflow->setPersistence($persistence)->addNodes([
                new RestorableStateFork(),
                new StatefulTextProcessNode(),
                new RestorableStateNode(suspend: true),
                new MergeNode(),
            ]);

            return $workflow;
        };

        $first = $make();
        $textRequest = $first->run()->getInterruptRequest();
        $this->assertNotNull($textRequest);

        $partial = $make();
        $partial->signal('text', []);
        $state = $partial->run();
        $this->assertTrue($state->isInterrupted());
        $this->assertContains('image:paused', $partial->restorations);

        $stale = $make();
        $checkpoint = $stale->resume([ResumeInput::event($textRequest, [])])->run();
        $this->assertTrue($checkpoint->isInterrupted());
        $this->assertSame('restored', ($checkpoint->operation)());
        $this->assertSame(['main'], $stale->restorations);

        $last = $make();
        $last->signal('image', []);
        $completed = $last->run();
        $this->assertFalse($completed->isInterrupted());
        $this->assertSame(['text' => 'restored:42', 'image' => 'restored:42'], $completed->get('analysis'));
    }
}
