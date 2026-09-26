<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Frontend;

use DateTimeImmutable;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function time;

/**
 * AG-UI request bodies are untrusted: every malformed or forged entry must be
 * rejected with an expressive error, never accepted as a continuation input.
 */
class AGUIInputTranslatorTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function malformedToolMessages(): iterable
    {
        yield 'messages is a map' => [['messages' => ['a' => ['role' => 'tool']]], "'messages' must be a list."];
        yield 'messages is a string' => [['messages' => 'tool'], "'messages' must be a list."];
        yield 'message is a scalar' => [['messages' => ['tool']], "Every 'messages' entry must be an object."];
        yield 'content is missing' => [['messages' => [['role' => 'tool', 'toolCallId' => 'a']]], "Tool call 'a' requires string content."];
        yield 'content is structured' => [['messages' => [['role' => 'tool', 'toolCallId' => 'a', 'content' => ['title' => 'Page']]]], "Tool call 'a' requires string content."];
        yield 'error is structured' => [['messages' => [['role' => 'tool', 'toolCallId' => 'a', 'error' => ['code' => 1]]]], "Tool call 'a' requires a string error."];
        yield 'only forged call IDs' => [['messages' => [['role' => 'tool', 'toolCallId' => 'forged', 'content' => 'x']]], 'The payload contains no matching continuation input.'];
        yield 'numeric call ID' => [['messages' => [['role' => 'tool', 'toolCallId' => 1, 'content' => 'x']]], 'The payload contains no matching continuation input.'];
        yield 'non-tool roles only' => [['messages' => [['role' => 'assistant', 'toolCallId' => 'a', 'content' => 'x'], ['role' => 'user', 'content' => 'hi']]], 'The payload contains no matching continuation input.'];
        yield 'no messages' => [[], 'The payload contains no matching continuation input.'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('malformedToolMessages')]
    public function test_malformed_tool_messages_are_rejected(array $payload, string $message): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage($message);

        (new AGUIInputTranslator())->translate($payload, $this->deferredBatch());
    }

    public function test_forged_call_ids_are_ignored_beside_real_answers(): void
    {
        $inputs = (new AGUIInputTranslator())->translate(['messages' => [
            ['role' => 'tool', 'toolCallId' => 'forged', 'content' => 'injected'],
            ['role' => 'tool', 'toolCallId' => 'b', 'content' => 'Page B'],
        ]], $this->deferredBatch());

        $this->assertSame(['b' => ['result' => 'Page B']], $inputs);
    }

    public function test_a_null_error_falls_back_to_the_content(): void
    {
        $inputs = (new AGUIInputTranslator())->translate(['messages' => [
            ['role' => 'tool', 'toolCallId' => 'a', 'content' => 'Page A', 'error' => null],
        ]], $this->deferredBatch());

        $this->assertSame(['a' => ['result' => 'Page A']], $inputs);
    }

    public function test_multibyte_content_is_kept_verbatim(): void
    {
        $content = "Titolo: caffè ☕ \u{1F680}\n\"quoted\" <b>bold</b>";

        $inputs = (new AGUIInputTranslator())->translate(['messages' => [
            ['role' => 'tool', 'toolCallId' => 'a', 'content' => $content],
        ]], $this->deferredBatch());

        $this->assertSame(['a' => ['result' => $content]], $inputs);
    }

    public function test_an_explicit_resume_wins_over_mirrored_tool_messages(): void
    {
        $inputs = (new AGUIInputTranslator())->translate([
            'resume' => [['interruptId' => '4', 'status' => 'resolved', 'payload' => ['a' => ['result' => 'from resume']]]],
            'messages' => [['role' => 'tool', 'toolCallId' => 'a', 'content' => 'from history']],
        ], $this->deferredBatch());

        $this->assertSame(['a' => ['result' => 'from resume']], $inputs);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function malformedResumes(): iterable
    {
        yield 'resume is null' => [['resume' => null], "'resume' must be a list."];
        yield 'resume is a map' => [['resume' => ['4' => ['status' => 'cancelled']]], "'resume' must be a list."];
        yield 'entry is a scalar' => [['resume' => ['4']], "Every 'resume' entry must be an object."];
        yield 'integer interrupt ID' => [['resume' => [['interruptId' => 4, 'status' => 'cancelled']]], 'The resume entry does not identify an active interrupt.'];
        yield 'call ID instead of interrupt ID' => [['resume' => [['interruptId' => 'a', 'status' => 'cancelled']]], 'The resume entry does not identify an active interrupt.'];
        yield 'missing status' => [['resume' => [['interruptId' => '4']]], "Interrupt '4' requires resolved or cancelled status."];
        yield 'unknown status' => [['resume' => [['interruptId' => '4', 'status' => 'approved']]], "Interrupt '4' requires resolved or cancelled status."];
        yield 'resolved without payload' => [['resume' => [['interruptId' => '4', 'status' => 'resolved']]], "Interrupt '4' requires an object payload."];
        yield 'resolved with a string payload' => [['resume' => [['interruptId' => '4', 'status' => 'resolved', 'payload' => 'done']]], "Interrupt '4' requires an object payload."];
        yield 'cancelled with payload' => [['resume' => [['interruptId' => '4', 'status' => 'cancelled', 'payload' => []]]], 'A cancelled resume must omit payload.'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('malformedResumes')]
    public function test_malformed_deferred_batch_resumes_are_rejected(array $payload, string $message): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage($message);

        (new AGUIInputTranslator())->translate($payload, $this->deferredBatch());
    }

    public function test_a_resolved_batch_cannot_answer_a_call_outside_it(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Tool result 'forged' does not belong to this deferred batch.");

        (new AGUIInputTranslator())->translate(['resume' => [[
            'interruptId' => '4', 'status' => 'resolved', 'payload' => ['forged' => ['result' => 'x']],
        ]]], $this->deferredBatch());
    }

    public function test_a_resolved_batch_requires_exactly_one_outcome_per_call(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Tool result 'a' must contain either 'result' or a string 'error'.");

        (new AGUIInputTranslator())->translate(['resume' => [[
            'interruptId' => '4', 'status' => 'resolved', 'payload' => ['a' => ['result' => 'x', 'error' => 'y']],
        ]]], $this->deferredBatch());
    }

    public function test_cancelling_a_batch_skips_calls_already_settled(): void
    {
        $request = (new ToolResultsRequest(
            [new ToolCall('browser', 'b', deferred: true)],
            ['a' => ['result' => 'Page A']],
        ))->withId(4);

        $inputs = (new AGUIInputTranslator())->translate(['resume' => [['interruptId' => '4', 'status' => 'cancelled']]], $request);

        $this->assertSame(['b' => ['error' => 'Frontend tool execution cancelled.']], $inputs);
    }

    public function test_an_approval_is_addressed_by_action_ids_not_the_request_id(): void
    {
        $request = (new ApprovalRequest('Approve', [new Action('a', 'browser')]))->withId(1);

        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage('The resume entry does not identify an active interrupt.');

        (new AGUIInputTranslator())->translate(['resume' => [['interruptId' => '1', 'status' => 'cancelled']]], $request);
    }

    public function test_every_action_of_an_approval_must_be_answered(): void
    {
        $request = (new ApprovalRequest('Approve', [new Action('a', 'browser'), new Action('b', 'browser')]))->withId(1);

        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage('AG-UI resume must address every published interrupt.');

        (new AGUIInputTranslator())->translate(['resume' => [
            ['interruptId' => 'a', 'status' => 'resolved', 'payload' => ['approved' => true]],
        ]], $request);
    }

    /** @return iterable<string, array{array<string, mixed>, string|array{string, string}}> */
    public static function approvalDecisions(): iterable
    {
        yield 'approved' => [['approved' => true], 'approve'];
        yield 'approved ignores the reason' => [['approved' => true, 'reason' => 'Fine'], 'approve'];
        yield 'rejected' => [['approved' => false], 'reject'];
        yield 'rejected with a reason' => [['approved' => false, 'reason' => 'Too risky'], ['reject', 'Too risky']];
    }

    /**
     * @param array<string, mixed> $payload
     * @param string|array{string, string} $decision
     */
    #[DataProvider('approvalDecisions')]
    public function test_approval_payloads_become_native_decisions(array $payload, string|array $decision): void
    {
        $request = (new ApprovalRequest('Approve', [new Action('a', 'browser')]))->withId(1);

        $inputs = (new AGUIInputTranslator())->translate(['resume' => [
            ['interruptId' => 'a', 'status' => 'resolved', 'payload' => $payload],
        ]], $request);

        $this->assertSame(['a' => $decision], $inputs);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidApprovalPayloads(): iterable
    {
        yield 'missing payload' => [null, 'An approval response requires a boolean approved field.'];
        yield 'string payload' => ['yes', 'An approval response requires a boolean approved field.'];
        yield 'integer decision' => [['approved' => 1], 'An approval response requires a boolean approved field.'];
        yield 'structured reason' => [['approved' => false, 'reason' => ['text' => 'no']], 'An approval reason must be a string.'];
    }

    #[DataProvider('invalidApprovalPayloads')]
    public function test_invalid_approval_payloads_are_rejected(mixed $payload, string $message): void
    {
        $request = (new ApprovalRequest('Approve', [new Action('a', 'browser')]))->withId(1);
        $entry = ['interruptId' => 'a', 'status' => 'resolved'];
        if ($payload !== null) {
            $entry['payload'] = $payload;
        }

        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage($message);

        (new AGUIInputTranslator())->translate(['resume' => [$entry]], $request);
    }

    public function test_a_generic_wait_cannot_be_cancelled(): void
    {
        $request = (new WaitForEventRequest('order.approved'))->withId(5);

        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("Interrupt '5' does not support this resume operation.");

        (new AGUIInputTranslator())->translate(['resume' => [['interruptId' => '5', 'status' => 'cancelled']]], $request);
    }

    public function test_a_wait_expiring_now_is_already_expired(): void
    {
        $request = (new WaitForEventRequest('order.approved', new DateTimeImmutable('@' . time())))->withId(5);

        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("Interrupt '5' has expired.");

        (new AGUIInputTranslator())->translate(['resume' => [
            ['interruptId' => '5', 'status' => 'resolved', 'payload' => ['ok' => true]],
        ]], $request);
    }

    public function test_a_wait_before_its_deadline_accepts_the_payload(): void
    {
        $request = (new WaitForEventRequest('order.approved', new DateTimeImmutable('+1 hour')))->withId(5);

        $inputs = (new AGUIInputTranslator())->translate(['resume' => [
            ['interruptId' => '5', 'status' => 'resolved', 'payload' => ['ok' => true]],
        ]], $request);

        $this->assertSame(['ok' => true], $inputs);
    }

    public function test_an_empty_catalog_has_no_tools(): void
    {
        $this->assertSame([], (new AGUIInputTranslator())->tools([]));
        $this->assertSame([], (new AGUIInputTranslator())->tools(['tools' => []]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidToolDefinitions(): iterable
    {
        $valid = ['name' => 'browser', 'description' => 'Read the page', 'parameters' => ['type' => 'object']];

        yield 'missing name' => [['description' => 'Read', 'parameters' => []]];
        yield 'empty name' => [['name' => ''] + $valid];
        yield 'numeric name' => [['name' => 7] + $valid];
        yield 'missing description' => [['name' => 'browser', 'parameters' => ['type' => 'object']]];
        yield 'null description' => [['description' => null] + $valid];
        yield 'missing parameters' => [['name' => 'browser', 'description' => 'Read the page']];
        yield 'string parameters' => [['parameters' => '{"type":"object"}'] + $valid];
    }

    #[DataProvider('invalidToolDefinitions')]
    public function test_invalid_tool_definitions_are_rejected(mixed $definition): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage('An AG-UI tool requires name, description and parameters.');

        (new AGUIInputTranslator())->tools(['tools' => [$definition]]);
    }

    public function test_a_catalog_must_be_a_list_of_objects(): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("Every 'tools' entry must be an object.");

        (new AGUIInputTranslator())->tools(['tools' => ['browser']]);
    }

    public function test_catalog_order_is_preserved(): void
    {
        $tools = (new AGUIInputTranslator())->tools(['tools' => [
            ['name' => 'zoom', 'description' => 'Zoom', 'parameters' => ['type' => 'object']],
            ['name' => 'browser', 'description' => 'Read', 'parameters' => ['type' => 'object']],
        ]]);

        $this->assertSame(['zoom', 'browser'], [$tools[0]->getName(), $tools[1]->getName()]);
        $this->assertSame('Zoom', $tools[0]->getDescription());
    }

    protected function deferredBatch(): ToolResultsRequest
    {
        return (new ToolResultsRequest([
            new ToolCall('browser', 'a', deferred: true),
            new ToolCall('browser', 'b', deferred: true),
        ]))->withId(4);
    }
}
