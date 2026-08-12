<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Scheduler\Stubs\SpyScheduler;
use NeuronAI\Tests\Workflow\Stubs\AddressedWorkflow;
use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

/**
 * Key-addressed identity: a run's durable records live in the partition named
 * by its address (the declared business key, or a generated handle), with the
 * ignition record as the generation head. One live run per address, enforced
 * by refusal; completion sweeps the whole partition, leaving nothing behind.
 */
class WorkflowAddressTest extends TestCase
{
    use ExecutorTestHelpers;

    public function testRecordsLiveUnderTheDeclaredAddress(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = AddressedWorkflow::make()->withDeclaredAddress('thread_1');

        $state = $this->execute($workflow, $persistence);

        $this->assertTrue($state->isInterrupted());
        $this->assertSame('thread_1', $workflow->getAddress());
        $this->assertNotNull($persistence->get('thread_1', '__ignition'));
        $this->assertNotNull($workflow->getRunId());
    }

    public function testBlankInstanceContinuesByAddress(): void
    {
        $persistence = new InMemoryPersistence();
        $suspended = AddressedWorkflow::make()->withDeclaredAddress('thread_1');
        $this->execute($suspended, $persistence);

        // The continuation holds the business key only — no other handle.
        $resumed = AddressedWorkflow::make()->withDeclaredAddress('thread_1');
        $state = $this->resume($resumed, $persistence, []);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('completed', $state->get('received_feedback'));
        $this->assertTrue($state->get('node_three_executed'));
        $this->assertSame($suspended->getRunId(), $resumed->getRunId());
    }

    public function testIgnitionRefusesALiveAddress(): void
    {
        $persistence = new InMemoryPersistence();
        $this->execute(AddressedWorkflow::make()->withDeclaredAddress('thread_1'), $persistence);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("A run is already in flight at address 'thread_1'");

        $this->execute(AddressedWorkflow::make()->withDeclaredAddress('thread_1'), $persistence);
    }

    public function testCompletionSweepsThePartitionAndFreesTheAddress(): void
    {
        $persistence = new InMemoryPersistence();
        $first = AddressedWorkflow::make()->withDeclaredAddress('thread_1');
        $this->execute($first, $persistence);
        $this->resume(AddressedWorkflow::make()->withDeclaredAddress('thread_1'), $persistence, []);

        // Nothing survives completion — no ignition, no orphan of any kind.
        $this->assertNull($persistence->get('thread_1', '__ignition'));

        // The address is free: a new run ignites with a fresh generation.
        $second = AddressedWorkflow::make()->withDeclaredAddress('thread_1');
        $state = $this->execute($second, $persistence);

        $this->assertTrue($state->isInterrupted());
        $this->assertSame('thread_1', $second->getAddress());
        $this->assertNotSame($first->getRunId(), $second->getRunId());
    }

    public function testResumeOnACompletedAddressThrows(): void
    {
        $persistence = new InMemoryPersistence();
        $this->execute(AddressedWorkflow::make()->withDeclaredAddress('thread_1'), $persistence);
        $this->resume(AddressedWorkflow::make()->withDeclaredAddress('thread_1'), $persistence, []);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No run in flight at address 'thread_1'");

        $this->resume(AddressedWorkflow::make()->withDeclaredAddress('thread_1'), $persistence, []);
    }

    public function testResumeOnAnUnknownAddressThrows(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No run in flight at address 'thread_unknown'");

        $this->resume(
            AddressedWorkflow::make()->withDeclaredAddress('thread_unknown'),
            new InMemoryPersistence(),
            [],
        );
    }

