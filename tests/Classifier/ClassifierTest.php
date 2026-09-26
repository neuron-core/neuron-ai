<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Classifier;

use ArrayObject;
use InvalidArgumentException;
use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\BooleanResult;
use NeuronAI\Classifier\Choice;
use NeuronAI\Classifier\ChoiceResult;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Classifier\ClassificationResult;
use NeuronAI\Classifier\ProbabilityDistribution;
use NeuronAI\Classifier\Score;
use NeuronAI\Classifier\ScoreResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_keys;
use function array_sum;

use const INF;
use const NAN;

class ClassifierTest extends TestCase
{
    public function test_named_questions_keep_their_types_and_definitions(): void
    {
        $department = new Choice('Which department?', ['billing' => 'Payments', 'shipping' => 'Deliveries']);
        $severity = new Score('How severe?', ['Cosmetic', 'Workaround available', 'Blocked']);
        $refund = new Boolean('Is a refund requested?');
        $request = new ClassificationRequest(
            ['ticket' => 'My package never arrived.', 'customer' => ['orders' => 3, 'active' => true]],
            ['department' => $department, 'severity' => $severity, 'refund' => $refund],
        );
        $answers = [
            'refund' => new BooleanResult($refund, 0.2),
            'department' => new ChoiceResult($department, ['shipping' => 0.8, 'billing' => 0.2]),
            'severity' => new ScoreResult($severity, [0.1, 0.3, 0.6]),
        ];

        $result = new ClassificationResult($request, $answers);

        self::assertSame($answers, $result->answers);
        self::assertSame($answers['department'], $result->choice('department'));
        self::assertSame($answers['severity'], $result->score('severity'));
        self::assertSame($answers['refund'], $result->boolean('refund'));
        self::assertSame('shipping', $result->choice('department')->choice);
        self::assertEqualsWithDelta(1.5, $result->score('severity')->score, 1e-12);
        self::assertSame(0.2, $result->boolean('refund')->probability);
        self::assertSame($department, $answers['department']->definition);
    }

    public function test_choice_ties_follow_definition_order(): void
    {
        $choice = new Choice('Which?', ['first' => 'First', 'second' => 'Second']);
        $result = new ChoiceResult($choice, ['second' => 0.5, 'first' => 0.5]);

        self::assertSame('first', $result->choice);
    }

    public function test_score_preserves_distributions_with_the_same_mean(): void
    {
        $score = new Score('How severe?', ['Low', 'Medium', 'High']);
        $certain = new ScoreResult($score, [0, 1, 0]);
        $uncertain = new ScoreResult($score, [0.5, 0, 0.5]);

        self::assertSame(1.0, $certain->score);
        self::assertSame($certain->score, $uncertain->score);
        self::assertNotSame($certain->distribution->probabilities, $uncertain->distribution->probabilities);
    }

    public function test_distribution_accepts_and_normalizes_rounding_error(): void
    {
        $distribution = new ProbabilityDistribution([0.3333333, 0.3333333, 0.3333333]);

        self::assertEqualsWithDelta(1.0, array_sum($distribution->probabilities), 1e-12);
    }

    #[DataProvider('invalid_distributions')]
    public function test_invalid_distributions_are_rejected(array $probabilities, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ProbabilityDistribution($probabilities);
    }

    public static function invalid_distributions(): iterable
    {
        $outcomes = 'A probability distribution requires at least two outcomes.';
        $range = 'Probabilities must be finite numbers between zero and one.';
        $sum = 'Probabilities must sum to one.';

        yield 'empty' => [[], $outcomes];
        yield 'single outcome' => [[1.0], $outcomes];
        yield 'negative' => [[-0.1, 1.1], $range];
        yield 'above one' => [[1.1, 0.0], $range];
        yield 'not normalized' => [[0.2, 0.2], $sum];
        yield 'zero mass' => [[0.0, 0.0], $sum];
        yield 'beyond the rounding tolerance' => [[0.5, 0.500002], $sum];
        yield 'nan' => [[NAN, 0.5], $range];
        yield 'infinity' => [[INF, 0.5], $range];
        yield 'numeric strings' => [['0.5', '0.5'], $range];
        yield 'boolean' => [[true, false], $range];
        yield 'null' => [[null, 1.0], $range];
    }

