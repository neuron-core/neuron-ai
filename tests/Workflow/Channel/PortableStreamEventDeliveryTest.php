<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use Generator;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\CustomStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\VercelAIAdapter;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function substr;

class WorkflowProgress
{
    public function __construct(public readonly int $percentage)
    {
    }
}

class PortableProgressNode extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): Generator
    {
        yield new WorkflowProgress(50);

        return new StopEvent();
    }
}

class PortableStreamEventDeliveryTest extends TestCase
{
    public function test_workflow_push_delivery_maps_custom_events_without_affecting_routing(): void
    {
        $adapter = (new VercelAIAdapter())->mapEvent(
            WorkflowProgress::class,
            static fn (WorkflowProgress $event): CustomStreamEvent => new CustomStreamEvent(
                'workflow-progress',
                ['percentage' => $event->percentage],
            ),
        );
        $channel = new FakeChannel();
        $workflow = Workflow::make()
            ->addNodes([new PortableProgressNode()])
            ->setStreamAdapter($adapter)
            ->setChannel($channel);

        $state = $workflow->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertSame([], $channel->sent);
        $this->assertCount(1, $channel->completions);
        $this->assertCount(3, $channel->lines);

        $custom = json_decode(substr($channel->lines[0], 6, -2), true);
        $finish = json_decode(substr($channel->lines[1], 6, -2), true);

        $this->assertSame([
            'type' => 'data-workflow-progress',
            'data' => ['percentage' => 50],
            'transient' => true,
        ], $custom);
        $this->assertSame(['type' => 'finish'], $finish);
        $this->assertSame("data: [DONE]\n\n", $channel->lines[2]);
    }
}
