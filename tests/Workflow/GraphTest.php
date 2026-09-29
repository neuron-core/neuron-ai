<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use Countable;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\StaticConstructor;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Workflow\Stub\ExposedNode;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Tests\Workflow\Stub\SecondEvent;
use NeuronAI\Tests\Workflow\Stub\ThirdEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Graph;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GraphTest extends TestCase
{
    public function test_nodes_are_routed_by_the_event_they_handle(): void
    {
        $one = new NodeOne();
        $two = new NodeTwo();
        $three = new NodeThree();

        $graph = new Graph(new StartEvent(), new WorkflowState(), new WorkflowResources(), [$three, $one, $two]);

        $this->assertSame([
            SecondEvent::class => $three,
            StartEvent::class => $one,
            FirstEvent::class => $two,
        ], $graph->nodes());
        $this->assertSame($two, $graph->nodeFor(new FirstEvent()));
    }

    public function test_two_nodes_handling_the_same_event_are_rejected(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Node for event ' . StartEvent::class . ' already exists');

        new Graph(new StartEvent(), new WorkflowState(), new WorkflowResources(), [new NodeOne(), new ExposedNode()]);
    }

    public function test_the_start_event_must_have_a_node(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that handle ' . FirstEvent::class);

        new Graph(new FirstEvent(), new WorkflowState(), new WorkflowResources(), [new NodeOne(), new NodeThree()]);
    }

    public function test_an_event_without_a_node_is_reported_by_class(): void
    {
        $graph = new Graph(new StartEvent(), new WorkflowState(), new WorkflowResources(), [new NodeOne()]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No node found that handle event: ' . ThirdEvent::class);

        $graph->nodeFor(new ThirdEvent());
    }

    public function test_routing_is_by_exact_class_not_by_subclass(): void
    {
        $graph = new Graph(new StartEvent(), new WorkflowState(), new WorkflowResources(), [new NodeOne(), new NodeTwo()]);
        $subclassEvent = new class () extends FirstEvent {
        };

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No node found that handle event: ' . $subclassEvent::class);

        $graph->nodeFor($subclassEvent);
    }

    public function test_the_graph_exposes_the_resources_its_nodes_share(): void
    {
        $resources = new WorkflowResources();

        $graph = new Graph(new StartEvent(), new WorkflowState(), $resources, [new NodeOne()]);

        $this->assertSame($resources, $graph->resources);
    }

    public function test_global_middleware_come_first_then_every_matching_registration_in_order(): void
    {
        $global = FakeMiddleware::make();
        $forInterface = FakeMiddleware::make();
        $forParent = FakeMiddleware::make();
        $forSibling = FakeMiddleware::make();
        $forChild = FakeMiddleware::make();
        $child = new class () extends NodeOne {
        };

        $graph = new Graph(new StartEvent(), new WorkflowState(), new WorkflowResources(), [new NodeOne()], [
            NodeInterface::class => [$forInterface],
            NodeOne::class => [$forParent],
            NodeTwo::class => [$forSibling],
            $child::class => [$forChild],
        ], [$global]);

        $this->assertSame([$global, $forInterface, $forParent, $forChild], $graph->middlewareFor($child));
        $this->assertSame([$global, $forInterface, $forParent], $graph->middlewareFor(new NodeOne()));
        $this->assertSame([$global, $forInterface, $forSibling], $graph->middlewareFor(new NodeTwo()));
    }

    /** @return array<string, array{string}> */
    public static function keysThatAreNotNodes(): array
    {
        return [
            'misspelled class' => ['NeuronAI\Tests\Workflow\Stub\NodeOen'],
            'event class' => [FirstEvent::class],
            'trait' => [StaticConstructor::class],
            'unrelated interface' => [Countable::class],
        ];
    }

    #[DataProvider('keysThatAreNotNodes')]
    public function test_middleware_for_a_key_that_is_not_a_node_class_is_rejected(string $key): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Middleware is registered for '{$key}', which is not a node class.");

        new Graph(new StartEvent(), new WorkflowState(), new WorkflowResources(), [new NodeOne()], [$key => [FakeMiddleware::make()]]);
    }

    public function test_a_node_without_middleware_gets_none(): void
    {
        $graph = new Graph(new StartEvent(), new WorkflowState(), new WorkflowResources(), [new NodeOne()]);

        $this->assertSame([], $graph->middlewareFor(new NodeOne()));
    }
}
