<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Executor\Stub\IgnitionStartEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\IgnitionWaitNode;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class IgnitionTest extends TestCase
{
    use ExecutorTestHelpers;

    protected function workflow(string $workflowId, InMemoryPersistence|FilePersistence $persistence): Workflow
    {
        return Workflow::make(workflowId: $workflowId)
            ->setPersistence($persistence)
            ->addNode(new IgnitionWaitNode());
    }

    public function test_ignition_record_is_written_on_the_first_segment(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = $this->workflow('ign_write', $persistence);
        $workflow->setStartEvent(new IgnitionStartEvent('hello'));

        $state = $workflow->run();
        $this->assertTrue($state->isInterrupted());

        $record = $persistence->get('ign_write', '__ignition');
        $this->assertNotNull($record);

        $ignition = (new PhpSerializer())->unserialize($record);
        $this->assertInstanceOf(Ignition::class, $ignition);
        $this->assertInstanceOf(IgnitionStartEvent::class, $ignition->startEvent);
        $this->assertSame('hello', $ignition->startEvent->message);
        $this->assertSame([], $ignition->context);
    }

    public function test_ignition_record_is_swept_by_clean_completion(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = $this->workflow('ign_sweep', $persistence);
        $workflow->setStartEvent(new IgnitionStartEvent());

        $workflow->run();
        $this->assertNotNull($persistence->get('ign_sweep', '__ignition'));

        $state = $workflow->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['answer' => 42])]);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame(42, $state->get('answer'));
        $this->assertNull($persistence->get('ign_sweep', '__ignition'));
    }

    public function test_blank_factory_wake_adopts_the_persisted_start_event(): void
    {
        $dir = sys_get_temp_dir() . '/neuron_ignition_' . uniqid();
        mkdir($dir);

        try {
            $first = $this->workflow('ign_roundtrip', new FilePersistence($dir));
            $first->setStartEvent(new IgnitionStartEvent('from-ignition'));
            $this->assertTrue($first->run()->isInterrupted());

            // A blank instance: same factory shape, workflow ID only — no start event set.
            $second = $this->workflow('ign_roundtrip', new FilePersistence($dir));
            $state = $second->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['answer' => 42])]);

            $this->assertFalse($state->isInterrupted());
            // The node replayed with the ADOPTED event: without adoption the
            // default StartEvent would not even route to IgnitionWaitNode.
            $this->assertSame('from-ignition', $state->get('ignited_with'));
            $this->assertSame(42, $state->get('answer'));
        } finally {
            $this->removeDirectory($dir);
        }
    }

    public function test_crash_replay_in_a_fresh_process_works_via_bare_resume(): void
    {
        $persistence = new InMemoryPersistence();

        $first = $this->workflow('ign_replay', $persistence);
        $first->setStartEvent(new IgnitionStartEvent('recovered'));
        $this->assertTrue($first->run()->isInterrupted());

        // A recovery worker knows only the workflow ID: bare resume() on a blank
        // instance adopts the record, delivers nothing, and re-suspends at
        // the same step.
        $second = $this->workflow('ign_replay', $persistence);
        $state = $second->run([]);

        $this->assertTrue($state->isInterrupted());
        $this->assertSame('recovered', $state->get('ignited_with'));
    }

    public function test_continuation_of_a_never_started_run_fails_loudly(): void
    {
        $workflow = $this->workflow('ign_unknown', new InMemoryPersistence());

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No run in flight for workflow ID 'ign_unknown'");

        $workflow->run([ResumeInput::event((new ApprovalRequest('test'))->withId(1), ['answer' => 1])]);
    }

    public function test_ignition_and_steps_share_the_workflow_persistence_under_a_custom_executor(): void
    {
        // An executor carries no store of its own: the ignition record and the
        // node steps both land in the workflow's configured persistence, so a
        // custom executor can never strand the record where no wake reads.
        $store = new InMemoryPersistence();

        $workflow = Workflow::make(workflowId: 'ign_routing')
            ->setPersistence($store)
            ->setExecutor(new WorkflowExecutor())
            ->addNode(new IgnitionWaitNode());
        $workflow->setStartEvent(new IgnitionStartEvent());

        $workflow->run();

        $this->assertNotNull($store->get('ign_routing', '__ignition'));
        $this->assertNotNull($store->get('ign_routing', $this->stepKey($workflow, IgnitionWaitNode::class . '-0')));
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                unlink($dir . '/' . $item);
            }
        }

        rmdir($dir);
    }
}
