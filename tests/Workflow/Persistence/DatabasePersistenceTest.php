<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Tests\Traits\CheckOpenPort;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use PDO;
use PHPUnit\Framework\TestCase;

use function uniqid;
use function sleep;

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

        $this->pdo = new PDO('mysql:host=127.0.0.1;dbname=neuron-ai', 'root', '');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

    public function testSaveWorkflowInterrupt(): void
    {
        $workflow = \NeuronAI\Workflow\Workflow::make(
            persistence: $this->persistence,
            workflowId: $this->threadId
        )->addNodes([
            new \NeuronAI\Tests\Workflow\Stubs\NodeOne(),
            new \NeuronAI\Tests\Workflow\Stubs\InterruptableNode(),
            new \NeuronAI\Tests\Workflow\Stubs\NodeThree(),
        ]);

        try {
            $workflow->start()->getResult();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (\NeuronAI\Workflow\WorkflowInterrupt) {
            // Verify the interrupt was saved to the database
            $stmt = $this->pdo->prepare("SELECT * FROM workflow_interrupts WHERE workflow_id = :id");
            $stmt->execute(['id' => $this->threadId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->assertNotFalse($result);
            $this->assertEquals($this->threadId, $result['workflow_id']);
            $this->assertNotEmpty($result['data']);
            $this->assertNotEmpty($result['created_at']);
            $this->assertNotEmpty($result['updated_at']);
        }
    }

    public function testLoadWorkflowInterrupt(): void
    {
        $workflow = \NeuronAI\Workflow\Workflow::make(
            persistence: $this->persistence,
            workflowId: $this->threadId
        )->addNodes([
            new \NeuronAI\Tests\Workflow\Stubs\NodeOne(),
            new \NeuronAI\Tests\Workflow\Stubs\InterruptableNode(),
            new \NeuronAI\Tests\Workflow\Stubs\NodeThree(),
        ]);

        // First run - interrupt and save
        try {
            $workflow->start()->getResult();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (\NeuronAI\Workflow\WorkflowInterrupt) {
            // Expected interrupt
        }

        // Load the saved interrupt
        $loadedInterrupt = $this->persistence->load($this->threadId);

        $this->assertInstanceOf(\NeuronAI\Workflow\WorkflowInterrupt::class, $loadedInterrupt);
        $this->assertEquals(['message' => 'Need human input'], $loadedInterrupt->getData());
        $this->assertInstanceOf(\NeuronAI\Tests\Workflow\Stubs\InterruptableNode::class, $loadedInterrupt->getCurrentNode());
        $this->assertInstanceOf(\NeuronAI\Workflow\WorkflowState::class, $loadedInterrupt->getState());
    }

    public function testLoadNonExistentWorkflowThrowsException(): void
    {
        $this->expectException(\NeuronAI\Exceptions\WorkflowException::class);
        $this->expectExceptionMessage('No saved workflow found for ID: non-existent-id');

        $this->persistence->load('non-existent-id');
    }

    public function testDeleteWorkflowInterrupt(): void
    {
        $workflow = \NeuronAI\Workflow\Workflow::make(
            persistence: $this->persistence,
            workflowId: $this->threadId
        )->addNodes([
            new \NeuronAI\Tests\Workflow\Stubs\NodeOne(),
            new \NeuronAI\Tests\Workflow\Stubs\InterruptableNode(),
            new \NeuronAI\Tests\Workflow\Stubs\NodeThree(),
        ]);

        // First run - interrupt and save
        try {
            $workflow->start()->getResult();
        } catch (\NeuronAI\Workflow\WorkflowInterrupt) {
            // Expected interrupt
        }

        // Verify it was saved
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM workflow_interrupts WHERE workflow_id = :id");
        $stmt->execute(['id' => $this->threadId]);
        $this->assertEquals(1, $stmt->fetchColumn());

        // Delete the interrupt
        $this->persistence->delete($this->threadId);

        // Verify it was deleted
        $stmt->execute(['id' => $this->threadId]);
        $this->assertEquals(0, $stmt->fetchColumn());
    }

    public function testUpdateExistingWorkflowInterrupt(): void
    {
        $workflow = \NeuronAI\Workflow\Workflow::make(
            persistence: $this->persistence,
            workflowId: $this->threadId
        )->addNodes([
            new \NeuronAI\Tests\Workflow\Stubs\NodeOne(),
            new \NeuronAI\Tests\Workflow\Stubs\MultipleInterruptionsNode(),
            new \NeuronAI\Tests\Workflow\Stubs\NodeThree(),
        ]);

        // First interrupt
        try {
            $workflow->start()->getResult();
        } catch (\NeuronAI\Workflow\WorkflowInterrupt $interrupt) {
            $this->assertEquals(['count' => 1, 'message' => 'Interrupt #1'], $interrupt->getData());
        }

        // Get the first created_at timestamp
        $stmt = $this->pdo->prepare("SELECT created_at, updated_at FROM workflow_interrupts WHERE workflow_id = :id");
        $stmt->execute(['id' => $this->threadId]);
        $firstRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        $firstCreatedAt = $firstRecord['created_at'];
        $firstUpdatedAt = $firstRecord['updated_at'];

        // Sleep to ensure timestamp difference
        sleep(1);

        // Second interrupt (should update the same record)
        try {
            $workflow->start(true, 'feedback')->getResult();
        } catch (\NeuronAI\Workflow\WorkflowInterrupt $interrupt) {
            $this->assertEquals(['count' => 2, 'message' => 'Interrupt #2'], $interrupt->getData());
        }

        // Verify it updated the existing record, not created a new one
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM workflow_interrupts WHERE workflow_id = :id");
        $stmt->execute(['id' => $this->threadId]);
        $this->assertEquals(1, $stmt->fetchColumn(), 'Should have only one record (updated, not inserted)');

        // Verify created_at stayed the same but updated_at changed
        $stmt = $this->pdo->prepare("SELECT created_at, updated_at FROM workflow_interrupts WHERE workflow_id = :id");
        $stmt->execute(['id' => $this->threadId]);
        $secondRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals($firstCreatedAt, $secondRecord['created_at'], 'created_at should not change');
        $this->assertNotEquals($firstUpdatedAt, $secondRecord['updated_at'], 'updated_at should change');
    }

    public function testEndToEndWorkflowWithDatabasePersistence(): void
    {
        $initialState = new \NeuronAI\Workflow\WorkflowState(['initial_data' => 'preserved']);

        $workflow = \NeuronAI\Workflow\Workflow::make(
            state: $initialState,
            persistence: $this->persistence,
            workflowId: $this->threadId
        )->addNodes([
            new \NeuronAI\Tests\Workflow\Stubs\NodeOne(),
            new \NeuronAI\Tests\Workflow\Stubs\InterruptableNode(),
            new \NeuronAI\Tests\Workflow\Stubs\NodeThree(),
        ]);

        // First run - should interrupt
        try {
            $workflow->start()->getResult();
            $this->fail('Expected WorkflowInterrupt exception');
        } catch (\NeuronAI\Workflow\WorkflowInterrupt $interrupt) {
            $this->assertInstanceOf(\NeuronAI\Tests\Workflow\Stubs\InterruptableNode::class, $interrupt->getCurrentNode());

            // Verify state at interrupt point
            $state = $interrupt->getState();
            $this->assertEquals('preserved', $state->get('initial_data'));
            $this->assertTrue($state->get('node_one_executed'));
            $this->assertTrue($state->get('interruptable_node_executed'));
            $this->assertFalse($state->has('node_three_executed'));
        }

        // Resume with human feedback
        $finalState = $workflow->start(true, 'human feedback provided')->getResult();

        // Verify the workflow completed successfully
        $this->assertTrue($finalState->get('node_one_executed'));
        $this->assertTrue($finalState->get('interruptable_node_executed'));
        $this->assertTrue($finalState->get('node_three_executed'));
        $this->assertEquals('human feedback provided', $finalState->get('received_feedback'));
        $this->assertEquals('preserved', $finalState->get('initial_data'));
    }

    public function testSerializationOfComplexWorkflowState(): void
    {
        $complexState = new \NeuronAI\Workflow\WorkflowState([
            'string' => 'test',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'array' => ['a', 'b', 'c'],
            'nested' => ['key' => 'value', 'num' => 123],
            'null' => null,
        ]);

        $workflow = \NeuronAI\Workflow\Workflow::make(
            state: $complexState,
            persistence: $this->persistence,
            workflowId: $this->threadId
        )->addNodes([
            new \NeuronAI\Tests\Workflow\Stubs\NodeOne(),
            new \NeuronAI\Tests\Workflow\Stubs\InterruptableNode(),
            new \NeuronAI\Tests\Workflow\Stubs\NodeThree(),
        ]);

        // Interrupt the workflow
        try {
            $workflow->start()->getResult();
        } catch (\NeuronAI\Workflow\WorkflowInterrupt) {
            // Expected
        }

        // Load and verify all data types are preserved
        $loadedInterrupt = $this->persistence->load($this->threadId);
        $loadedState = $loadedInterrupt->getState();

        $this->assertEquals('test', $loadedState->get('string'));
        $this->assertEquals(42, $loadedState->get('int'));
        $this->assertEquals(3.14, $loadedState->get('float'));
        $this->assertTrue($loadedState->get('bool'));
        $this->assertEquals(['a', 'b', 'c'], $loadedState->get('array'));
        $this->assertEquals(['key' => 'value', 'num' => 123], $loadedState->get('nested'));
        $this->assertNull($loadedState->get('null'));
    }

    public function testMultipleWorkflowsCanBeSavedIndependently(): void
    {
        $workflowId1 = uniqid('workflow-1-');
        $workflowId2 = uniqid('workflow-2-');

        $persistence1 = new DatabasePersistence($this->pdo, 'workflow_interrupts');
        $persistence2 = new DatabasePersistence($this->pdo, 'workflow_interrupts');

        // Create and interrupt first workflow
        $workflow1 = \NeuronAI\Workflow\Workflow::make(
            persistence: $persistence1,
            workflowId: $workflowId1
        )->addNodes([
            new \NeuronAI\Tests\Workflow\Stubs\NodeOne(),
            new \NeuronAI\Tests\Workflow\Stubs\InterruptableNode(),
            new \NeuronAI\Tests\Workflow\Stubs\NodeThree(),
        ]);

        try {
            $workflow1->start()->getResult();
        } catch (\NeuronAI\Workflow\WorkflowInterrupt $interrupt) {
            // Expected
        }

        // Create and interrupt second workflow
        $workflow2 = \NeuronAI\Workflow\Workflow::make(
            persistence: $persistence2,
            workflowId: $workflowId2
        )->addNodes([
            new \NeuronAI\Tests\Workflow\Stubs\NodeOne(),
            new \NeuronAI\Tests\Workflow\Stubs\InterruptableNode(),
            new \NeuronAI\Tests\Workflow\Stubs\NodeThree(),
        ]);

        try {
            $workflow2->start()->getResult();
        } catch (\NeuronAI\Workflow\WorkflowInterrupt) {
            // Expected
        }

        // Verify both are saved independently
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM workflow_interrupts");
        $stmt->execute();
        $this->assertGreaterThanOrEqual(2, $stmt->fetchColumn());

        // Verify each can be loaded independently
        $loaded1 = $persistence1->load($workflowId1);
        $loaded2 = $persistence2->load($workflowId2);

        $this->assertInstanceOf(\NeuronAI\Workflow\WorkflowInterrupt::class, $loaded1);
        $this->assertInstanceOf(\NeuronAI\Workflow\WorkflowInterrupt::class, $loaded2);
        $this->assertEquals(['message' => 'Need human input'], $loaded1->getData());
        $this->assertEquals(['message' => 'Need human input'], $loaded2->getData());
    }
}
