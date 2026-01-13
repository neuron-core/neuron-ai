<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Traits\CheckOpenPort;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;
use PDO;
use PHPUnit\Framework\TestCase;

use function uniqid;

class DatabasePersistenceTest extends TestCase
{
    use CheckOpenPort;
    protected PDO $pdo;
    protected string $threadId;

    protected PersistenceInterface $persistence;

    public function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', 3306)) {
            $this->markTestSkipped("MySQL not available on port 3306. Skipping test.");
        }

        $this->pdo = new PDO('mysql:host=127.0.0.1;dbname=neuron-ai', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS workflow_interrupts (
            workflow_id VARCHAR(255) PRIMARY KEY,
            data LONGBLOB NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,

            INDEX idx_workflow_id (workflow_id),
            INDEX idx_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $this->threadId = uniqid('test-thread-');

        $this->persistence = new DatabasePersistence($this->pdo, 'workflow_interrupts');
    }

    public function tearDown(): void
    {
        // Clean up test data
        if (isset($this->pdo)) {
            $this->pdo->exec("TRUNCATE TABLE workflow_interrupts");
        }
    }

    public function testSaveAndLoadWorkflowInterrupt(): void
    {
        $workflowId = $this->threadId . '-save-load';
        $interrupt = $this->createTestInterrupt();

        // Save the interrupt
        $this->persistence->save($workflowId, $interrupt);

        // Load it back
        $loadedInterrupt = $this->persistence->load($workflowId);

        // Verify the data matches
        $this->assertInstanceOf(WorkflowInterrupt::class, $loadedInterrupt);
        $this->assertEquals($interrupt->getMessage(), $loadedInterrupt->getMessage());
        $this->assertEquals(
            $interrupt->getRequest()->getMessage(),
            $loadedInterrupt->getRequest()->getMessage()
        );
        $this->assertCount(2, $loadedInterrupt->getRequest()->getActions());
    }

    public function testUpdateExistingWorkflowInterrupt(): void
    {
        $workflowId = $this->threadId . '-update';

        // Save the first interrupt
        $this->persistence->save($workflowId, $this->createTestInterrupt('First message'));

        // Update with new interrupt
        $this->persistence->save($workflowId, $this->createTestInterrupt('Second message'));

        // Load and verify it was updated
        $loadedInterrupt = $this->persistence->load($workflowId);

        $this->assertEquals('Second message', $loadedInterrupt->getRequest()->getMessage());
    }

    public function testDeleteWorkflowInterrupt(): void
    {
        $workflowId = $this->threadId . '-delete';
        $interrupt = $this->createTestInterrupt();

        // Save and verify it exists
        $this->persistence->save($workflowId, $interrupt);
        $this->assertInstanceOf(WorkflowInterrupt::class, $this->persistence->load($workflowId));

        // Delete it
        $this->persistence->delete($workflowId);

        // Verify it throws an exception when trying to load deleted workflow
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No saved workflow found for ID: {$workflowId}");
        $this->persistence->load($workflowId);
    }

    public function testLoadNonExistentWorkflowThrowsException(): void
    {
        $workflowId = $this->threadId . '-nonexistent';

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No saved workflow found for ID: {$workflowId}");

        $this->persistence->load($workflowId);
    }

    public function testSavePreservesAllInterruptData(): void
    {
        $workflowId = $this->threadId . '-preserve-data';
        $state = new WorkflowState([
            'key1' => 'value1',
            'key2' => 42,
            'key3' => ['nested' => 'array'],
        ]);

        $interrupt = $this->createTestInterruptWithState($state);

        $this->persistence->save($workflowId, $interrupt);
        $loadedInterrupt = $this->persistence->load($workflowId);

        // Verify state was preserved
        $loadedState = $loadedInterrupt->getState();
        $this->assertEquals('value1', $loadedState->get('key1'));
        $this->assertEquals(42, $loadedState->get('key2'));
        $this->assertEquals(['nested' => 'array'], $loadedState->get('key3'));

        // Verify actions were preserved
        $actions = $loadedInterrupt->getRequest()->getActions();
        $this->assertCount(2, $actions);
        $this->assertEquals('action_1', $actions[0]->id);
        $this->assertEquals('Execute Command', $actions[0]->name);
    }

    public function testSaveAndLoadWithApprovedActions(): void
    {
        $workflowId = $this->threadId . '-approved-actions';
        $interrupt = $this->createTestInterrupt();

        // Approve one action
        $actions = $interrupt->getRequest()->getActions();
        $actions[0]->approve('Looks good');

        $this->persistence->save($workflowId, $interrupt);
        $loadedInterrupt = $this->persistence->load($workflowId);

        // Verify action decisions were preserved
        $loadedActions = $loadedInterrupt->getRequest()->getActions();
        $this->assertTrue($loadedActions[0]->isApproved());
        $this->assertEquals('Looks good', $loadedActions[0]->feedback);
        $this->assertTrue($loadedActions[1]->isPending());
    }

    public function testSaveAndLoadWithRejectedActions(): void
    {
        $workflowId = $this->threadId . '-rejected-actions';
        $interrupt = $this->createTestInterrupt();

        // Reject one action
        $actions = $interrupt->getRequest()->getActions();
        $actions[1]->reject('Too dangerous');

        $this->persistence->save($workflowId, $interrupt);
        $loadedInterrupt = $this->persistence->load($workflowId);

        // Verify action decisions were preserved
        $loadedActions = $loadedInterrupt->getRequest()->getActions();
        $this->assertTrue($loadedActions[1]->isRejected());
        $this->assertEquals('Too dangerous', $loadedActions[1]->feedback);
    }

    public function testMultipleWorkflowsAreIndependent(): void
    {
        $workflowId1 = $this->threadId . '-workflow-1';
        $workflowId2 = $this->threadId . '-workflow-2';

        $interrupt1 = $this->createTestInterrupt('Workflow 1 message');
        $interrupt2 = $this->createTestInterrupt('Workflow 2 message');

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

    public function testCustomTableName(): void
    {
        // Create a custom table
        $customTable = 'custom_workflow_table_' . uniqid();
        $this->pdo->exec("CREATE TABLE {$customTable} (
            workflow_id VARCHAR(255) PRIMARY KEY,
            data LONGBLOB NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $customPersistence = new DatabasePersistence($this->pdo, $customTable);
        $workflowId = $this->threadId . '-custom-table';
        $interrupt = $this->createTestInterrupt();

        $customPersistence->save($workflowId, $interrupt);
        $customPersistence->load($workflowId);

        $this->expectNotToPerformAssertions();

        // Cleanup
        $this->pdo->exec("DROP TABLE {$customTable}");
    }

    /**
     * Helper method to create a test WorkflowInterrupt.
     */
    private function createTestInterrupt(string $message = 'Test interrupt message'): WorkflowInterrupt
    {
        $state = new WorkflowState(['test_key' => 'test_value']);
        return $this->createTestInterruptWithState($state, $message);
    }

    /**
     * Helper method to create a test WorkflowInterrupt with specific state.
     */
    private function createTestInterruptWithState(
        WorkflowState $state,
        string $message = 'Test interrupt message'
    ): WorkflowInterrupt {
        $request = new InterruptRequest(
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
