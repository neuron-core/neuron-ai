<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Frontend;

use NeuronAI\Agent\Frontend\VercelAIInputTranslator;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vercel UI messages are untrusted and carry the full client history: only
 * parts answering the current interrupt count, and malformed ones fail loudly.
 */
class VercelAIInputTranslatorTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function malformedResultParts(): iterable
    {
        yield 'parts is a map' => [['messages' => [['role' => 'assistant', 'parts' => ['a' => []]]]], "'parts' must be a list."];
        yield 'part is a scalar' => [['messages' => [['role' => 'assistant', 'parts' => ['tool-browser']]]], "Every 'parts' entry must be an object."];
        yield 'output is missing' => [self::parts(['state' => 'output-available']), "Tool call 'a' has no output."];
        yield 'errorText is missing' => [self::parts(['state' => 'output-error']), "Tool call 'a' requires errorText."];
        yield 'errorText is structured' => [self::parts(['state' => 'output-error', 'errorText' => ['code' => 1]]), "Tool call 'a' requires errorText."];
        yield 'only forged call IDs' => [self::parts(['toolCallId' => 'forged', 'state' => 'output-available', 'output' => 'x']), 'The payload contains no matching continuation input.'];
        yield 'tool part on a user message' => [['messages' => [['role' => 'user', 'parts' => [
            ['type' => 'tool-browser', 'toolCallId' => 'a', 'state' => 'output-available', 'output' => 'x'],
        ]]]], 'The payload contains no matching continuation input.'];
        yield 'non-tool part type' => [self::parts(['type' => 'text', 'state' => 'output-available', 'output' => 'x']), 'The payload contains no matching continuation input.'];
        yield 'numeric part type' => [self::parts(['type' => 1, 'state' => 'output-available', 'output' => 'x']), 'The payload contains no matching continuation input.'];
        yield 'still running in the browser' => [self::parts(['state' => 'input-available']), 'The payload contains no matching continuation input.'];
        yield 'denied is presentation only' => [self::parts(['state' => 'output-denied']), 'The payload contains no matching continuation input.'];
        yield 'conflicting outputs' => [['messages' => [
            ['role' => 'assistant', 'parts' => [['type' => 'tool-browser', 'toolCallId' => 'a', 'state' => 'output-available', 'output' => 'one']]],
            ['role' => 'assistant', 'parts' => [['type' => 'tool-browser', 'toolCallId' => 'a', 'state' => 'output-available', 'output' => 'two']]],
        ]], "Conflicting responses for tool call 'a'."];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('malformedResultParts')]
    public function test_malformed_result_parts_are_rejected(array $payload, string $message): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage($message);

        (new VercelAIInputTranslator())->translate($payload, $this->deferredBatch());
    }

    public function test_earlier_tool_cycles_in_the_history_are_ignored(): void
    {
        $inputs = (new VercelAIInputTranslator())->translate(['messages' => [
            ['role' => 'assistant', 'parts' => [
                ['type' => 'tool-browser', 'toolCallId' => 'old', 'state' => 'output-available', 'output' => 'stale'],
            ]],
            ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Continue']]],
            ['role' => 'assistant', 'parts' => [
                ['type' => 'text', 'text' => 'Reading'],
                ['type' => 'tool-browser', 'toolCallId' => 'b', 'state' => 'output-error', 'errorText' => 'Blocked'],
            ]],
        ]], $this->deferredBatch());

        $this->assertSame(['b' => ['error' => 'Blocked']], $inputs);
    }

    /** @return iterable<string, array{mixed}> */
    public static function jsonOutputs(): iterable
    {
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'empty string' => [''];
        yield 'empty list' => [[]];
        yield 'nested object' => [['page' => ['title' => 'Caffè ☕', 'links' => [1, 2]]]];
    }

    #[DataProvider('jsonOutputs')]
    public function test_falsy_and_structured_outputs_keep_their_json_type(mixed $output): void
    {
        $inputs = (new VercelAIInputTranslator())->translate(
            self::parts(['state' => 'output-available', 'output' => $output]),
            $this->deferredBatch(),
        );

        $this->assertSame(['a' => ['result' => $output]], $inputs);
    }

    public function test_an_identical_repeat_of_the_same_output_is_accepted_once(): void
    {
        $part = ['type' => 'tool-browser', 'toolCallId' => 'a', 'state' => 'output-available', 'output' => 'Page'];

        $inputs = (new VercelAIInputTranslator())->translate(
            ['messages' => [['role' => 'assistant', 'parts' => [$part, $part]]]],
            $this->deferredBatch(),
        );

        $this->assertSame(['a' => ['result' => 'Page']], $inputs);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidApprovals(): iterable
    {
        yield 'missing approval' => [[], "Approval ID does not match tool call 'a'."];
        yield 'approval is a string' => [['approval' => 'yes'], "Approval ID does not match tool call 'a'."];
        yield 'approval of another call' => [['approval' => ['id' => 'b', 'approved' => true]], "Approval ID does not match tool call 'a'."];
        yield 'non-boolean decision' => [['approval' => ['id' => 'a', 'approved' => 'true']], 'An approval response requires a boolean approved field.'];
        yield 'structured reason' => [['approval' => ['id' => 'a', 'approved' => false, 'reason' => 1]], 'An approval reason must be a string.'];
        yield 'edited arguments' => [['approval' => ['id' => 'a', 'approved' => true, 'editedArgs' => ['x' => 1]]], 'Approval with edited arguments is not supported.'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('invalidApprovals')]
    public function test_invalid_approval_responses_are_rejected(array $fields, string $message): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage($message);

        (new VercelAIInputTranslator())->translate(self::parts(['state' => 'approval-responded', ...$fields]), $this->approval());
    }

    public function test_approvals_for_calls_outside_the_request_are_ignored(): void
    {
        $inputs = (new VercelAIInputTranslator())->translate(['messages' => [['role' => 'assistant', 'parts' => [
            ['type' => 'tool-shell', 'toolCallId' => 'forged', 'state' => 'approval-responded', 'approval' => ['id' => 'forged', 'approved' => true]],
            ['type' => 'dynamic-tool', 'toolCallId' => 'a', 'state' => 'approval-responded', 'approval' => ['id' => 'a', 'approved' => true]],
        ]]]], $this->approval());

        $this->assertSame(['a' => 'approve'], $inputs);
    }

    public function test_an_approval_without_any_response_is_rejected(): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage('The payload contains no matching continuation input.');

        (new VercelAIInputTranslator())->translate(self::parts(['state' => 'approval-requested']), $this->approval());
    }

    public function test_tool_parts_cannot_answer_a_generic_wait(): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage('The payload contains no matching continuation input.');

        (new VercelAIInputTranslator())->translate(
            self::parts(['state' => 'output-available', 'output' => 'x']),
            (new WaitForEventRequest('a'))->withId(1),
        );
    }

    public function test_a_call_without_an_id_cannot_be_correlated(): void
    {
        $request = (new ToolResultsRequest([new ToolCall('browser', null, deferred: true)]))->withId(1);

        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage('A tool call requires a non-empty call ID.');

        (new VercelAIInputTranslator())->translate(self::parts(['state' => 'output-available', 'output' => 'x']), $request);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected static function parts(array $fields): array
    {
        return ['messages' => [['role' => 'assistant', 'parts' => [
            ['type' => 'tool-browser', 'toolCallId' => 'a', ...$fields],
        ]]]];
    }

    protected function deferredBatch(): ToolResultsRequest
    {
        return (new ToolResultsRequest([
            new ToolCall('browser', 'a', deferred: true),
            new ToolCall('browser', 'b', deferred: true),
        ]))->withId(4);
    }

    protected function approval(): ApprovalRequest
    {
        return (new ApprovalRequest('Approve', [new Action('a', 'browser')]))->withId(1);
    }
}
