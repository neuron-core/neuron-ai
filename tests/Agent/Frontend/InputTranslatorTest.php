<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Frontend;

use DateTimeImmutable;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Agent\Frontend\VercelAIInputTranslator;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InputTranslatorTest extends TestCase
{
    public function test_agui_tool_messages_preserve_text_and_correlate_repeated_tool_names(): void
    {
        $translator = new AGUIInputTranslator();
        $this->assertInstanceOf(InputTranslatorInterface::class, $translator);
        $request = (new ToolResultsRequest([
            new ToolCall('browser', 'a', deferred: true),
            new ToolCall('browser', 'b', deferred: true),
        ]))->withId(4);
        $inputs = $translator->translate(['messages' => [
            ['role' => 'tool', 'toolCallId' => 'old', 'content' => 'Earlier result'],
            ['role' => 'tool', 'toolCallId' => 'b', 'content' => 'Failure', 'error' => 'Permission denied'],
            ['role' => 'tool', 'toolCallId' => 'a', 'content' => '{"title":"Page"}'],
        ]], $request);
        $this->assertCount(2, $inputs);
        $this->assertSame([
            'b' => ['error' => 'Permission denied'],
            'a' => ['result' => '{"title":"Page"}'],
        ], $inputs);
    }

    public function test_vercel_parts_preserve_json_values_and_ignore_prior_approval(): void
    {
        $translator = new VercelAIInputTranslator();
        $this->assertInstanceOf(InputTranslatorInterface::class, $translator);
        $request = (new ToolResultsRequest([
            new ToolCall('browser', 'a', deferred: true),
            new ToolCall('browser', 'b', deferred: true),
        ]))->withId(4);
        $inputs = $translator->translate(['messages' => [[
            'role' => 'assistant', 'parts' => [
                ['type' => 'tool-browser', 'toolCallId' => 'a', 'state' => 'approval-responded', 'approval' => ['id' => 'a', 'approved' => true]],
                ['type' => 'tool-browser', 'toolCallId' => 'a', 'state' => 'output-available', 'output' => null],
                ['type' => 'dynamic-tool', 'toolName' => 'browser', 'toolCallId' => 'b', 'state' => 'output-available', 'output' => ['visible' => false]],
            ],
        ]]], $request);
        $this->assertCount(2, $inputs);
        $this->assertSame(['a' => ['result' => null], 'b' => ['result' => ['visible' => false]]], $inputs);
    }

    public function test_vercel_error_part_becomes_an_error_result(): void
    {
        $request = (new ToolResultsRequest([new ToolCall('browser', 'a', deferred: true)]))->withId(1);
        $inputs = (new VercelAIInputTranslator())->translate(['messages' => [[
            'role' => 'assistant', 'parts' => [[
                'type' => 'tool-browser', 'toolCallId' => 'a', 'state' => 'output-error', 'errorText' => 'GPS unavailable',
            ]],
        ]]], $request);
        $this->assertSame(['a' => ['error' => 'GPS unavailable']], $inputs);
    }

    public function test_agui_folds_all_approval_responses_into_one_engine_input(): void
    {
        $request = (new ApprovalRequest('Approve', [new Action('a', 'browser'), new Action('b', 'browser')]))->withId(7);
        $inputs = (new AGUIInputTranslator())->translate(['resume' => [
            ['interruptId' => 'a', 'status' => 'resolved', 'payload' => ['approved' => true]],
            ['interruptId' => 'b', 'status' => 'cancelled'],
        ], 'messages' => [['role' => 'tool', 'toolCallId' => 'a', 'content' => 'approved']]], $request);
        $this->assertCount(2, $inputs);
        $this->assertSame(['a' => 'approve', 'b' => 'reject'], $inputs);
    }

    public function test_vercel_partial_approval_only_translates_submitted_decisions(): void
    {
        $request = (new ApprovalRequest('Approve', [
            new Action('a', 'browser', decision: ActionDecision::Approved),
            new Action('b', 'browser'),
        ]))->withId(8);
        $inputs = (new VercelAIInputTranslator())->translate(['messages' => [[
            'role' => 'assistant', 'parts' => [[
                'type' => 'tool-browser', 'toolCallId' => 'b', 'state' => 'approval-responded',
                'approval' => ['id' => 'b', 'approved' => false, 'reason' => 'No thanks'],
            ]],
        ]]], $request);
        $this->assertSame(['b' => ['reject', 'No thanks']], $inputs);
    }

    public function test_agui_cancelling_a_deferred_interrupt_settles_every_pending_call(): void
    {
        $request = (new ToolResultsRequest([
            new ToolCall('browser', 'a', deferred: true), new ToolCall('browser', 'b', deferred: true),
        ]))->withId(9);
        $inputs = (new AGUIInputTranslator())->translate(['resume' => [
            ['interruptId' => '9', 'status' => 'cancelled'],
        ]], $request);
        $this->assertSame([
            'a' => ['error' => 'Frontend tool execution cancelled.'],
            'b' => ['error' => 'Frontend tool execution cancelled.'],
        ], $inputs);
    }

    public function test_agui_generic_event_resume_uses_the_same_interface(): void
    {
        $request = (new WaitForEventRequest('custom.event'))->withId(12);
        $inputs = (new AGUIInputTranslator())->translate(['resume' => [
            ['interruptId' => '12', 'status' => 'resolved', 'payload' => ['value' => 42]],
        ]], $request);
        $this->assertSame(['value' => 42], $inputs);
    }

    public function test_agui_catalog_creates_schema_tools_without_attaching_them(): void
    {
        $schema = ['type' => 'object', 'properties' => ['selector' => ['type' => 'string']], 'required' => ['selector']];
        $tools = (new AGUIInputTranslator())->tools(['tools' => [[
            'name' => 'browser', 'description' => 'Read the page', 'parameters' => $schema,
        ]]]);
        $this->assertSame('browser', $tools[0]->getName());
        $this->assertSame($schema, $tools[0]->getInputSchema());
        $this->assertCount(1, $tools[0]->getProperties());
    }

    public function test_conflicting_repeat_of_an_already_accepted_result_is_rejected(): void
    {
        $request = (new ToolResultsRequest(
            [new ToolCall('browser', 'b', deferred: true)],
            ['a' => ['result' => 'original']],
        ))->withId(4);
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Tool result 'a' has already been settled with a different outcome.");
        (new AGUIInputTranslator())->translate(['messages' => [
            ['role' => 'tool', 'toolCallId' => 'a', 'content' => 'changed'],
            ['role' => 'tool', 'toolCallId' => 'b', 'content' => 'new'],
        ]], $request);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidAguiApprovals(): iterable
    {
        yield 'missing coverage' => [['resume' => []], 'AG-UI resume must address every published interrupt.'];
        yield 'unknown ID' => [['resume' => [['interruptId' => 'other', 'status' => 'cancelled']]], 'The resume entry does not identify an active interrupt.'];
        yield 'invalid decision' => [['resume' => [['interruptId' => 'a', 'status' => 'resolved', 'payload' => ['approved' => 'yes']]]], 'An approval response requires a boolean approved field.'];
        yield 'cancelled payload' => [['resume' => [['interruptId' => 'a', 'status' => 'cancelled', 'payload' => null]]], 'A cancelled resume must omit payload.'];
        yield 'unsupported edits' => [['resume' => [['interruptId' => 'a', 'status' => 'resolved', 'payload' => ['approved' => true, 'editedArgs' => []]]]], 'Approval with edited arguments is not supported.'];
        yield 'tool result cannot approve' => [['messages' => [['role' => 'tool', 'toolCallId' => 'a', 'content' => 'approved']]], 'Pending AG-UI interrupts require an explicit resume array.'];
        yield 'duplicate response' => [['resume' => [['interruptId' => 'a', 'status' => 'cancelled'], ['interruptId' => 'a', 'status' => 'cancelled']]], "Duplicate resume entry 'a'."];
    }

    #[DataProvider('invalidAguiApprovals')]
    public function test_invalid_agui_approval_is_rejected(array $payload, string $message): void
    {
        $request = (new ApprovalRequest('Approve', [new Action('a', 'browser')]))->withId(1);
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage($message);
        (new AGUIInputTranslator())->translate($payload, $request);
    }

    public function test_vercel_output_cannot_bypass_approval(): void
    {
        $request = (new ApprovalRequest('Approve', [new Action('a', 'browser')]))->withId(1);
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("Tool call 'a' is awaiting approval, not execution results.");
        (new VercelAIInputTranslator())->translate(['messages' => [[
            'role' => 'assistant', 'parts' => [[
                'type' => 'tool-browser', 'toolCallId' => 'a', 'state' => 'output-available', 'output' => 'done',
            ]],
        ]]], $request);
    }

    public function test_conflicting_duplicate_results_are_rejected(): void
    {
        $request = (new ToolResultsRequest([new ToolCall('browser', 'a', deferred: true)]))->withId(1);
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("Conflicting responses for tool call 'a'.");
        (new AGUIInputTranslator())->translate(['messages' => [
            ['role' => 'tool', 'toolCallId' => 'a', 'content' => 'one'],
            ['role' => 'tool', 'toolCallId' => 'a', 'content' => 'two'],
        ]], $request);
    }

    public function test_identical_duplicate_and_accepted_results_are_safe_to_restate(): void
    {
        $request = (new ToolResultsRequest(
            [new ToolCall('browser', 'b', deferred: true)],
            ['a' => ['result' => 'original']],
        ))->withId(4);
        $inputs = (new AGUIInputTranslator())->translate(['messages' => [
            ['role' => 'tool', 'toolCallId' => 'a', 'content' => 'original'],
            ['role' => 'tool', 'toolCallId' => 'b', 'content' => 'new'],
            ['role' => 'tool', 'toolCallId' => 'b', 'content' => 'new'],
        ]], $request);
        $this->assertSame(['a' => ['result' => 'original'], 'b' => ['result' => 'new']], $inputs);
    }

    public function test_expired_agui_resume_is_rejected(): void
    {
        $request = (new ApprovalRequest('Approve', [new Action('a', 'browser')], new DateTimeImmutable('-1 minute')))->withId(1);
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("Interrupt 'a' has expired.");
        (new AGUIInputTranslator())->translate(['resume' => [['interruptId' => 'a', 'status' => 'cancelled']]], $request);
    }

    public function test_resolved_agui_deferred_batch_accepts_a_result_map(): void
    {
        $request = (new ToolResultsRequest([new ToolCall('browser', 'a', deferred: true)]))->withId(3);
        $inputs = (new AGUIInputTranslator())->translate(['resume' => [[
            'interruptId' => '3', 'status' => 'resolved', 'payload' => ['a' => ['result' => false]],
        ]]], $request);
        $this->assertSame(['a' => ['result' => false]], $inputs);
    }

    public function test_duplicate_catalog_names_are_rejected(): void
    {
        $tool = ['name' => 'browser', 'description' => 'Browser', 'parameters' => ['type' => 'object']];
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("Duplicate frontend tool 'browser'.");
        (new AGUIInputTranslator())->tools(['tools' => [$tool, $tool]]);
    }

    public function test_null_message_list_is_rejected(): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("'messages' must be a list.");
        (new VercelAIInputTranslator())->translate(['messages' => null], new WaitForEventRequest('event'));
    }

    public function test_vercel_approval_must_identify_the_published_approval(): void
    {
        $request = (new ApprovalRequest('Approve', [new Action('a', 'browser')]))->withId(1);
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("Approval ID does not match tool call 'a'.");
        (new VercelAIInputTranslator())->translate(['messages' => [[
            'role' => 'assistant', 'parts' => [[
                'type' => 'tool-browser', 'toolCallId' => 'a', 'state' => 'approval-responded',
                'approval' => ['id' => 'other', 'approved' => true],
            ]],
        ]]], $request);
    }


    public function test_explicit_approval_updates_preserve_the_engines_latest_delivery_behavior(): void
    {
        $request = (new ApprovalRequest('Approve', [
            new Action('a', 'browser', decision: ActionDecision::Approved), new Action('b', 'browser'),
        ]))->withId(8);
        $inputs = (new VercelAIInputTranslator())->translate(['messages' => [[
            'role' => 'assistant', 'parts' => [[
                'type' => 'tool-browser', 'toolCallId' => 'a', 'state' => 'approval-responded',
                'approval' => ['id' => 'a', 'approved' => false],
            ]],
        ]]], $request);
        $this->assertSame(['a' => 'reject'], $inputs);
    }
}
