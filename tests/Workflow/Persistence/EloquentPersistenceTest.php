<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\EloquentPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function uniqid;

class EloquentPersistenceTest extends TestCase
{
    protected string $workflowId;

    protected PersistenceInterface $persistence;

    protected function setUp(): void
    {
        $manager = new Manager();
        $manager->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $manager->setAsGlobal();
        $manager->bootEloquent();

        Manager::schema()->create('workflow_interrupts', function (Blueprint $table) {
            $table->id();
            $table->string('workflow_id')->unique();
            $table->longText('interrupt')->charset('binary');
            $table->timestamps();
        });

        $this->workflowId = uniqid('test-thread-');
        $this->persistence = new EloquentPersistence(WorkflowInterruptModel::class);
    }

    protected function tearDown(): void
    {
        Manager::schema()->dropIfExists('workflow_interrupts');
    }

    public function testSaveAndLoadWorkflowInterrupt(): void
    {
        $interrupt = $this->createTestInterrupt();

        // Save the interrupt
        $this->persistence->save($this->workflowId, $interrupt);

        // Load it back
        $loadedInterrupt = $this->persistence->load($this->workflowId);

        // Verify the data matches
        $this->assertInstanceOf(WorkflowInterrupt::class, $loadedInterrupt);
        $this->assertEquals($interrupt->getMessage(), $loadedInterrupt->getMessage());
        $this->assertEquals(
            $interrupt->getRequest()->getMessage(),
            $loadedInterrupt->getRequest()->getMessage()
        );
        /** @var ApprovalRequest $loadedRequest */
        $loadedRequest = $loadedInterrupt->getRequest();
        $this->assertCount(2, $loadedRequest->getActions());
    }

    public function testUpdateExistingWorkflowInterrupt(): void
    {
        // Save the first interrupt
        $this->persistence->save($this->workflowId, $this->createTestInterrupt('First message'));

        // Update with new interrupt
        $this->persistence->save($this->workflowId, $this->createTestInterrupt('Second message'));

        // Load and verify it was updated
        $loadedInterrupt = $this->persistence->load($this->workflowId);

        $this->assertEquals('Second message', $loadedInterrupt->getRequest()->getMessage());
    }

    public function testDeleteWorkflowInterrupt(): void
    {
        $interrupt = $this->createTestInterrupt();

        // Save and verify it exists
        $this->persistence->save($this->workflowId, $interrupt);
        $this->assertInstanceOf(WorkflowInterrupt::class, $this->persistence->load($this->workflowId));

        // Delete it
        $this->persistence->delete($this->workflowId);

        // Verify it throws an exception when trying to load deleted workflow
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No saved workflow found for ID: {$this->workflowId}");
        $this->persistence->load($this->workflowId);
    }

    public function testLoadNonExistentWorkflowThrowsException(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No saved workflow found for ID: {$this->workflowId}");

        $this->persistence->load($this->workflowId);
    }

    public function testSavePreservesAllInterruptData(): void
    {
        $state = new WorkflowState([
            'key1' => 'value1',
            'key2' => 42,
            'key3' => ['nested' => 'array'],
            '__workflowId' => $this->workflowId,
        ]);

        $interrupt = $this->createTestInterruptWithState($state);

        $this->persistence->save($this->workflowId, $interrupt);
        $loadedInterrupt = $this->persistence->load($this->workflowId);

        // Verify state was preserved
        $loadedState = $loadedInterrupt->getState();
        $this->assertEquals('value1', $loadedState->get('key1'));
        $this->assertEquals(42, $loadedState->get('key2'));
        $this->assertEquals(['nested' => 'array'], $loadedState->get('key3'));

        // Verify actions were preserved
        /** @var ApprovalRequest $loadedRequest */
        $loadedRequest = $loadedInterrupt->getRequest();
        $actions = $loadedRequest->getActions();
        $this->assertCount(2, $actions);
        $this->assertEquals('action_1', $actions[0]->id);
        $this->assertEquals('Execute Command', $actions[0]->name);
    }