    public function testContinuationWithoutAnyAddressThrows(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('the workflow declares none');

        $this->resume(
            Workflow::make()->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]),
            new InMemoryPersistence(),
            [],
        );
    }

    public function testDeclaredAndExplicitAddressConflictThrows(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Mis-addressed run: the workflow declares address 'thread_1' but was given 'thread_2'");

        $this->execute(
            AddressedWorkflow::make(address: 'thread_2')->withDeclaredAddress('thread_1'),
            new InMemoryPersistence(),
        );
    }

    public function testReservedAndEmptyAddressesThrow(): void
    {
        foreach (['__reserved', ''] as $invalid) {
            try {
                $this->execute(
                    AddressedWorkflow::make()->withDeclaredAddress($invalid),
                    new InMemoryPersistence(),
                );
                $this->fail("Address '{$invalid}' should have been rejected.");
            } catch (WorkflowException $e) {
                $this->assertStringContainsString('Invalid address', $e->getMessage());
            }
        }
    }

    public function testBareResumeRevivesWithoutDeliveringAnAnswer(): void
    {
        $persistence = new InMemoryPersistence();
        $this->execute(AddressedWorkflow::make()->withDeclaredAddress('thread_1'), $persistence);

        // Null payload: the suspended step re-runs, receives nothing, and
        // re-emits its request — the run is still waiting, which is the truth.
        $revived = AddressedWorkflow::make()->withDeclaredAddress('thread_1');
        $state = $this->resume($revived, $persistence, null);

        $this->assertTrue($state->isInterrupted());
        $this->assertInstanceOf(InterruptRequest::class, $state->getInterruptRequest());
        $this->assertNull($state->get('received_feedback'));

        // The run stays continuable: an actual answer completes it.
        $state = $this->resume(
            AddressedWorkflow::make()->withDeclaredAddress('thread_1'),
            $persistence,
            [],
        );
        $this->assertFalse($state->isInterrupted());
        $this->assertSame('completed', $state->get('received_feedback'));
    }

    public function testEmptyArrayDeliversAnEmptyAnswer(): void
    {
        $persistence = new InMemoryPersistence();
        $this->execute(AddressedWorkflow::make()->withDeclaredAddress('thread_1'), $persistence);

        // [] is not null: it reaches the waiting step as a delivered answer.
        $state = $this->resume(
            AddressedWorkflow::make()->withDeclaredAddress('thread_1'),
            $persistence,
            [],
        );

        $this->assertFalse($state->isInterrupted());
        $this->assertSame('completed', $state->get('received_feedback'));
    }

    public function testGeneratedAddressIsTheContinuationHandle(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = Workflow::make()->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]);
        $this->execute($workflow, $persistence);

        $address = $workflow->getAddress();
        $this->assertNotNull($address);

        $resumed = Workflow::make($address)
            ->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]);
        $state = $this->resume($resumed, $persistence, []);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame($address, $resumed->getAddress());
    }

    public function testReplayIgnoresRecordsOfAForeignGeneration(): void
    {
        $persistence = new InMemoryPersistence();
        $suspended = AddressedWorkflow::make()->withDeclaredAddress('thread_1');
        $this->execute($suspended, $persistence);

        // Another generation takes the address: the head now names a run
        // that owns none of the stale records left in the partition.
        $persistence->put(
            'thread_1',
            '__ignition',
            (new PhpSerializer())->serialize(new Ignition('run_foreign', new StartEvent())),
        );

        // The continuation runs as the foreign generation: the stale steps
        // (completed AND interrupted markers) are invisible under its prefix,
        // so the delivered answer finds no step waiting for it and the
        // traversal re-runs from the start — re-suspending at the interrupt.
        $resumed = AddressedWorkflow::make()->withDeclaredAddress('thread_1');
        $state = $this->resume($resumed, $persistence, []);

        $this->assertSame('run_foreign', $resumed->getRunId());
        $this->assertTrue($state->isInterrupted());
        $this->assertNull($state->get('received_feedback'));
    }

    public function testZombieCompletionNeitherSweepsNorFiresOnComplete(): void
    {
        $persistence = new InMemoryPersistence();
        $scheduler = new SpyScheduler();
        $serializer = new PhpSerializer();

        // The node simulates a takeover happening mid-run: by the time this
        // run completes, the generation head names someone else.
        $hijacked = new class ($persistence, $serializer) extends Node {
            public function __construct(
                protected InMemoryPersistence $persistence,
                protected PhpSerializer $serializer,
            ) {
            }

            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                $this->persistence->put(
                    'thread_1',
                    '__ignition',
                    $this->serializer->serialize(new Ignition('run_successor', new StartEvent())),
                );

                return new StopEvent();
            }
        };

        $workflow = Workflow::make('thread_1')->addNode($hijacked);
        $state = $this->execute($workflow, $persistence, $scheduler);

        $this->assertFalse($state->isInterrupted());

        // The fenced sweep held: the successor's head record survives, the
        // zombie's own step record is left as inert garbage, and the
        // successor's coordination state was not dropped.
        $head = $serializer->unserialize((string) $persistence->get('thread_1', '__ignition'));
        $this->assertInstanceOf(Ignition::class, $head);
        $this->assertSame('run_successor', $head->runId);
        $this->assertNotNull($persistence->get('thread_1', $this->stepKey($workflow, $hijacked::class . '-0')));
        $this->assertSame([], $scheduler->onCompleteCalls);
    }

    public function testStateCarriesBothIdentities(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = AddressedWorkflow::make()->withDeclaredAddress('thread_1');

        $state = $this->execute($workflow, $persistence);

        $this->assertSame('thread_1', $state->get('__address'));
        $this->assertSame($workflow->getRunId(), $state->get('__runId'));
    }
}
