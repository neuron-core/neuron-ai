<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Channel\Stub\ChunkStreamingNode;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

/**
 * A workflow instance keeps nothing of a segment, so a call made while one of
 * its segments is in flight meets the persisted run as another process
 * would: the running generation refuses a new ignition, a live lease refuses
 * an abandon, and an abandoned run fences its segment out.
 */
class WorkflowSegmentOverlapTest extends TestCase
{
    protected function streaming(InMemoryPersistence $persistence): Workflow
    {
        return Workflow::make('thread_1')
            ->addNode(new ChunkStreamingNode())
            ->setPersistence($persistence)
            ->retainCompletionUntilAcknowledged();
    }

    public function test_a_second_segment_is_refused_by_the_running_generation(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = $this->streaming($persistence);

        $live = $workflow->events();
        $live->current();
        $runId = $workflow->inspect()?->runId;

        try {
            $workflow->events()->current();
            $this->fail('The overlapping segment should be refused.');
        } catch (RunInFlightException $e) {
            $this->assertSame($runId, $e->runId);
            $this->assertSame(WorkflowStatus::Running, $e->status);
        }
        iterator_to_array($live, false);
        $state = $live->getReturn();

        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->assertSame($runId, $state->getRunId());
        $control = (new PhpSerializer())->unserialize((string) $persistence->get('thread_1', '__control'));
        $this->assertInstanceOf(WorkflowControl::class, $control);
        $this->assertSame($runId, $control->runId);
    }

    public function test_a_live_lease_refuses_an_abandon_and_a_running_run_cannot_be_acknowledged(): void
    {
        $workflow = $this->streaming(new InMemoryPersistence())->setLeaseTimeout(60);
        $live = $workflow->events();
        $live->current();

        try {
            $workflow->abandon();
            $this->fail('Abandoning under a live lease should be refused.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('appears to be executing', $e->getMessage());
        }

        try {
            $workflow->acknowledge((string) $workflow->inspect()?->runId);
            $this->fail('Acknowledging a running run should be refused.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('is not completed', $e->getMessage());
        }
    }

    public function test_an_abandoned_run_fences_its_segment_out(): void
    {
        $workflow = $this->streaming(new InMemoryPersistence());
        $live = $workflow->events();
        $live->current();

        $this->assertTrue($workflow->abandon());

        try {
            iterator_to_array($live, false);
            $this->fail('The abandoned segment should not commit.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('Stale execution attempt', $e->getMessage());
        }
        $this->assertNull($workflow->inspect());
    }

    public function test_a_discarded_segment_releases_the_instance(): void
    {
        $workflow = $this->streaming(new InMemoryPersistence());

        $discarded = $workflow->events();
        $discarded->current();
        unset($discarded);

        // The run it left behind is still marked running with no lease, so an
        // inputless continuation on the same instance takes it over.
        $state = $workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume());

        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
    }
}
