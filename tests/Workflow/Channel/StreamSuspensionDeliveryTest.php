<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Chat\Messages\Stream\Adapters\AGUIAdapter;
use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Workflow\Channel\Stub\SharedRequestInterruptNode;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;
use function array_column;
use function array_key_last;
use function array_map;
use function count;
use function json_decode;
use function substr;

class StreamSuspensionDeliveryTest extends TestCase
{
    public function test_suspension_frames_reach_pull_and_push_consumers_instead_of_end(): void
    {
        $request = new ApprovalRequest('needs a human', [
            new Action('call_1', 'delete_file', inputs: ['path' => '/tmp/x']),
        ]);
        $channel = new FakeChannel();
        $workflow = Workflow::make()
            ->addNodes([new NodeOne(), new SharedRequestInterruptNode($request)])
            ->setStreamAdapter(new AGUIAdapter('thread_test', 'run_test'))
            ->setChannel($channel);

        $pulled = [];
        $generator = $workflow->events();
        foreach ($generator as $line) {
            $pulled[] = $line;
        }
        $state = $generator->getReturn();

        $this->assertTrue($state->isInterrupted());
        $this->assertSame($pulled, $channel->lines);
        $this->assertSame([], $channel->sent);
        $this->assertCount(1, $channel->suspendedStates);
        $this->assertSame([], $channel->completions);

        $events = array_map(static fn (string $line): array => json_decode(substr($line, 6, -2), true), $pulled);
        $this->assertSame(
            ['RUN_STARTED', 'TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'RUN_FINISHED'],
            array_column($events, 'type'),
        );

        $finished = $events[array_key_last($events)];
        $this->assertSame('interrupt', $finished['outcome']['type']);
        $this->assertSame('call_1', $finished['outcome']['interrupts'][0]['toolCallId']);
        $this->assertSame('needs a human', $finished['outcome']['interrupts'][0]['message']);
    }

    public function test_the_segment_outcome_selects_the_adapter_terminal(): void
    {
        $request = new ApprovalRequest('needs a human');
        $channel = new FakeChannel();

        $paused = $this->createMock(StreamAdapterInterface::class);
        $paused->expects($this->once())->method('start')->willReturn([]);
        // The InterruptEvent is the suspension terminal, never stream content.
        $paused->expects($this->never())->method('transform');
        $paused->expects($this->once())->method('suspended')
            ->with($this->callback(
                static fn (array $requests): bool => count($requests) === 1
                    && $requests[1] instanceof ApprovalRequest
                    && $requests[1]->getId() === 1,
            ))
            ->willReturn(['paused']);
        $paused->expects($this->never())->method('end');

        $workflow = Workflow::make()
            ->addNodes([new NodeOne(), new SharedRequestInterruptNode($request), new NodeThree()])
            ->setStreamAdapter($paused)
            ->setChannel($channel);

        $state = $workflow->run();

        $this->assertTrue($state->isInterrupted());
        $this->assertSame(['paused'], $channel->lines);
        $this->assertCount(1, $channel->suspendedStates);

        // The continuation completes: a fresh adapter for the segment ends normally.
        $completed = $this->createMock(StreamAdapterInterface::class);
        $completed->expects($this->once())->method('start')->willReturn([]);
        $completed->expects($this->never())->method('suspended');
        $completed->expects($this->once())->method('end')->willReturn(['done']);

        $state = $workflow
            ->setStreamAdapter($completed)
            ->run([ResumeInput::event($state->getInterruptRequest(), [])]);

        $this->assertFalse($state->isInterrupted());
        $this->assertSame(['paused', 'done'], $channel->lines);
        $this->assertCount(1, $channel->completions);
    }
}
