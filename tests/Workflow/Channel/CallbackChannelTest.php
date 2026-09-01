<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel;

use NeuronAI\Workflow\Channel\CallbackChannel;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

class CallbackChannelTest extends TestCase
{
    public function test_unset_hooks_are_silent_no_ops(): void
    {
        $received = [];
        $channel = new CallbackChannel(
            onSend: function (object $item) use (&$received): void {
                $received[] = $item;
            },
        );

        $item = new stdClass();
        $channel->send($item);

        // The other three hooks are unset: calling them must be a silent no-op.
        $channel->suspended(new ApprovalRequest('pending'), 'run_1');
        $channel->completed(new WorkflowState(), 'run_1');
        $channel->failed(new RuntimeException('boom'), 'run_1');

        $this->assertSame([$item], $received);
    }

    public function test_each_hook_receives_its_arguments(): void
    {
        $calls = [];
        $channel = new CallbackChannel(
            onSend: function (object $item) use (&$calls): void {
                $calls[] = ['send', $item];
            },
            onSuspended: function (InterruptRequest $request, string $runId) use (&$calls): void {
                $calls[] = ['suspended', $request, $runId];
            },
            onCompleted: function (WorkflowState $state, string $runId) use (&$calls): void {
                $calls[] = ['completed', $state, $runId];
            },
            onFailed: function (Throwable $exception, string $runId) use (&$calls): void {
                $calls[] = ['failed', $exception, $runId];
            },
        );

        $item = new stdClass();
        $request = new ApprovalRequest('pending');
        $state = new WorkflowState();
        $exception = new RuntimeException('boom');

        $channel->send($item);
        $channel->suspended($request, 'run_1');
        $channel->completed($state, 'run_1');
        $channel->failed($exception, 'run_1');

        $this->assertSame([
            ['send', $item],
            ['suspended', $request, 'run_1'],
            ['completed', $state, 'run_1'],
            ['failed', $exception, 'run_1'],
        ], $calls);
    }

    public function test_send_line_receives_each_line_and_is_silent_when_unset(): void
    {
        // Unset onSendLine: a silent no-op (same shape as the other hooks).
        (new CallbackChannel())->sendLine('dropped');

        // Set: each adapted protocol line reaches the closure, in order.
        $received = [];
        $channel = new CallbackChannel(
            onSendLine: function (string $line) use (&$received): void {
                $received[] = $line;
            },
        );

        $channel->sendLine("start\n");
        $channel->sendLine('text:hello');

        $this->assertSame(["start\n", 'text:hello'], $received);
    }
}
