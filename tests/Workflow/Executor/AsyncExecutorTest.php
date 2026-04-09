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
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function microtime;

class AsyncExecutorTest extends TestCase
{
    public function testAsyncExecutorWithNormalNodes(): void
    {
        $workflow = Workflow::make()
            ->setExecutor(new AsyncExecutor())
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $result = async(fn () => $workflow->init()->run())->await();

        $this->assertTrue($result->get('node_one_executed'));
        $this->assertTrue($result->get('node_two_executed'));
        $this->assertTrue($result->get('node_three_executed'));
    }

    public function testParallelBranchesRunWithDefaultExecutor(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new SlowTextProcessNode(),   // 0.1 s delay
                new SlowImageProcessNode(),  // 0.1 s delay
                new MergeNode(),
            ]);

        $start = microtime(true);
        $workflow->init()->run();
        $elapsed = microtime(true) - $start;

        // Sequential: branches run one after another, so total ≈ 0.2 s
        $this->assertGreaterThan(0.15, $elapsed, 'SequentialExecutor should run branches one by one');
    }

    public function testAsyncExecutorRunsBranchesConcurrently(): void
    {
        $workflow = Workflow::make()
            ->setExecutor(new AsyncExecutor())
            ->addNodes([
                new DocumentParallelProcessing(),
                new SlowTextProcessNode(),   // 0.1 s delay
                new SlowImageProcessNode(),  // 0.1 s delay
                new MergeNode(),
            ]);

        $start = microtime(true);
        $workflow->init()->run();
        $elapsed = microtime(true) - $start;

        // Concurrent: both branches run in parallel, so total ≈ 0.1 s
        $this->assertLessThan(0.18, $elapsed, 'AsyncExecutor should run branches concurrently');
    }

    public function testBranchStateIsIsolatedAndMerged(): void
    {
        $workflow = Workflow::make()
            ->setExecutor(new AsyncExecutor())
            ->addNodes([
                new DocumentParallelProcessing(),
                new TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $workflow->init()->run();

        // Each branch's state changes land under branches.{branchId}.*
        $this->assertSame('HELLO', $result->get('branches.text.processedText'));
        $this->assertSame('processed_image.jpg', $result->get('branches.image.processedImage'));

        // merge() combines them into the analysis key
        $analysis = $result->get('analysis');
        $this->assertSame('HELLO', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }
}
