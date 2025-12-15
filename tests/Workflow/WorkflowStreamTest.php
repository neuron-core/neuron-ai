<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

class WorkflowStreamTest extends TestCase
{
    public function testWorkflowStreaming(): void
    {
        $workflow = Workflow::make()->addNodes([
            new NodeOne(),
            new NodeTwo(),
            new NodeThree()
        ]);

        $handler = $workflow->init();

        foreach ($handler->events() as $event) {
            $this->assertInstanceOf(Event::class, $event);
        }

        $finalState = $handler->run();
        $this->assertTrue($finalState->get('node_one_executed'));
    }
}
