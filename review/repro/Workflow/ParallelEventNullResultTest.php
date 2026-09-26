<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Events;

use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class ParallelEventNullResultTest extends TestCase
{
    public function test_a_branch_that_completed_with_a_null_result_has_a_result(): void
    {
        $event = new ParallelEvent(['left' => new StartEvent()]);

        $event->setResult('left', null);

        $this->assertArrayHasKey('left', $event->getAllResults());
        $this->assertTrue($event->hasResult('left'));
    }

    public function test_join_node_sees_every_completed_branch_including_those_without_a_result(): void
    {
        $workflow = Workflow::make()->addNodes([
            new DocumentParallelProcessing(),
            new class () extends Node {
                public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
                {
                    return new StopEvent();
                }
            },
            new class () extends Node {
                public function __invoke(ImageProcessEvent $event, WorkflowState $state): StopEvent
                {
                    return new StopEvent(result: 'image');
                }
            },
            new class () extends Node {
                public function __invoke(DocumentParallelEvent $event, WorkflowState $state): StopEvent
                {
                    $state->set('completed', [
                        'text' => $event->hasResult('text'),
                        'image' => $event->hasResult('image'),
                    ]);
                    return new StopEvent();
                }
            },
        ]);

        $state = $workflow->run();

        $this->assertSame(['text' => true, 'image' => true], $state->get('completed'));
    }
}
