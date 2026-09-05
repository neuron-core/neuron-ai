<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Persistence\Stub\CountingPersistence;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Executor\StepResult;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Executor\WorkflowRunStore;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

class WorkflowRunStoreTest extends TestCase
{
    public function test_initializes_and_loads_one_run_partition(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $control = new WorkflowControl('run-1', WorkflowStatus::Running);
        $ignition = new Ignition('run-1', new StartEvent());

        $store = new WorkflowRunStore($persistence, $serializer, 'workflow-1');

        $this->assertTrue($store->initialize($control, $ignition));
        $this->assertSame($control, $store->control());

        $reconstructed = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $this->assertEquals($control, $reconstructed->loadControl());
        $this->assertEquals($ignition, $reconstructed->loadIgnition());
    }

    public function test_rejects_a_second_initialization(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $control = new WorkflowControl('run-1', WorkflowStatus::Running);
        $ignition = new Ignition('run-1', new StartEvent());

        $first = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $second = new WorkflowRunStore($persistence, $serializer, 'workflow-1');

        $this->assertTrue($first->initialize($control, $ignition));
        $this->assertFalse($second->initialize($control, $ignition));
    }

    public function test_replaces_control_and_writes_records_atomically(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $store = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $store->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );

        $suspended = new WorkflowControl('run-1', WorkflowStatus::Suspended);
        $step = new StepResult('step-1');
        $store->commitStep($step, $suspended);

