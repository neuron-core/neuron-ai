<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\Stub\IgnitionStartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

class ExecutionRequestTest extends TestCase
{
    #[DataProvider('validRunIds')]
    public function test_a_start_accepts_a_well_formed_reserved_run_id(string $runId): void
    {
        $request = ExecutionRequest::start(runId: $runId);

        $this->assertTrue($request->starting);
        $this->assertSame($runId, $request->runId);
    }

    /** @return array<string, array{string}> */
    public static function validRunIds(): array
    {
        return [
            'single character' => ['a'],
            'digit first' => ['9-delivery_ID'],
            'maximum length' => [str_repeat('a', 128)],
        ];
    }

    /**
     * Run IDs prefix every record key of a run, so they must never address
     * a reserved record, another run or a path.
     */
    #[DataProvider('invalidRunIds')]
    public function test_a_start_rejects_a_malformed_reserved_run_id(string $runId): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Invalid reserved run ID: use 1-128 ASCII letters, digits, underscores or hyphens, starting with a letter or digit.');

        ExecutionRequest::start(runId: $runId);
    }

    /** @return array<string, array{string}> */
    public static function invalidRunIds(): array
    {
        return [
            'empty' => [''],
            'too long' => [str_repeat('a', 129)],
            'reserved record prefix' => ['__control'],
            'leading hyphen' => ['-run'],
            'record separator' => ['run/step'],
            'path traversal' => ['../run'],
            'memo separator' => ['run::memo'],
            'trailing newline' => ["run\n"],
            'NUL byte' => ["run\0"],
            'whitespace' => ['run 1'],
            'multibyte' => ['rün'],
        ];
    }

    public function test_a_start_without_event_leaves_the_default_to_the_workflow(): void
    {
        $request = ExecutionRequest::start();

        $this->assertTrue($request->starting);
        $this->assertNull($request->event());
        $this->assertNull($request->payload());
        $this->assertNull($request->runId);
        $this->assertFalse($request->recoverFailed);
    }

    public function test_the_start_event_is_captured_at_creation_and_returned_detached(): void
    {
        $event = new IgnitionStartEvent('original');
        $request = ExecutionRequest::start($event);
        $event->message = 'mutated after creation';

        $first = $request->event();
        $this->assertInstanceOf(IgnitionStartEvent::class, $first);
        $first->message = 'mutated by a reader';

        $second = $request->event();
        $this->assertInstanceOf(IgnitionStartEvent::class, $second);
        $this->assertSame('original', $second->message);
    }

    public function test_the_payload_is_captured_at_creation_and_returned_detached(): void
    {
        $state = new WorkflowState(['value' => 1]);
        $request = ExecutionRequest::resume(['state' => $state]);
        $state->set('value', 2);

        $payload = $request->payload();
        $payload['state']->set('value', 3);

        $this->assertSame(1, $request->payload()['state']->get('value'));
    }

    public function test_an_empty_answer_is_distinct_from_no_answer(): void
    {
        $this->assertSame([], ExecutionRequest::resume([])->payload());
        $this->assertNull(ExecutionRequest::resume()->payload());
    }

    public function test_a_resume_carries_its_fences_and_never_starts(): void
    {
        $request = ExecutionRequest::resume(['ok' => true], 'run-1', 3);

        $this->assertFalse($request->starting);
        $this->assertSame('run-1', $request->runId);
        $this->assertSame(3, $request->executionAttempt);
        $this->assertNull($request->signal);
        $this->assertNull($request->event());
    }

    public function test_a_signal_answers_with_an_empty_payload_by_default(): void
    {
        $request = ExecutionRequest::signal('order.paid', expectedRunId: 'run-1', expectedExecutionAttempt: 2);

        $this->assertFalse($request->starting);
        $this->assertSame('order.paid', $request->signal);
        $this->assertSame([], $request->payload());
        $this->assertSame('run-1', $request->runId);
        $this->assertSame(2, $request->executionAttempt);
    }
}