    #[DataProvider('invalid_definitions')]
    public function test_invalid_definitions_are_rejected(string $type, array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new $type(...$arguments);
    }

    public static function invalid_definitions(): iterable
    {
        $options = 'Choice options require non-empty string identifiers and descriptions.';
        $levels = 'A score requires a list of at least two ordered levels.';
        $level = 'Score levels require non-empty string descriptions.';

        yield 'empty choice instruction' => [Choice::class, [' ', ['a' => 'A', 'b' => 'B']], 'Choice instructions cannot be empty.'];
        yield 'one option' => [Choice::class, ['Which?', ['a' => 'A']], 'A choice requires at least two options.'];
        yield 'numeric option ids' => [Choice::class, ['Which?', ['A', 'B']], $options];
        yield 'numeric string option id' => [Choice::class, ['Which?', ['1' => 'A', 'b' => 'B']], $options];
        yield 'blank option id' => [Choice::class, ['Which?', [' ' => 'A', 'b' => 'B']], $options];
        yield 'blank option description' => [Choice::class, ['Which?', ['a' => '', 'b' => 'B']], $options];
        yield 'invalid option description' => [Choice::class, ['Which?', ['a' => [], 'b' => 'B']], $options];
        yield 'empty score instruction' => [Score::class, ['', ['Low', 'High']], 'Score instructions cannot be empty.'];
        yield 'no levels' => [Score::class, ['How much?', []], $levels];
        yield 'one level' => [Score::class, ['How much?', ['Low']], $levels];
        yield 'unordered levels' => [Score::class, ['How much?', [1 => 'Low', 0 => 'High']], $levels];
        yield 'named levels' => [Score::class, ['How much?', ['low' => 'Low', 'high' => 'High']], $levels];
        yield 'blank level' => [Score::class, ['How much?', ['Low', ' ']], $level];
        yield 'invalid level' => [Score::class, ['How much?', ['Low', 42]], $level];
        yield 'empty boolean instruction' => [Boolean::class, [''], 'Boolean instructions cannot be empty.'];
        yield 'whitespace boolean instruction' => [Boolean::class, ["\n\t "], 'Boolean instructions cannot be empty.'];
    }

    #[DataProvider('invalid_inputs')]
    public function test_input_must_be_json_compatible_data(string|array $input, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ClassificationRequest($input, ['check' => new Boolean('Is this valid?')]);
    }

    public static function invalid_inputs(): iterable
    {
        $json = 'Classification input must be JSON-compatible.';
        $values = 'Classification input accepts only arrays, scalars and null.';

        yield 'nested object' => [['nested' => [new stdClass()]], $values];
        yield 'serializable object' => [['nested' => new ArrayObject(['a' => 1])], $values];
        yield 'closure' => [['callback' => static fn (): int => 1], $values];
        yield 'non-finite number' => [['value' => INF], $json];
        yield 'invalid utf8' => ["\xB1\x31", $json];
        yield 'invalid utf8 in a nested value' => [['text' => ['ok', "\xB1\x31"]], $json];
        $recursive = [];
        $recursive['self'] = &$recursive;
        yield 'recursive array' => [$recursive, $json];
    }

    #[DataProvider('invalid_questions')]
    public function test_requests_require_named_typed_questions(array $questions, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ClassificationRequest('Input', $questions);
    }

    public static function invalid_questions(): iterable
    {
        $identifiers = 'Question identifiers must be non-empty strings.';

        yield 'empty' => [[], 'A classification request requires at least one question.'];
        yield 'unnamed' => [[new Boolean('True?')], $identifiers];
        yield 'blank name' => [[' ' => new Boolean('True?')], $identifiers];
        yield 'wrong type' => [['question' => new stdClass()], 'Questions must be Choice, Score or Boolean definitions.'];
        yield 'definition result' => [['question' => new BooleanResult(new Boolean('True?'), 0.5)], 'Questions must be Choice, Score or Boolean definitions.'];
    }

