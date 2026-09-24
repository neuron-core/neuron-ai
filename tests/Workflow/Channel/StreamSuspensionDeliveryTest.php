<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Workflow\Channel\Stub\SharedRequestInterruptNode;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_key_last;
use function array_map;
use function array_slice;
use function count;
use function json_decode;
use function json_encode;

class StreamSuspensionDeliveryTest extends TestCase
{
    public function test_suspension_frames_reach_the_channel_instead_of_end(): void
    {
        $request = new ApprovalRequest('needs a human', [
            new Action('call_1', 'delete_file', inputs: ['path' => '/tmp/x']),
        ]);
        $channel = new FakeChannel();
        $workflow = Workflow::make()
            ->addNodes([new NodeOne(), new SharedRequestInterruptNode($request)])
            ->setStreamAdapter(new AGUIAdapter('thread_test', 'run_test'))
            ->setChannel($channel);

        $state = $workflow->run();

        $this->assertTrue($state->isInterrupted());
        $this->assertCount(1, $channel->getSuspensions());
        $this->assertSame([], $channel->getCompletions());

        $events = array_map(static fn (ProtocolEvent $event): array => json_decode(json_encode($event), true), $channel->getSent());
        $this->assertSame(
            ['RUN_STARTED', 'STATE_SNAPSHOT', 'MESSAGES_SNAPSHOT', 'RUN_FINISHED'],
            array_column($events, 'type'),
        );

        $finished = $events[array_key_last($events)];
        $this->assertSame('interrupt', $finished['outcome']['type']);
        $this->assertSame('call_1', $finished['outcome']['interrupts'][0]['id']);
        $this->assertSame('needs a human', $finished['outcome']['interrupts'][0]['message']);
    }

    public function test_the_segment_outcome_selects_the_adapter_terminal(): void
    {
        $request = new ApprovalRequest('needs a human');
        $channel = new FakeChannel();

        $pauseFrame = new ProtocolEvent('paused');
        $paused = $this->createMock(StreamAdapterInterface::class);
        $paused->expects($this->once())->method('reset');
        $paused->expects($this->once())->method('start')->willReturn([]);
        // The InterruptEvent is the suspension terminal, never stream content.
        $paused->expects($this->never())->method('transform');
        $paused->expects($this->once())->method('interrupt')
            ->with($this->callback(
                static fn (ApprovalRequest $request): bool => $request->getId() === 1,
            ))
            ->willReturn([$pauseFrame]);
        $paused->expects($this->never())->method('end');

        $workflow = Workflow::make('test-execution')
            ->addNodes([new NodeOne(), new SharedRequestInterruptNode($request), new NodeThree()])
            ->setStreamAdapter($paused)
            ->setChannel($channel);

        $state = $workflow->run();

        $this->assertTrue($state->isInterrupted());
        $this->assertSame([$pauseFrame], $channel->getSent());
        $this->assertCount(1, $channel->getSuspensions());

        // The continuation completes: a fresh adapter for the segment ends normally.
        $doneFrame = new ProtocolEvent('done');
        $completed = $this->createMock(StreamAdapterInterface::class);
        $completed->expects($this->once())->method('reset');
        $completed->expects($this->once())->method('start')->willReturn([]);
        $completed->expects($this->never())->method('interrupt');
        $completed->expects($this->once())->method('end')->willReturn([$doneFrame]);

        $state = $workflow
            ->setStreamAdapter($completed)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([]));

        $this->assertFalse($state->isInterrupted());
        $this->assertSame([$pauseFrame, $doneFrame], $channel->getSent());
        $this->assertCount(1, $channel->getCompletions());
    }

    /**
     * @param list<string> $firstSegment
     * @param list<string> $continuation
     */
    #[DataProvider('reusable_adapters')]
    public function test_one_adapter_instance_serves_a_suspension_and_its_continuation(
        StreamAdapterInterface $adapter,
        array $firstSegment,
        array $continuation,
    ): void {
        $request = new ApprovalRequest('needs a human', [
            new Action('call_1', 'delete_file', inputs: ['path' => '/tmp/x']),
        ]);
        $channel = new FakeChannel();
        $workflow = Workflow::make('test-execution')
            ->addNodes([new NodeOne(), new SharedRequestInterruptNode($request), new NodeThree()])
            ->setStreamAdapter($adapter)
            ->setChannel($channel);

        $state = $workflow->run();

        $this->assertTrue($state->isInterrupted());
        $this->assertSame($firstSegment, $this->types($channel->getSent()));

        // The instance is reset at the segment boundary, so the continuation
        // is framed again instead of being silently suppressed.
        $delivered = count($channel->getSent());
        $state = $workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([]));

        $this->assertFalse($state->isInterrupted());
        $this->assertSame($continuation, $this->types(array_slice($channel->getSent(), $delivered)));
        $this->assertCount(1, $channel->getCompletions());
    }

    /**
     * @return iterable<string, array{StreamAdapterInterface, list<string>, list<string>}>
     */
    public static function reusable_adapters(): iterable
    {
        yield 'AG-UI' => [
            new AGUIAdapter('thread_test', 'run_test'),
            ['RUN_STARTED', 'STATE_SNAPSHOT', 'MESSAGES_SNAPSHOT', 'RUN_FINISHED'],
            ['RUN_STARTED', 'RUN_FINISHED'],
        ];
        yield 'Vercel' => [
            new VercelAIAdapter(),
            ['start', 'tool-input-start', 'tool-input-delta', 'tool-approval-request', 'finish'],
            ['finish'],
        ];
    }

    /**
     * @param ProtocolEvent[] $events
     * @return list<string>
     */
    protected function types(array $events): array
    {
        return array_map(static fn (ProtocolEvent $event): string => $event->type, $events);
    }
}
