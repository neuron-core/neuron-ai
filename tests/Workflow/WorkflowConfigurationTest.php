<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Channel\Stub\ChunkStreamingNode;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Tests\Workflow\Stub\RecordingEventDispatcher;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Exporter\ExporterInterface;
use NeuronAI\Workflow\Exporter\WorkflowGraph;
use NeuronAI\Workflow\Exporter\WorkflowGraphVertex;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\Serializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_map;
use function array_unique;
use function is_a;
use function array_values;
use function iterator_to_array;
use function serialize;
use function unserialize;

class WorkflowConfigurationTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function retentionPolicies(): iterable
    {
        yield 'retained to automatic cleanup' => [true];
        yield 'automatic cleanup to retained' => [false];
    }

    #[DataProvider('retentionPolicies')]
    public function test_completion_policy_changes_apply_to_the_next_segment(bool $retain): void
    {
        $workflow = Workflow::make('order')->addNode(new ChunkStreamingNode(2))
            ->retainCompletionUntilAcknowledged($retain);
        $stream = $workflow->events();
        $stream->rewind();
        $workflow->retainCompletionUntilAcknowledged(!$retain);
        iterator_to_array($stream);

        self::assertSame($retain, $workflow->inspect() instanceof \NeuronAI\Workflow\WorkflowRunSnapshot);
        if ($retain) {
            $workflow->acknowledge($stream->getReturn()->getRunId());
        }
        $workflow->run();
        self::assertSame(!$retain, $workflow->inspect() instanceof \NeuronAI\Workflow\WorkflowRunSnapshot);
    }

    public function test_storage_and_serializer_changes_apply_to_the_next_execution(): void
    {
        $originalStore = new InMemoryPersistence();
        $nextStore = new InMemoryPersistence();
        $serializations = 0;
        $serializer = $this->createMock(Serializer::class);
        $serializer->method('serialize')->willReturnCallback(function (mixed $value) use (&$serializations): string {
            $serializations++;
            return serialize($value);
        });
        $serializer->method('unserialize')->willReturnCallback(static fn (string $value): mixed => unserialize($value));
        $workflow = Workflow::make('order')->addNode(new ChunkStreamingNode(2))
            ->setPersistence($originalStore)->retainCompletionUntilAcknowledged();
        $stream = $workflow->events();
        $stream->rewind();
        $workflow->setPersistence($nextStore)->setSerializer($serializer)->setLeaseTimeout(30)->setBranchRunner(new AsyncBranchRunner());
        iterator_to_array($stream);

        self::assertSame(0, $serializations);
        self::assertSame($stream->getReturn()->getRunId(), Workflow::make('order')->setPersistence($originalStore)->inspect()->runId);
        self::assertNull($workflow->inspect());

        $state = $workflow->run();
        self::assertGreaterThan(0, $serializations);
        self::assertSame($state->getRunId(), $workflow->inspect()->runId);
        self::assertNotSame($stream->getReturn()->getRunId(), $state->getRunId());
    }

    public function test_graph_state_and_middleware_changes_apply_to_the_next_execution(): void
    {
        $middleware = $this->createMock(WorkflowMiddleware::class);
        $middleware->method('after')->willReturnCallback(static function (NodeInterface $node, Event $event, WorkflowState $state): void {
            $state->set('middleware', true);
        });
        $workflow = Workflow::make('order', new WorkflowState(['seed' => 'original']))
            ->addNode(new ChunkStreamingNode(2));
        $stream = $workflow->events();
        $stream->rewind();
        $workflow->setState(new WorkflowState(['seed' => 'next']))
            ->setStartEvent(new FirstEvent('Next input'))
            ->addNodes([new NodeTwo(), new NodeThree()])
            ->addGlobalMiddleware(fn (): \PHPUnit\Framework\MockObject\MockObject => $middleware)
            ->addMiddleware(NodeThree::class, fn (): \PHPUnit\Framework\MockObject\MockObject => $middleware);
        iterator_to_array($stream);

        self::assertSame('original', $stream->getReturn()->get('seed'));
        self::assertNull($stream->getReturn()->get('middleware'));
        self::assertNull($stream->getReturn()->get('node_two_executed'));

        $state = $workflow->run();
        self::assertSame('next', $state->get('seed'));
        self::assertSame('Next input', $state->get('first_message'));
        self::assertTrue($state->get('middleware'));
        self::assertTrue($state->get('node_three_executed'));
    }

    public function test_listener_and_dispatcher_changes_apply_to_the_next_segment(): void
    {
        $originalEnds = [];
        $nextEnds = [];
        $subscribedEnds = [];
        $originalDispatcher = $this->createMock(EventDispatcherInterface::class);
        $originalDispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$originalEnds): object {
            if ($event instanceof WorkflowEnd) {
                $originalEnds[] = $event->execution->runId;
            }
            return $event;
        });
        $nextDispatcher = $this->createMock(EventDispatcherInterface::class);
        $nextDispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$nextEnds): object {
            if ($event instanceof WorkflowEnd) {
                $nextEnds[] = $event->execution->runId;
            }
            return $event;
        });
        $workflow = Workflow::make('order')->addNode(new ChunkStreamingNode(2))->setEventDispatcher($originalDispatcher);
        $stream = $workflow->events();
        $stream->rewind();
        $workflow->setEventDispatcher($nextDispatcher)->subscribe(WorkflowEnd::class, static function (WorkflowEnd $event) use (&$subscribedEnds): void {
            $subscribedEnds[] = $event->execution->runId;
        });
        iterator_to_array($stream);

        self::assertSame([$stream->getReturn()->getRunId()], $originalEnds);
        self::assertSame([], $nextEnds);
        self::assertSame([], $subscribedEnds);

        $state = $workflow->run();
        self::assertSame([$state->getRunId()], $nextEnds);
        self::assertSame([$state->getRunId()], $subscribedEnds);
        self::assertCount(1, $originalEnds);
    }

    public function test_a_listener_subscribed_after_the_dispatcher_was_resolved_receives_the_next_run(): void
    {
        $workflow = Workflow::make('order')->addNode(new ChunkStreamingNode(2));
        $workflow->getEventDispatcher();
        $ends = [];
        $workflow->subscribe(WorkflowEnd::class, static function (WorkflowEnd $event) use (&$ends): void {
            $ends[] = $event->execution->runId;
        });

        $state = $workflow->run();

        self::assertSame([$state->getRunId()], $ends);
    }

    public function test_an_external_dispatcher_set_after_the_dispatcher_was_resolved_receives_the_next_run(): void
    {
        $workflow = Workflow::make('order')->addNode(new ChunkStreamingNode(2));
        $workflow->getEventDispatcher();
        $external = new RecordingEventDispatcher();
        $workflow->setEventDispatcher($external);

        $state = $workflow->run();

        $ends = array_values(array_filter($external->dispatched, static fn (object $event): bool => $event instanceof WorkflowEnd));
        self::assertCount(1, $ends);
        self::assertSame($state->getRunId(), $ends[0]->execution->runId);
    }

    public function test_export_hands_the_graph_to_the_configured_exporter_without_claiming_a_run(): void
    {
        $exporter = new class () implements ExporterInterface {
            public ?WorkflowGraph $graph = null;

            public function export(WorkflowGraph $graph): string
            {
                $this->graph = $graph;
                return 'exported';
            }
        };
        $workflow = Workflow::make('order')
            ->setExporter($exporter)
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        self::assertSame('exported', $workflow->export());

        $classes = array_map(static fn (WorkflowGraphVertex $vertex): ?string => $vertex->class, $exporter->graph?->getVertices() ?? []);
        $nodes = array_filter($classes, static fn (?string $class): bool => $class !== null && is_a($class, NodeInterface::class, true));
        self::assertSame([NodeOne::class, NodeTwo::class, NodeThree::class], array_values(array_unique($nodes)));
        self::assertNull($workflow->inspect());
    }

    public function test_export_refuses_an_invalid_graph(): void
    {
        $workflow = Workflow::make('order')->addNodes([new NodeTwo(), new NodeThree()]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No nodes found that handle ' . StartEvent::class);

        $workflow->export();
    }
}
