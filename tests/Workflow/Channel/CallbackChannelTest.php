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
    public function testUnsetHooksAreSilentNoOps(): void
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

    public function testEachHookReceivesItsArguments(): void
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
}
