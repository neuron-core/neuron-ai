<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Testing;

use InvalidArgumentException;
use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\Choice;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Classifier\Score;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Testing\FakeClassifier;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function serialize;
use function unserialize;

use const NAN;

class FakeClassifierTest extends TestCase
{
    public function test_builds_typed_results_using_request_definitions(): void
    {
        $request = new ClassificationRequest(['ticket' => 'Please refund me.'], [
            'department' => new Choice('Which department?', ['billing' => 'Payments', 'shipping' => 'Deliveries']),
            'severity' => new Score('How severe?', ['Low', 'Medium', 'High']),
            'refund' => new Boolean('Is a refund requested?'),
        ]);
        $classifier = new FakeClassifier([[
            'refund' => 0.95,
            'severity' => [0.1, 0.3, 0.6],
            'department' => ['shipping' => 0.2, 'billing' => 0.8],
        ]]);

        $result = $classifier->classify($request);

        self::assertSame('billing', $result->choice('department')->choice);
        self::assertSame(['shipping' => 0.2, 'billing' => 0.8], $result->choice('department')->distribution->probabilities);
        self::assertEqualsWithDelta(1.5, $result->score('severity')->score, 1e-12);
        self::assertSame(0.95, $result->boolean('refund')->probability);
        self::assertSame($request->questions['department'], $result->choice('department')->definition);
        self::assertSame($request->questions['severity'], $result->score('severity')->definition);
        self::assertSame($request->questions['refund'], $result->boolean('refund')->definition);
        self::assertSame([$request], $classifier->getRecorded());
        self::assertSame(1, $classifier->getCallCount());
        $classifier->assertCallCount(1);
        $classifier->assertSent(static fn (ClassificationRequest $sent): bool => $sent->input === ['ticket' => 'Please refund me.']);
    }

    public function test_consumes_one_answer_map_per_call_and_records_in_order(): void
    {
        $classifier = new FakeClassifier([
            ['refund' => 1],
            ['urgent' => 0],
        ]);
        $first = new ClassificationRequest('First', ['refund' => new Boolean('Refund?')]);
        $second = new ClassificationRequest('Second', ['urgent' => new Boolean('Urgent?')]);

        self::assertSame(1.0, $classifier->classify($first)->boolean('refund')->probability);
        self::assertSame(0.0, $classifier->classify($second)->boolean('urgent')->probability);
        self::assertSame([$first, $second], $classifier->getRecorded());
        $classifier->assertCallCount(2);
        $classifier->assertSent(static fn (ClassificationRequest $request): bool => $request->input === 'Second');
    }

    public function test_empty_queue_throws_provider_exception_and_records_attempt(): void
    {
        $classifier = new FakeClassifier();
        $request = new ClassificationRequest('Input', ['check' => new Boolean('True?')]);

        try {
            $classifier->classify($request);
            self::fail('Expected an exhausted response queue.');
        } catch (ProviderException $exception) {
            self::assertStringContainsString('response queue is empty', $exception->getMessage());
            self::assertSame([$request], $classifier->getRecorded());
            $classifier->assertCallCount(1);
        }
    }

    public function test_exhausted_queue_does_not_repeat_the_last_response(): void
    {
        $classifier = new FakeClassifier([['check' => 0.9]]);
        $request = new ClassificationRequest('Input', ['check' => new Boolean('True?')]);
        $classifier->classify($request);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('response queue is empty');

        $classifier->classify($request);
    }

    public function test_choice_ties_follow_definition_order(): void
    {
        $classifier = new FakeClassifier([['choice' => ['second' => 0.5, 'first' => 0.5]]]);
        $request = new ClassificationRequest('Input', [
            'choice' => new Choice('Which?', ['first' => 'First', 'second' => 'Second']),
        ]);

        self::assertSame('first', $classifier->classify($request)->choice('choice')->choice);
    }

