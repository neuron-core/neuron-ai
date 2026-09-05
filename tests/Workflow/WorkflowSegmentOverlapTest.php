<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Channel\Stub\ChunkStreamingNode;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

/**
 * A workflow instance runs one segment at a time. A second segment started
 * under a live one used to overwrite the executor's working context, so the
 * live segment finished stamped with another ignition's run ID. It is now
 * refused before anything is touched, and a discarded segment releases the
 * instance.
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

    public function test_a_second_segment_is_refused_while_one_is_in_flight(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = $this->streaming($persistence);

        $live = $workflow->events();
        $live->current();
        $runId = $workflow->getRunId();

        try {
            $workflow->events()->current();
            $this->fail('The overlapping segment should be refused.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('already in flight', $e->getMessage());
        }

        foreach ($live as $ignored) {
        }
        $state = $live->getReturn();

        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->assertSame($runId, $state->getRunId());
        $control = (new PhpSerializer())->unserialize((string) $persistence->get('thread_1', '__control'));
        $this->assertInstanceOf(WorkflowControl::class, $control);
        $this->assertSame($runId, $control->runId);
    }

    public function test_abandon_and_acknowledge_are_refused_while_a_segment_is_in_flight(): void
    {
        $workflow = $this->streaming(new InMemoryPersistence());
        $live = $workflow->events();
        $live->current();

        try {
            $workflow->abandonRun();
            $this->fail('Abandoning under a live segment should be refused.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('already in flight', $e->getMessage());
        }

        try {
            $workflow->acknowledgeCompletion((string) $workflow->getRunId());
            $this->fail('Acknowledging under a live segment should be refused.');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('already in flight', $e->getMessage());
        }
    }

    public function test_a_discarded_segment_releases_the_instance(): void
    {
        $workflow = $this->streaming(new InMemoryPersistence());

        $discarded = $workflow->events();
        $discarded->current();
        unset($discarded);

        // The run it left behind is still marked running with no lease, so an
        // inputless continuation on the same instance takes it over.
        $state = $workflow->run([]);

        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
    }
}