    #[DataProvider('invalid_probabilities')]
    public function test_boolean_rejects_invalid_probabilities(float $probability): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Boolean probability must be finite and between zero and one.');

        new BooleanResult(new Boolean('True?'), $probability);
    }

    public static function invalid_probabilities(): iterable
    {
        yield [-0.1];
        yield [-0.0000001];
        yield [1.1];
        yield [1.0000001];
        yield [NAN];
        yield [INF];
        yield [-INF];
    }

    public function test_boolean_accepts_both_extremes_without_thresholding(): void
    {
        $definition = new Boolean('True?');

        self::assertSame(0.0, (new BooleanResult($definition, 0.0))->probability);
        self::assertSame(1.0, (new BooleanResult($definition, 1.0))->probability);
    }

    public static function mismatched_choice_probabilities(): iterable
    {
        yield 'unknown option' => [['a' => 0.5, 'c' => 0.5]];
        yield 'missing option' => [['a' => 1.0]];
        yield 'extra option' => [['a' => 0.5, 'b' => 0.0, 'c' => 0.5]];
    }

    #[DataProvider('mismatched_choice_probabilities')]
    public function test_choice_probabilities_must_match_the_options(array $probabilities): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Choice probabilities must cover exactly the defined options.');

        new ChoiceResult(new Choice('Which?', ['a' => 'A', 'b' => 'B']), $probabilities);
    }

    public static function mismatched_score_probabilities(): iterable
    {
        yield 'shifted levels' => [[1 => 0.5, 2 => 0.5]];
        yield 'missing level' => [[0 => 1.0]];
        yield 'extra level beyond the scale' => [[0 => 0.5, 1 => 0.0, 2 => 0.5]];
    }

    #[DataProvider('mismatched_score_probabilities')]
    public function test_score_probabilities_must_match_the_levels(array $probabilities): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Score probabilities must cover exactly the defined levels.');

        new ScoreResult(new Score('How much?', ['Low', 'High']), $probabilities);
    }

    #[DataProvider('invalid_answers')]
    public function test_answers_must_match_the_request(array $answers, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ClassificationResult(
            new ClassificationRequest('Input', ['check' => new Boolean('True?')]),
            $answers,
        );
    }

    public static function invalid_answers(): iterable
    {
        $identifiers = 'Classification answers must match the requested question identifiers.';
        $definition = "Answer 'check' must match its requested question definition.";

        yield 'missing answer' => [[], $identifiers];
        yield 'unknown answer' => [['other' => new BooleanResult(new Boolean('True?'), 0.5)], $identifiers];
        yield 'extra answer' => [[
            'check' => new BooleanResult(new Boolean('True?'), 0.5),
            'extra' => new BooleanResult(new Boolean('True?'), 0.5),
        ], $identifiers];
        yield 'wrong primitive' => [['check' => new ScoreResult(new Score('True?', ['No', 'Yes']), [0.5, 0.5])], $definition];
        yield 'wrong definition' => [['check' => new BooleanResult(new Boolean('Different?'), 0.5)], $definition];
        yield 'invalid result' => [['check' => new stdClass()], $definition];
    }

    public function test_equivalent_definitions_are_accepted(): void
    {
        $request = new ClassificationRequest('Input', ['check' => new Boolean('True?')]);
        $answer = new BooleanResult(new Boolean('True?'), 0.7);

        self::assertSame($answer, (new ClassificationResult($request, ['check' => $answer]))->answers['check']);
    }

    public function test_reordered_choice_definitions_are_rejected(): void
    {
        $request = new ClassificationRequest('Input', [
            'choice' => new Choice('Which?', ['first' => 'First', 'second' => 'Second']),
        ]);
        $answer = new ChoiceResult(
            new Choice('Which?', ['second' => 'Second', 'first' => 'First']),
            ['first' => 0.5, 'second' => 0.5],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Answer 'choice' must match its requested question definition.");

        new ClassificationResult($request, ['choice' => $answer]);
    }

    public function test_distribution_accepts_rounding_error_within_the_tolerance(): void
    {
        $above = new ProbabilityDistribution(['a' => 0.5, 'b' => 0.5000009]);
        $below = new ProbabilityDistribution(['a' => 0.5, 'b' => 0.4999991]);

        self::assertEqualsWithDelta(1.0, array_sum($above->probabilities), 1e-12);
        self::assertEqualsWithDelta(1.0, array_sum($below->probabilities), 1e-12);
    }

    public function test_distribution_keeps_the_outcome_keys_in_response_order(): void
    {
        $distribution = new ProbabilityDistribution(['b' => 0.25, 'a' => 0.75]);

        self::assertSame(['b', 'a'], array_keys($distribution->probabilities));
        self::assertEqualsWithDelta(0.25, $distribution->probabilities['b'], 1e-12);
    }

    public function test_accepted_rounding_error_cannot_push_a_score_beyond_its_top_level(): void
    {
        $result = new ScoreResult(new Score('How severe?', ['Low', 'Medium', 'High']), [0.0, 0.0000009, 1.0]);

        self::assertLessThanOrEqual(2.0, $result->score);
        self::assertLessThanOrEqual(1.0, $result->distribution->probabilities[2]);
    }

    public function test_the_most_probable_option_wins_wherever_it_is_defined(): void
    {
        $choice = new Choice('Which?', ['low' => 'Low', 'mid' => 'Mid', 'high' => 'High']);

        self::assertSame('high', (new ChoiceResult($choice, ['low' => 0.2, 'mid' => 0.3, 'high' => 0.5]))->choice);
        self::assertSame('mid', (new ChoiceResult($choice, ['high' => 0.3, 'low' => 0.2, 'mid' => 0.5]))->choice);
    }

    public function test_request_keeps_json_compatible_input_unchanged(): void
    {
        $input = [
            'text' => "Grüße, 世界 👋 \"quoted\" <b>tag</b>",
            'nested' => ['count' => 3, 'ratio' => 0.5, 'active' => false, 'missing' => null],
            'list' => ['a', 1, true],
        ];
        $choice = new Choice('Which language?', ['de' => 'Deutsch', 'zh' => '中文']);

        $request = new ClassificationRequest($input, ['sprache' => $choice]);

        self::assertSame($input, $request->input);
        self::assertSame(['sprache' => $choice], $request->questions);
    }

    public function test_score_probabilities_can_arrive_in_any_order(): void
    {
        $result = new ScoreResult(new Score('How much?', ['Low', 'High']), [1 => 0.8, 0 => 0.2]);

        self::assertSame(0.8, $result->score);
    }

    #[DataProvider('invalid_accessors')]
    public function test_accessors_reject_missing_or_mismatched_answers(string $accessor, string $id, string $message): void
    {
        $choice = new Choice('Which?', ['a' => 'A', 'b' => 'B']);
        $score = new Score('How much?', ['Low', 'High']);
        $boolean = new Boolean('True?');
        $request = new ClassificationRequest('Input', [
            'category' => $choice,
            'severity' => $score,
            'check' => $boolean,
        ]);
        $result = new ClassificationResult($request, [
            'category' => new ChoiceResult($choice, ['a' => 0.5, 'b' => 0.5]),
            'severity' => new ScoreResult($score, [0.5, 0.5]),
            'check' => new BooleanResult($boolean, 0.5),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Classification answer '{$id}' {$message}.");

        $result->{$accessor}($id);
    }

    public static function invalid_accessors(): iterable
    {
        yield 'missing choice' => ['choice', 'missing', 'does not exist'];
        yield 'missing score' => ['score', 'missing', 'does not exist'];
        yield 'missing boolean' => ['boolean', 'missing', 'does not exist'];
        yield 'score as choice' => ['choice', 'severity', 'is not a ChoiceResult'];
        yield 'boolean as choice' => ['choice', 'check', 'is not a ChoiceResult'];
        yield 'choice as score' => ['score', 'category', 'is not a ScoreResult'];
        yield 'boolean as score' => ['score', 'check', 'is not a ScoreResult'];
        yield 'choice as boolean' => ['boolean', 'category', 'is not a BooleanResult'];
        yield 'score as boolean' => ['boolean', 'severity', 'is not a BooleanResult'];
    }
}
