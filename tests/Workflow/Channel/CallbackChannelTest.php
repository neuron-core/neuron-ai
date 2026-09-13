<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use Throwable;

class CallbackChannelTest extends TestCase
{
    public function test_unset_hooks_are_silent_no_ops(): void
    {
        $received = [];
        $channel = new CallbackChannel(
            onSend: function (ProtocolEvent $item) use (&$received): void {
                $received[] = $item;
            },
        );

        $item = new ProtocolEvent('item');
        $channel->send($item);

        // The other three hooks are unset: calling them must be a silent no-op.
        $channel->suspended(new WorkflowState());
        $channel->completed(new WorkflowState(), 'run_1');
        $channel->failed(new RuntimeException('boom'), 'run_1');

        $this->assertSame([$item], $received);
    }

    public function test_each_hook_receives_its_arguments(): void
    {
        $calls = [];
        $channel = new CallbackChannel(
            onSend: function (ProtocolEvent $item) use (&$calls): void {
                $calls[] = ['send', $item];
            },
            onSuspended: function (WorkflowState $state) use (&$calls): void {
                $calls[] = ['suspended', $state];
            },
            onCompleted: function (WorkflowState $state, string $runId) use (&$calls): void {
                $calls[] = ['completed', $state, $runId];
            },
            onFailed: function (Throwable $exception, string $runId) use (&$calls): void {
                $calls[] = ['failed', $exception, $runId];
            },
        );

        $item = new ProtocolEvent('item');
        $state = new WorkflowState();
        $exception = new RuntimeException('boom');

        $channel->send($item);
        $channel->suspended($state);
        $channel->completed($state, 'run_1');
        $channel->failed($exception, 'run_1');

        $this->assertSame([
            ['send', $item],
            ['suspended', $state],
            ['completed', $state, 'run_1'],
            ['failed', $exception, 'run_1'],
        ], $calls);
    }
}
