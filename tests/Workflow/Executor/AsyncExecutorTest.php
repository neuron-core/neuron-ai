<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stubs\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\SlowImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\SlowTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\TextProcessNode;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Executor\DefaultNodeRunner;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function microtime;

class AsyncExecutorTest extends TestCase
{
    use ExecutorTestHelpers;

    private function createAsyncExecutor(): AsyncExecutor
    {
        return new AsyncExecutor(
            new DefaultNodeRunner(),
            new InMemoryPersistence(),
        );
    }

    public function testAsyncExecutorWithNormalNodes(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $executor = $this->createAsyncExecutor();
        $result = async(fn () => $this->execute($workflow, $executor))->await();

        $this->assertTrue($result->get('node_one_executed'));
        $this->assertTrue($result->get('node_two_executed'));
        $this->assertTrue($result->get('node_three_executed'));
    }

    public function testParallelBranchesRunWithDefaultExecutor(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new SlowTextProcessNode(),
                new SlowImageProcessNode(),
                new MergeNode(),
            ]);

        $start = microtime(true);
        $this->execute($workflow);
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThan(0.15, $elapsed, 'SequentialExecutor should run branches one by one');
    }

    public function testAsyncExecutorRunsBranchesConcurrently(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new SlowTextProcessNode(),
                new SlowImageProcessNode(),
                new MergeNode(),
            ]);

        $executor = $this->createAsyncExecutor();

        $start = microtime(true);
        $this->execute($workflow, $executor);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.18, $elapsed, 'AsyncExecutor should run branches concurrently');
    }

    public function testBranchStateIsIsolatedAndMerged(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $executor = $this->createAsyncExecutor();
        $result = $this->execute($workflow, $executor);

        $analysis = $result->get('analysis');
        $this->assertSame('HELLO', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }
}
