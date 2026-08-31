<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

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
    public function testInitializesAndLoadsOneRunPartition(): void
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

    public function testRejectsASecondInitialization(): void
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

    public function testReplacesControlAndWritesRecordsAtomically(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $store = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $store->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );

        $suspended = new WorkflowControl('run-1', WorkflowStatus::Suspended);
        $step = new StepResult('step-1', state: null);
        $store->replaceControl($suspended, ['run-1/step-1' => $step]);

        $this->assertSame($suspended, $store->control());
        $this->assertEquals($step, $store->readRecord('run-1/step-1'));
    }

    public function testStaleStoreCannotWriteAfterAnotherStoreChangesControl(): void
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

        $stale->writeRecords(['run-1/step-1' => new StepResult('step-1')]);
    }

    public function testMemoizerWritesRequireTheCurrentControlValue(): void
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
        $memoizer = $stale->memoizer('run-1/step-1');
        $owner->replaceControl(new WorkflowControl('run-1', WorkflowStatus::Suspended));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Stale execution attempt 1');

        $memoizer->memo('provider', fn (): string => 'result');
    }

    public function testExistingMemoizerUsesTheStoresLatestControlSnapshot(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $store = new WorkflowRunStore($persistence, $serializer, 'workflow-1');
        $store->initialize(
            new WorkflowControl('run-1', WorkflowStatus::Running),
            new Ignition('run-1', new StartEvent()),
        );
        $memoizer = $store->memoizer('run-1/step-1');

        $store->replaceControl(new WorkflowControl('run-1', WorkflowStatus::Suspended));

        $this->assertSame('result', $memoizer->memo('provider', fn (): string => 'result'));
        $this->assertSame('result', $memoizer->memo('provider', fn (): string => 'must-not-run'));
    }

    public function testDeletesOnlyTheOwnedPartition(): void
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