        $this->assertSame($suspended, $store->control());
        $this->assertEquals($step, $store->loadStep('step-1'));
    }

    public function test_load_step_returns_null_when_record_is_absent(): void
    {
        $store = new WorkflowRunStore(
            new InMemoryPersistence(),
            new PhpSerializer(),
            'workflow-1',
        );

        $store->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );
        $this->assertNull($store->loadStep('missing-step'));
    }

    public function test_load_step_rejects_a_present_record_with_the_wrong_type(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $store = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $store->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );
        $control = $persistence->get('workflow-1', '__control');
        $this->assertNotNull($control);
        $persistence->writeIfUnchanged('workflow-1', '__control', $control, [
            'run-1/step-1' => $serializer->serialize('not-a-step-result'),
        ]);

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage(
            "Invalid step record 'run-1/step-1' for workflow ID 'workflow-1'.",
        );

        $reader = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $reader->loadControl();
        $reader->loadStep('step-1');
    }

    public function test_load_step_rejects_an_undecodable_record(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $store = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $store->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );
        $control = $persistence->get('workflow-1', '__control');
        $this->assertNotNull($control);
        $this->assertTrue($persistence->writeIfUnchanged(
            'workflow-1',
            '__control',
            $control,
            ['run-1/step-1' => 'not-serialized-data'],
        ));

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage(
            "Invalid step record 'run-1/step-1' for workflow ID 'workflow-1'.",
        );

        $reader = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $reader->loadControl();
        $reader->loadStep('step-1');
    }

    public function test_stale_store_cannot_write_after_another_store_changes_control(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $owner = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $stale = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $owner->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );
        $stale->loadControl();
        $owner->replaceControl(new WorkflowControl('run-1', WorkflowStatus::Suspended));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Stale execution attempt 1');

        $stale->commitStep(new StepResult('step-1'));
    }

    public function test_memoizer_writes_require_the_current_control_value(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $owner = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $stale = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $owner->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );
        $stale->loadControl();
        $memoizer = $stale->memoizer('step-1');
        $owner->replaceControl(new WorkflowControl('run-1', WorkflowStatus::Suspended));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Stale execution attempt 1');

        $memoizer->memo('provider', fn (): string => 'result');
    }

    public function test_existing_memoizer_uses_the_stores_latest_control_snapshot(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $store = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $store->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );
        $memoizer = $store->memoizer('step-1');

        $store->replaceControl(new WorkflowControl('run-1', WorkflowStatus::Suspended));

        $this->assertSame('result', $memoizer->memo('provider', fn (): string => 'result'));
        $this->assertSame('result', $memoizer->memo('provider', fn (): string => 'must-not-run'));
    }

    public function test_memoized_null_is_replayed_without_repeating_the_operation(): void
    {
        $persistence = new InMemoryPersistence();
        $memoizer = \NeuronAI\Tests\Support\WorkflowTestStore::memoizer($persistence, 'workflow-1', 'step-1');
        $calls = 0;
        $operation = function () use (&$calls): mixed {
            $calls++;
            return null;
        };

        $this->assertNull($memoizer->memo('nullable', $operation));
        $replayed = \NeuronAI\Tests\Support\WorkflowTestStore::memoizer($persistence, 'workflow-1', 'step-1');
        $this->assertNull($replayed->memo('nullable', $operation));
        $this->assertSame(1, $calls);
    }

    public function test_deletes_only_the_owned_partition(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $owner = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $stale = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $owner->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );
        $stale->loadControl();
        $owner->replaceControl(new WorkflowControl('run-1', WorkflowStatus::Suspended));

        $this->assertFalse($stale->deleteIfOwned());
        $this->assertTrue($owner->deleteIfOwned());
        $this->assertNull($persistence->get('workflow-1', '__control'));
    }

    public function test_checkpoint_and_outcome_are_run_scoped_records_beside_control(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $store = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $store->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );
        $this->assertNull($store->loadCheckpoint());
        $this->assertNull($store->loadOutcome());

        $checkpoint = new WorkflowState(['phase' => 'paused']);
        $suspended = new WorkflowControl('run-1', WorkflowStatus::Suspended);
        $store->commitCheckpoint($checkpoint, $suspended);

        $this->assertSame($suspended, $store->control());
        $this->assertEquals($checkpoint, $store->loadCheckpoint());
        $this->assertNotNull($persistence->get('workflow-1', 'run-1/__checkpoint'));
        // The fence stays small: workflow state never rides inside __control.
        $this->assertStringNotContainsString('paused', (string) $persistence->get('workflow-1', '__control'));

        $outcome = new WorkflowState(['phase' => 'done']);
        $completed = new WorkflowControl('run-1', WorkflowStatus::Completed);
        $store->commitOutcome($outcome, $completed);

        $this->assertSame($completed, $store->control());
        $this->assertEquals($outcome, $store->loadOutcome());
        $this->assertNotNull($persistence->get('workflow-1', 'run-1/__outcome'));
        $this->assertStringNotContainsString('done', (string) $persistence->get('workflow-1', '__control'));
    }

    public function test_load_checkpoint_rejects_a_present_record_with_the_wrong_type(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $store = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $store->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );
        $control = $persistence->get('workflow-1', '__control');
        $this->assertNotNull($control);
        $persistence->writeIfUnchanged('workflow-1', '__control', $control, [
            'run-1/__checkpoint' => $serializer->serialize('not-a-state'),
        ]);

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage(
            "Invalid checkpoint record 'run-1/__checkpoint' for workflow ID 'workflow-1'.",
        );

        $reader = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $reader->loadControl();
        $reader->loadCheckpoint();
    }

    public function test_a_fresh_run_resolves_unwritten_records_without_reading_the_backend(): void
    {
        $persistence = new CountingPersistence();
        $store = new WorkflowRunStore($persistence, new PhpSerializer(), 'workflow-1');
        $store->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );
        $persistence->reads = 0;
        $calls = 0;
        $nullable = function () use (&$calls): mixed {
            $calls++;
            return null;
        };

        $this->assertNull($store->loadStep('step-1'));
        $this->assertNull($store->recallMemo('step-1', 'provider'));
        $this->assertSame('result', $store->memo('step-1', 'provider', fn (): string => 'result'));
        $this->assertSame('result', $store->memo('step-1', 'provider', fn (): string => 'must-not-run'));
        $this->assertNull($store->memo('step-1', 'nullable', $nullable));
        $this->assertNull($store->memo('step-1', 'nullable', $nullable));
        $store->commitStep(new StepResult('step-1'));
        $this->assertEquals(new StepResult('step-1'), $store->loadStep('step-1'));

        $this->assertSame(1, $calls);
        $this->assertSame(0, $persistence->reads);
    }

    public function test_a_continuation_reads_each_record_once_and_serves_its_own_writes(): void
    {
        $persistence = new CountingPersistence();
        $serializer = new PhpSerializer();
        $owner = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $owner->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );
        $owner->commitStep(new StepResult('step-1'));

        $continuation = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $continuation->loadControl();
        $persistence->reads = 0;

        $this->assertEquals(new StepResult('step-1'), $continuation->loadStep('step-1'));
        $this->assertEquals(new StepResult('step-1'), $continuation->loadStep('step-1'));
        $this->assertSame(1, $persistence->reads);

        $this->assertNull($continuation->recallMemo('step-2', 'provider'));
        $this->assertNull($continuation->recallMemo('step-2', 'provider'));
        $this->assertSame(2, $persistence->reads);

        $this->assertSame('result', $continuation->memo('step-2', 'provider', fn (): string => 'result'));
        $this->assertSame('result', $continuation->recallMemo('step-2', 'provider'));
        $this->assertSame(2, $persistence->reads);
    }

    public function test_reloading_control_discards_the_segment_cache(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $igniter = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $igniter->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );
        $this->assertNull($igniter->loadStep('step-1'));

        // A write behind the igniter's back, which the engine never does
        // while the fence holds; reloading control re-anchors the view.
        $writer = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $writer->loadControl();
        $writer->commitStep(new StepResult('step-1'));

        $this->assertNull($igniter->loadStep('step-1'));
        $igniter->loadControl();
        $this->assertEquals(new StepResult('step-1'), $igniter->loadStep('step-1'));
    }

    public function test_cached_records_are_deserialized_on_access_as_detached_snapshots(): void
    {
        $store = new WorkflowRunStore(new InMemoryPersistence(), new PhpSerializer(), 'workflow-1');
        $store->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );

        $first = $store->memo('step-1', 'state', fn (): WorkflowState => new WorkflowState(['n' => 1]));
        $first->set('n', 2);

        $this->assertSame(1, $store->recallMemo('step-1', 'state')->get('n'));
    }

    public function test_a_refused_write_leaves_no_cached_record(): void
    {
        $persistence = new CountingPersistence();
        $serializer = new PhpSerializer();
        $owner = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $stale = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $owner->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );
        $stale->loadControl();
        $owner->replaceControl(new WorkflowControl('run-1', WorkflowStatus::Suspended));

        try {
            $stale->commitStep(new StepResult('step-1'));
            $this->fail('The stale write should be refused.');
        } catch (WorkflowException) {
        }

        $persistence->reads = 0;
        $this->assertNull($stale->loadStep('step-1'));
        $this->assertSame(1, $persistence->reads);
    }
}
