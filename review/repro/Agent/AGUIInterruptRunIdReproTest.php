<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Adapters;

use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function iterator_to_array;

class AGUIInterruptRunIdReproTest extends TestCase
{
    public function test_an_interrupt_never_finishes_with_a_null_run_id(): void
    {
        $adapter = new AGUIAdapter('thread');
        $request = (new ApprovalRequest('Approve', [new Action('call_1', 'delete')]))->withId(1);

        $finishedWithoutRunId = array_values(array_filter(
            iterator_to_array($adapter->interrupt($request), false),
            static fn (ProtocolEvent $frame): bool => $frame->type === 'RUN_FINISHED' && $frame->data['runId'] === null,
        ));

        $this->assertSame([], $finishedWithoutRunId, 'AG-UI requires RUN_FINISHED.runId; end() suppresses the frame instead.');
    }

    public function test_end_without_run_id_emits_no_run_finished(): void
    {
        $this->assertSame([], iterator_to_array((new AGUIAdapter('thread'))->end(), false));
    }
}
