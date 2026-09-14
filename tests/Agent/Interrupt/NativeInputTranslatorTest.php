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
        (new ToolResultsTranslator())->translate(['a' => ['result' => 'approve']], (new ApprovalRequest('Approve', [new Action('a', 'one')]))->withId(1));
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
        (new ApprovalTranslator())->translate(['a' => $decision], (new ApprovalRequest('Approve', [new Action('a', 'one')]))->withId(1));
    }
}
