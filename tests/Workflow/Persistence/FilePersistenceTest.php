<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function uniqid;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

class FilePersistenceTest extends TestCase
{
    protected string $tmpDir;
    protected string $workflowId;
    protected FilePersistence $persistence;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuron_test_' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
        $this->workflowId = uniqid('test-wf-');
        $this->persistence = new FilePersistence($this->tmpDir);
    }

    protected function tearDown(): void
    {
        // Clean up any files created during the test
        $files = glob($this->tmpDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testConstructorThrowsIfDirectoryDoesNotExist(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Directory '/nonexistent/path' does not exist");

        new FilePersistence('/nonexistent/path');
    }

    public function testSaveAndLoadWorkflowInterrupt(): void
    {
        $interrupt = $this->createTestInterrupt();

        $this->persistence->save($this->workflowId, $interrupt);
        $loaded = $this->persistence->load($this->workflowId);

        $this->assertInstanceOf(WorkflowInterrupt::class, $loaded);
        $this->assertEquals($interrupt->getMessage(), $loaded->getMessage());
        $this->assertEquals(
            $interrupt->getRequest()->getMessage(),
            $loaded->getRequest()->getMessage()
        );
        /** @var ApprovalRequest $loadedRequest */
        $loadedRequest = $loaded->getRequest();
        $this->assertCount(2, $loadedRequest->getActions());
    }

    public function testUpdateExistingWorkflowInterrupt(): void
    {
        $this->persistence->save($this->workflowId, $this->createTestInterrupt('First message'));
        $this->persistence->save($this->workflowId, $this->createTestInterrupt('Second message'));

        $loaded = $this->persistence->load($this->workflowId);
        $this->assertEquals('Second message', $loaded->getRequest()->getMessage());
    }

    public function testDeleteWorkflowInterrupt(): void
    {
        $this->persistence->save($this->workflowId, $this->createTestInterrupt());
        $this->assertInstanceOf(WorkflowInterrupt::class, $this->persistence->load($this->workflowId));

        $this->persistence->delete($this->workflowId);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No saved workflow found for ID: {$this->workflowId}.");
        $this->persistence->load($this->workflowId);
    }

    public function testDeleteNonExistentIsNoOp(): void
    {
        // Should not throw — delete of a missing file is a no-op
        $this->persistence->delete($this->workflowId);
        $this->assertFalse(is_file($this->tmpDir . '/neuron_workflow_' . $this->workflowId.'.store'));
    }

    public function testLoadNonExistentWorkflowThrowsException(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("No saved workflow found for ID: {$this->workflowId}.");

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
        $loaded = $this->persistence->load($this->workflowId);

        $loadedState = $loaded->getState();
        $this->assertEquals('value1', $loadedState->get('key1'));
        $this->assertEquals(42, $loadedState->get('key2'));
        $this->assertEquals(['nested' => 'array'], $loadedState->get('key3'));

        /** @var ApprovalRequest $loadedRequest */
        $loadedRequest = $loaded->getRequest();
        $actions = $loadedRequest->getActions();
        $this->assertCount(2, $actions);
        $this->assertEquals('action_1', $actions[0]->id);
    }

    public function testSaveAndLoadWithApprovedActions(): void
    {
        $interrupt = $this->createTestInterrupt();
        /** @var ApprovalRequest $request */
        $request = $interrupt->getRequest();
        $actions = $request->getActions();
        $actions[0]->approve('Looks good');

        $this->persistence->save($this->workflowId, $interrupt);
        $loaded = $this->persistence->load($this->workflowId);

        /** @var ApprovalRequest $loadedRequest */
        $loadedRequest = $loaded->getRequest();
        $loadedActions = $loadedRequest->getActions();
        $this->assertTrue($loadedActions[0]->isApproved());
        $this->assertEquals('Looks good', $loadedActions[0]->feedback);
        $this->assertTrue($loadedActions[1]->isPending());
    }

    public function testMultipleWorkflowsAreIndependent(): void
    {
        $id1 = $this->workflowId . '-wf-1';
        $id2 = $this->workflowId . '-wf-2';

        $this->persistence->save($id1, $this->createTestInterrupt('Workflow 1', $id1));
        $this->persistence->save($id2, $this->createTestInterrupt('Workflow 2', $id2));

        $loaded1 = $this->persistence->load($id1);
        $loaded2 = $this->persistence->load($id2);

        $this->assertEquals('Workflow 1', $loaded1->getRequest()->getMessage());
        $this->assertEquals('Workflow 2', $loaded2->getRequest()->getMessage());

        $this->persistence->delete($id1);

        $this->expectException(WorkflowException::class);
        $this->persistence->load($id1);
    }

    public function testCustomPrefixAndExtension(): void
    {
        $persistence = new FilePersistence($this->tmpDir, 'custom_', '.dat');

        $interrupt = $this->createTestInterrupt();
        $persistence->save($this->workflowId, $interrupt);

        // Verify the file was created with the custom prefix and extension
        $expectedFile = $this->tmpDir . '/custom_' . $this->workflowId . '.dat';
        $this->assertFileExists($expectedFile);

        // Verify it loads correctly
        $loaded = $persistence->load($this->workflowId);
        $this->assertEquals($interrupt->getMessage(), $loaded->getMessage());
    }

    private function createTestInterrupt(string $message = 'Test interrupt message', ?string $id = null): WorkflowInterrupt
    {
        $state = new WorkflowState(['test_key' => 'test_value', '__workflowId' => $id ?? $this->workflowId]);
        return $this->createTestInterruptWithState($state, $message);
    }

    private function createTestInterruptWithState(WorkflowState $state, string $message = 'Test interrupt message'): WorkflowInterrupt
    {
        $request = new ApprovalRequest($message, [
            new Action('action_1', 'Execute Command', 'Execute a potentially dangerous command'),
            new Action('action_2', 'Delete File', 'Delete an important file'),
        ]);

        $node = new \NeuronAI\Tests\Workflow\Stubs\InterruptableNode();
        $event = new \NeuronAI\Workflow\Events\StartEvent();

        return new WorkflowInterrupt($request, $node, $state, $event);
    }
}
