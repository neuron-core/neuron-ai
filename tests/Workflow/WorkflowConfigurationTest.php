<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Tests\Workflow\Channel\Stub\ChunkStreamingNode;
use NeuronAI\Tests\Workflow\Stub\FirstEvent;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\Serializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

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

        self::assertSame($retain, $workflow->inspect() !== null);
        if ($retain) {
            $workflow->acknowledge($stream->getReturn()->getRunId());
        }
        $workflow->run();
        self::assertSame(!$retain, $workflow->inspect() !== null);
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
            ->addGlobalMiddleware(fn () => $middleware)
            ->addMiddleware(NodeThree::class, fn () => $middleware);
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
}
