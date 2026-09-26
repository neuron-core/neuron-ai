<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Interrupt;

use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Tests\Agent\Interrupt\Stub\ExposedToolInputTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared checks every protocol translator applies to untrusted frontend payloads.
 */
class ToolInputTranslatorTest extends TestCase
{
    public function test_entries_default_to_an_empty_list(): void
    {
        $this->assertSame([], (new ExposedToolInputTranslator())->exposeEntries([], 'messages'));
    }

    public function test_entries_are_returned_as_given(): void
    {
        $entries = [['id' => 'a'], ['id' => 'b']];

        $this->assertSame($entries, (new ExposedToolInputTranslator())->exposeEntries(['messages' => $entries], 'messages'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function malformedEntries(): iterable
    {
        yield 'string' => ['messages', "'messages' must be a list."];
        yield 'null' => [null, "'messages' must be a list."];
        yield 'object instead of list' => [['first' => ['id' => 'a']], "'messages' must be a list."];
        yield 'scalar entry' => [[['id' => 'a'], 'b'], "Every 'messages' entry must be an object."];
        yield 'null entry' => [[null], "Every 'messages' entry must be an object."];
    }

    #[DataProvider('malformedEntries')]
    public function test_malformed_entries_are_refused(mixed $entries, string $message): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage($message);

        (new ExposedToolInputTranslator())->exposeEntries(['messages' => $entries], 'messages');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string|array{string, string}}>
     */
    public static function approvals(): iterable
    {
        yield 'approved' => [['approved' => true], 'approve'];
        yield 'approved ignores a reason' => [['approved' => true, 'reason' => 'ok'], 'approve'];
        yield 'rejected' => [['approved' => false], 'reject'];
        yield 'rejected with a reason' => [['approved' => false, 'reason' => 'Too expensive'], ['reject', 'Too expensive']];
    }

    /**
     * @param array<string, mixed> $payload
     * @param string|array{string, string} $expected
     */
    #[DataProvider('approvals')]
    public function test_approval_responses_map_to_native_decisions(array $payload, string|array $expected): void
    {
        $this->assertSame($expected, (new ExposedToolInputTranslator())->exposeApproval($payload));
    }

    /**
     * Only a boolean true approves: loosely truthy values must never read as consent.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidApprovals(): iterable
    {
        $boolean = 'An approval response requires a boolean approved field.';

        yield 'not an object' => ['approve', $boolean];
        yield 'missing field' => [[], $boolean];
        yield 'string true' => [['approved' => 'true'], $boolean];
        yield 'integer one' => [['approved' => 1], $boolean];
        yield 'null' => [['approved' => null], $boolean];
        yield 'edited arguments' => [['approved' => true, 'editedArgs' => ['amount' => 1]], 'Approval with edited arguments is not supported.'];
        yield 'null edited arguments' => [['approved' => true, 'editedArgs' => null], 'Approval with edited arguments is not supported.'];
        yield 'non string reason' => [['approved' => false, 'reason' => ['text' => 'no']], 'An approval reason must be a string.'];
    }

    #[DataProvider('invalidApprovals')]
    public function test_invalid_approval_responses_are_refused(mixed $payload, string $message): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage($message);

        (new ExposedToolInputTranslator())->exposeApproval($payload);
    }

    public function test_repeated_identical_answers_are_accepted_once(): void
    {
        $translator = new ExposedToolInputTranslator();

        $answers = $translator->exposeAnswer(['a' => 'approve'], 'a', 'approve');

        $this->assertSame(['a' => 'approve'], $answers);
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function conflictingAnswers(): iterable
    {
        yield 'another decision' => ['approve', 'reject'];
        yield 'a missing answer' => [['result' => 'x'], null];
        // Loosely equal outcomes are still different answers.
        yield 'integer and numeric string' => [['result' => 0], ['result' => '0']];
        yield 'false and null' => [['result' => false], ['result' => null]];
        yield 'true and one' => [['result' => true], ['result' => 1]];
    }

    #[DataProvider('conflictingAnswers')]
    public function test_conflicting_answers_for_one_call_are_refused(mixed $recorded, mixed $answer): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage("Conflicting responses for tool call 'a'.");

        (new ExposedToolInputTranslator())->exposeAnswer(['a' => $recorded], 'a', $answer);
    }
}
