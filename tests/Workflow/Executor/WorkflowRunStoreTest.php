<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Executor\StepResult;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Executor\WorkflowRunStore;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
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

        $store->loadStep('step-1');
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

        $store->loadStep('step-1');
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
}