    #[DataProvider('invalid_responses')]
    public function test_rejects_invalid_queued_answers(Choice|Score|Boolean $question, array $response): void
    {
        $classifier = new FakeClassifier([$response]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage("Invalid FakeClassifier answer 'check'");

        $classifier->classify(new ClassificationRequest('Input', ['check' => $question]));
    }

    public static function invalid_responses(): iterable
    {
        $boolean = new Boolean('True?');
        $choice = new Choice('Which?', ['a' => 'A', 'b' => 'B']);
        $score = new Score('How much?', ['Low', 'High']);

        yield 'boolean distribution' => [$boolean, ['check' => [0.5, 0.5]]];
        yield 'boolean string' => [$boolean, ['check' => '0.5']];
        yield 'boolean literal' => [$boolean, ['check' => true]];
        yield 'boolean out of range' => [$boolean, ['check' => 1.1]];
        yield 'boolean non-finite' => [$boolean, ['check' => NAN]];
        yield 'choice scalar' => [$choice, ['check' => 0.8]];
        yield 'choice missing option' => [$choice, ['check' => ['a' => 1.0]]];
        yield 'choice unknown option' => [$choice, ['check' => ['a' => 0.5, 'c' => 0.5]]];
        yield 'score scalar' => [$score, ['check' => 0.8]];
        yield 'score unknown level' => [$score, ['check' => [1 => 0.5, 2 => 0.5]]];
        yield 'score invalid mass' => [$score, ['check' => [0.2, 0.2]]];
        yield 'score invalid probability' => [$score, ['check' => ['0.5', '0.5']]];
    }

    #[DataProvider('mismatched_answers')]
    public function test_answer_identifiers_must_match_the_request(array $response): void
    {
        $classifier = new FakeClassifier([$response]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('question identifiers');

        $classifier->classify(new ClassificationRequest('Input', ['check' => new Boolean('True?')]));
    }

    public static function mismatched_answers(): iterable
    {
        yield 'missing' => [[]];
        yield 'extra' => [['check' => 0.5, 'other' => 0.5]];
        yield 'unknown' => [['other' => 0.5]];
    }

    public function test_invalid_response_is_recorded_and_consumed(): void
    {
        $classifier = new FakeClassifier([['check' => 2], ['check' => 0.8]]);
        $request = new ClassificationRequest('Input', ['check' => new Boolean('True?')]);

        try {
            $classifier->classify($request);
            self::fail('Expected an invalid response.');
        } catch (ProviderException $exception) {
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
        }

        self::assertSame(0.8, $classifier->classify($request)->boolean('check')->probability);
        self::assertSame([$request, $request], $classifier->getRecorded());
    }

    public function test_queue_and_recordings_survive_serialization(): void
    {
        $classifier = new FakeClassifier([['check' => 0.9], ['check' => 0.1]]);
        $request = new ClassificationRequest('Input', ['check' => new Boolean('True?')]);
        $classifier->classify($request);

        $restored = unserialize(serialize($classifier));

        self::assertInstanceOf(FakeClassifier::class, $restored);
        self::assertEquals([$request], $restored->getRecorded());
        self::assertSame(0.1, $restored->classify($request)->boolean('check')->probability);
        $restored->assertCallCount(2);
    }

    public function test_unused_fake_has_no_recorded_calls(): void
    {
        $classifier = new FakeClassifier([['check' => 0.5]]);

        self::assertSame([], $classifier->getRecorded());
        self::assertSame(0, $classifier->getCallCount());
        $classifier->assertNothingSent();
    }

    /**
     * @param 'count'|'nothing'|'sent' $assertion
     */
    #[DataProvider('failing_assertions')]
    public function test_assertion_helpers_report_unmet_expectations(string $assertion): void
    {
        $classifier = new FakeClassifier([['check' => 0.5]]);
        $classifier->classify(new ClassificationRequest('Input', ['check' => new Boolean('True?')]));

        $this->expectException(AssertionFailedError::class);

        match ($assertion) {
            'count' => $classifier->assertCallCount(2),
            'nothing' => $classifier->assertNothingSent(),
            'sent' => $classifier->assertSent(static fn (ClassificationRequest $request): bool => $request->input === 'Other'),
        };
    }

    public static function failing_assertions(): iterable
    {
        yield ['count'];
        yield ['nothing'];
        yield ['sent'];
    }

    #[DataProvider('invalid_queues')]
    public function test_constructor_requires_a_list_of_answer_maps(array $responses, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new FakeClassifier($responses);
    }

    public static function invalid_queues(): iterable
    {
        yield 'single answer map' => [['check' => 0.5], 'FakeClassifier expects a list of answer maps, one per classify() call.'];
        yield 'scalar entry' => [[0.5], 'Each FakeClassifier response must be an answer map.'];
    }
}
