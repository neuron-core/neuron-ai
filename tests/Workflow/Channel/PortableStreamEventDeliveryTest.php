<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Workflow\Channel\Stub\PortableProgressNode;
use NeuronAI\Tests\Workflow\Channel\Stub\WorkflowProgress;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function json_decode;
use function json_encode;

class PortableStreamEventDeliveryTest extends TestCase
{
    public function test_workflow_adapter_maps_pull_output(): void
    {
        $adapter = (new VercelAIAdapter())->mapEvent(
            WorkflowProgress::class,
            static fn (WorkflowProgress $event): CustomStreamEvent => new CustomStreamEvent(
                'workflow-progress',
                ['percentage' => $event->percentage],
            ),
        );
        $workflow = Workflow::make()
            ->addNodes([new PortableProgressNode()])
            ->setStreamAdapter($adapter);

        $events = iterator_to_array($workflow->events());

        $this->assertCount(2, $events);
        $custom = json_decode(json_encode($events[0]), true);
        $this->assertSame([
            'type' => 'data-workflow-progress',
            'data' => ['percentage' => 50],
            'transient' => true,
        ], $custom);
    }

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
        $this->assertCount(1, $channel->getCompletions());
        $this->assertCount(2, $channel->getSent());

        $custom = json_decode(json_encode($channel->getSent()[0]), true);
        $finish = json_decode(json_encode($channel->getSent()[1]), true);

        $this->assertSame([
            'type' => 'data-workflow-progress',
            'data' => ['percentage' => 50],
            'transient' => true,
        ], $custom);
        $this->assertSame(['type' => 'finish'], $finish);
    }
}
