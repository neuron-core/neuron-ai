<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Interrupt;

use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Agent\Interrupt\ToolResultsTranslator;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class NativeInputTranslatorTest extends TestCase
{
    protected function approvalRequest(): ApprovalRequest
    {
        return (new ApprovalRequest('Approve', [new Action('a', 'one'), new Action('b', 'two')]))->withId(1);
    }

    public function test_approval_payload_does_not_repeat_previous_decisions(): void
    {
        $request = (new ApprovalRequest('Approve', [
            new Action('a', 'one', decision: ActionDecision::Approved),
            new Action('b', 'two'),
            new Action('123', 'three'),
        ]))->withId(4);
        $payload = ['b' => ['reject', 'Too expensive'], 123 => 'approve'];
        $this->assertSame($payload, (new ApprovalTranslator())->translate($payload, $request));
        $this->assertSame(['a' => 'reject'], (new ApprovalTranslator())->translate(['a' => 'reject'], $request));
    }

    public function test_results_are_returned_as_one_payload(): void
    {
        $request = (new ToolResultsRequest([new ToolCall('browser', 'a'), new ToolCall('browser', 'b')]))->withId(4);
        $payload = ['a' => ['result' => false], 'b' => ['result' => null]];
        $this->assertSame($payload, (new ToolResultsTranslator())->translate($payload, $request));
    }

    public function test_execution_results_cannot_answer_an_approval_request(): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("No matching request for tool call 'a'.");

        (new ToolResultsTranslator())->translate(['a' => ['result' => 'approve']], $this->approvalRequest());
    }

    public function test_approval_decisions_cannot_answer_a_tool_results_request(): void
    {
        $request = (new ToolResultsRequest([new ToolCall('browser', 'a', deferred: true)]))->withId(1);

        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("No matching request for tool call 'a'.");

        (new ApprovalTranslator())->translate(['a' => 'approve'], $request);
    }

    public function test_decisions_cannot_answer_an_unrelated_wait(): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("No matching request for tool call 'a'.");

        (new ApprovalTranslator())->translate(['a' => 'approve'], (new WaitForEventRequest('approval'))->withId(1));
    }

    public function test_a_decision_for_a_forged_call_id_is_refused(): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("No matching request for tool call 'forged'.");

        (new ApprovalTranslator())->translate(['a' => 'approve', 'forged' => 'approve'], $this->approvalRequest());
    }

    public function test_an_empty_decision_set_is_refused(): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage('The payload contains no matching continuation input.');

        (new ApprovalTranslator())->translate([], $this->approvalRequest());
    }

    #[TestWith([null])]
    #[TestWith([''])]
    public function test_a_request_holding_a_call_without_an_id_accepts_no_input(?string $callId): void
    {
        $request = (new ToolResultsRequest([new ToolCall('browser', $callId, deferred: true)]))->withId(1);

        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage('A tool call requires a non-empty call ID.');

        (new ToolResultsTranslator())->translate(['' => ['result' => 'x']], $request);
    }

    public function test_malformed_results_are_refused_with_the_batch_rules(): void
    {
        $request = (new ToolResultsRequest([new ToolCall('browser', 'a', deferred: true)]))->withId(1);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Tool result 'a' must contain either 'result' or a string 'error'.");

        (new ToolResultsTranslator())->translate(['a' => ['result' => 'x', 'error' => 'y']], $request);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDecisions(): iterable
    {
        yield 'boolean' => [true];
        yield 'null' => [null];
        yield 'integer' => [1];
        yield 'unknown decision' => ['approved'];
        yield 'uppercase' => ['APPROVE'];
        yield 'padded' => [' approve'];
        yield 'reason on approval' => [['approve', 'Reason']];
        yield 'nonstring reason' => [['reject', false]];
        yield 'missing reason' => [['reject']];
        yield 'extra value' => [['reject', 'Reason', 'Extra']];
        yield 'associative rejection' => [['decision' => 'reject', 'reason' => 'Reason']];
    }

    #[DataProvider('invalidDecisions')]
    public function test_invalid_native_decisions_are_rejected(mixed $decision): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("A decision must be 'approve', 'reject', or ['reject', reason].");

        (new ApprovalTranslator())->translate(['a' => $decision], $this->approvalRequest());
    }

    public function test_one_invalid_decision_rejects_the_whole_delivery(): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("A decision must be 'approve', 'reject', or ['reject', reason].");

        (new ApprovalTranslator())->translate(['a' => 'approve', 'b' => 'yes'], $this->approvalRequest());
    }
}
