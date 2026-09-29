<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Workflow\Channel\Stub\ChunkStreamingNode;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

use function array_map;
use function function_exists;
use function getmypid;
use function glob;
use function iterator_to_array;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_kill;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const SIGKILL;

/**
 * A workflow instance keeps nothing of a segment, so a call made while one of
 * its segments is in flight meets the persisted run as another process
 * would: the running generation refuses a new ignition, a live lease refuses
 * an abandon, and an abandoned run fences its segment out. A segment whose
 * consumer lets go before it settles fails its run instead of holding it.
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

        // The segment failed the run it left behind, so a plain run() recovers it.
        $this->assertSame(WorkflowStatus::Failed, $workflow->inspect()?->status);
        $state = $workflow->run();

        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
    }

    public function test_a_discarded_segment_notifies_its_channel(): void
    {
        $channel = new FakeChannel();
        $workflow = $this->streaming(new InMemoryPersistence())->setChannel(fn (): FakeChannel => $channel);

        $discarded = $workflow->events();
        $discarded->current();
        unset($discarded);

        $channel->assertFailed();
    }

    public function test_a_forked_child_never_fails_its_parents_run(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('Forking requires the pcntl extension.');
        }

        $directory = sys_get_temp_dir() . '/neuron-segment-fork-' . getmypid();
        $workflow = Workflow::make('thread_1')
            ->addNode(new ChunkStreamingNode())
            ->setPersistence(new FilePersistence($directory));
        $live = $workflow->events();
        $live->current();

        try {
            $child = pcntl_fork();
            if ($child === 0) {
                try {
                    // A forked worker ends: its copy of the segment is destroyed.
                    unset($live);
                } finally {
                    posix_kill(getmypid(), SIGKILL);
                }
            } else {
                pcntl_waitpid($child, $status);

                $this->assertSame(WorkflowStatus::Running, $workflow->inspect()?->status);
                iterator_to_array($live, false);
                $this->assertSame(WorkflowStatus::Completed, $live->getReturn()->getStatus());
            }
        } finally {
            array_map(unlink(...), glob($directory . '/*') ?: []);
            rmdir($directory);
        }
    }
}
