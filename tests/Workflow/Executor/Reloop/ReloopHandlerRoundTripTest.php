<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Reloop;

use NeuronAI\Tests\Workflow\Executor\Stubs\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\TextProcessNode;
use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Executor\Amp\AsyncExecutor;
use NeuronAI\Workflow\Executor\Reloop\ReloopStepEngine;
use NeuronAI\Workflow\Executor\Reloop\ReloopWorkflowHandler;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;
use Reloop\Client;
use Reloop\Event;
use Reloop\Task;

class ReloopHandlerRoundTripTest extends TestCase
{
    use ReplaysReloopSteps;

    public function testLinearWorkflowThroughHandler(): void
    {
        $workflow = Workflow::make()
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $client = new Client(
            appName: 'test-app',
            serveUrl: 'https://example.com/api/reloop',
            apiKey: 'test-key',
        );

        $client->register(new Task(
            id: 'linear-workflow',
            triggers: [new Event(name: 'test/trigger')],
            handler: new ReloopWorkflowHandler($workflow),
        ));

        $this->replayThroughHandler($client, 'linear-workflow');

        $state = $workflow->resolveState();
        $this->assertTrue($state->get('node_one_executed'));
        $this->assertTrue($state->get('node_two_executed'));
        $this->assertTrue($state->get('node_three_executed'));
    }

    public function testParallelWorkflowThroughHandler(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $client = new Client(
            appName: 'test-app',
            serveUrl: 'https://example.com/api/reloop',
            apiKey: 'test-key',
        );

        $client->register(new Task(
            id: 'parallel-workflow',
            triggers: [new Event(name: 'test/trigger')],
            handler: new ReloopWorkflowHandler(
                $workflow,
                executorFactory: fn (ReloopStepEngine $engine): AsyncExecutor => new AsyncExecutor($engine),
            ),
        ));

        $this->replayThroughHandler($client, 'parallel-workflow');

        $analysis = $workflow->resolveState()->get('analysis');
        $this->assertSame('HELLO', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function testInterruptResumeThroughHandler(): void
    {
        $workflow = Workflow::make()
            ->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]);

        $client = new Client(
            appName: 'test-app',
            serveUrl: 'https://example.com/api/reloop',
            apiKey: 'test-key',
        );

        $client->register(new Task(
            id: 'interrupt-workflow',
            triggers: [new Event(name: 'test/trigger')],
            handler: new ReloopWorkflowHandler($workflow),
        ));

        $this->replayThroughHandler(
            $client,
            'interrupt-workflow',
            resumeRequest: new ApprovalRequest('approved'),
        );

        $state = $workflow->resolveState();
        $this->assertTrue($state->get('node_one_executed'));
        $this->assertTrue($state->get('interruptable_node_executed'));
        $this->assertSame('completed', $state->get('received_feedback'));
        $this->assertTrue($state->get('node_three_executed'));
    }
}