    public function testSaveAndLoadWithApprovedActions(): void
    {
        $interrupt = $this->createTestInterrupt();

        // Approve one action
        /** @var ApprovalRequest $request */
        $request = $interrupt->getRequest();
        $actions = $request->getActions();
        $actions[0]->approve('Looks good');

        $this->persistence->save($this->workflowId, $interrupt);
        $loadedInterrupt = $this->persistence->load($this->workflowId);

        // Verify action decisions were preserved
        /** @var ApprovalRequest $loadedRequest */
        $loadedRequest = $loadedInterrupt->getRequest();
        $loadedActions = $loadedRequest->getActions();
        $this->assertTrue($loadedActions[0]->isApproved());
        $this->assertEquals('Looks good', $loadedActions[0]->feedback);
        $this->assertTrue($loadedActions[1]->isPending());
    }

    public function testSaveAndLoadWithRejectedActions(): void
    {
        $interrupt = $this->createTestInterrupt();

        // Reject one action
        /** @var ApprovalRequest $request */
        $request = $interrupt->getRequest();
        $actions = $request->getActions();
        $actions[1]->reject('Too dangerous');

        $this->persistence->save($this->workflowId, $interrupt);
        $loadedInterrupt = $this->persistence->load($this->workflowId);

        // Verify action decisions were preserved
        /** @var ApprovalRequest $loadedRequest */
        $loadedRequest = $loadedInterrupt->getRequest();
        $loadedActions = $loadedRequest->getActions();
        $this->assertTrue($loadedActions[1]->isRejected());
        $this->assertEquals('Too dangerous', $loadedActions[1]->feedback);
    }

    public function testMultipleWorkflowsAreIndependent(): void
    {
        $workflowId1 = $this->workflowId . '-workflow-1';
        $workflowId2 = $this->workflowId . '-workflow-2';

        $interrupt1 = $this->createTestInterrupt('Workflow 1 message', $workflowId1);
        $interrupt2 = $this->createTestInterrupt('Workflow 2 message', $workflowId2);

        // Save both
        $this->persistence->save($workflowId1, $interrupt1);
        $this->persistence->save($workflowId2, $interrupt2);

        // Load and verify they're independent
        $loaded1 = $this->persistence->load($workflowId1);
        $loaded2 = $this->persistence->load($workflowId2);

        $this->assertEquals('Workflow 1 message', $loaded1->getRequest()->getMessage());
        $this->assertEquals('Workflow 2 message', $loaded2->getRequest()->getMessage());

        // Delete one shouldn't affect the other
        $this->persistence->delete($workflowId1);

        $this->expectException(WorkflowException::class);
        $this->persistence->load($workflowId1);

        // Workflow 2 should still exist
        $loaded2Again = $this->persistence->load($workflowId2);
        $this->assertEquals('Workflow 2 message', $loaded2Again->getRequest()->getMessage());
    }

    /**
     * Helper method to create a test WorkflowInterrupt.
     */
    private function createTestInterrupt(string $message = 'Test interrupt message', ?string $id = null): \NeuronAI\Workflow\Interrupt\WorkflowInterrupt
    {
        $state = new WorkflowState(['test_key' => 'test_value', '__workflowId' => $id ?? $this->workflowId]);
        return $this->createTestInterruptWithState($state, $message);
    }

    /**
     * Helper method to create a test WorkflowInterrupt with specific state.
     */
    private function createTestInterruptWithState(
        WorkflowState $state,
        string $message = 'Test interrupt message'
    ): WorkflowInterrupt {
        $request = new ApprovalRequest(
            $message,
            [
                new Action(
                    'action_1',
                    'Execute Command',
                    'Execute a potentially dangerous command'
                ),
                new Action(
                    'action_2',
                    'Delete File',
                    'Delete an important file'
                ),
            ]
        );

        $node = new \NeuronAI\Tests\Workflow\Stubs\InterruptableNode();
        $event = new \NeuronAI\Workflow\Events\StartEvent();

        return new WorkflowInterrupt($request, $node, $state, $event);
    }
}

/**
 * Mock Eloquent Model for testing
 *
 * @property string $workflow_id
 * @property string $interrupt
 */
class WorkflowInterruptModel extends Model
{
    protected $table = 'workflow_interrupts';
    protected $fillable = ['workflow_id', 'interrupt'];
}
