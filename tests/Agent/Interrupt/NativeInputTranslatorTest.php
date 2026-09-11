<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Interrupt;

use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Agent\Interrupt\ToolResultsTranslator;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NativeInputTranslatorTest extends TestCase
{
    public function test_approval_inputs_are_addressed_per_request_and_keep_partial_decisions(): void
    {
        $requests = [
            (new ApprovalRequest('First', [new Action('a', 'one', decision: ActionDecision::Approved), new Action('b', 'two')]))->withId(4),
            (new ApprovalRequest('Second', [new Action('123', 'three')]))->withId(8),
        ];
        $inputs = (new ApprovalTranslator())->translate(['b' => ['reject', 'Too expensive'], 123 => 'approve'], $requests);
        $this->assertSame([4, 8], array_column($inputs, 'interruptId'));
        $this->assertSame(['b' => ['reject', 'Too expensive'], 'a' => 'approve'], $inputs[0]->payload);
        $this->assertSame([123 => 'approve'], $inputs[1]->payload);
        $updated = (new ApprovalTranslator())->translate(['a' => 'reject'], $requests);
        $this->assertSame(['a' => 'reject'], $updated[0]->payload);
    }

    public function test_results_are_partitioned_without_answering_other_requests(): void
    {
        $requests = [
            (new ToolResultsRequest([new ToolCall('browser', 'a')]))->withId(4),
            (new ToolResultsRequest([new ToolCall('browser', 'b')]))->withId(8),
            (new ApprovalRequest('Other', [new Action('c', 'other')]))->withId(12),
        ];
        $inputs = (new ToolResultsTranslator())->translate(['a' => ['result' => false], 'b' => ['result' => null]], $requests);
        $this->assertSame([4, 8], array_column($inputs, 'interruptId'));
        $this->assertSame(['a' => ['result' => false]], $inputs[0]->payload);
        $this->assertSame(['b' => ['result' => null]], $inputs[1]->payload);
    }

    public function test_execution_results_cannot_answer_an_approval_request(): void
    {
        $this->expectException(InputTranslationException::class);
        (new ToolResultsTranslator())->translate(['a' => ['result' => 'approve']], [
            (new ApprovalRequest('Approve', [new Action('a', 'one')]))->withId(1),
        ]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDecisions(): iterable
    {
        yield 'boolean' => [true];
        yield 'unknown decision' => ['approved'];
        yield 'reason on approval' => [['approve', 'Reason']];
        yield 'nonstring reason' => [['reject', false]];
        yield 'missing reason' => [['reject']];
        yield 'extra value' => [['reject', 'Reason', 'Extra']];
    }

    #[DataProvider('invalidDecisions')]
    public function test_invalid_native_decisions_are_rejected(mixed $decision): void
    {
        $this->expectException(InputTranslationException::class);
        (new ApprovalTranslator())->translate(['a' => $decision], [
            (new ApprovalRequest('Approve', [new Action('a', 'one')]))->withId(1),
        ]);
    }
}
