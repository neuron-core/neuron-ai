<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Classifier;

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
    public function test_invalid_distributions_are_rejected(array $probabilities): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProbabilityDistribution($probabilities);
    }

    public static function invalid_distributions(): iterable
    {
        yield 'empty' => [[]];
        yield 'single outcome' => [[1.0]];
        yield 'negative' => [[-0.1, 1.1]];
        yield 'above one' => [[1.1, 0.0]];
        yield 'not normalized' => [[0.2, 0.2]];
        yield 'zero mass' => [[0.0, 0.0]];
        yield 'nan' => [[NAN, 0.5]];
        yield 'infinity' => [[INF, 0.5]];
        yield 'numeric strings' => [['0.5', '0.5']];
        yield 'boolean' => [[true, false]];
    }

    #[DataProvider('invalid_definitions')]
    public function test_invalid_definitions_are_rejected(string $type, array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);

        new $type(...$arguments);
    }

    public static function invalid_definitions(): iterable
    {
        yield 'empty choice instruction' => [Choice::class, [' ', ['a' => 'A', 'b' => 'B']]];
        yield 'one option' => [Choice::class, ['Which?', ['a' => 'A']]];
        yield 'numeric option ids' => [Choice::class, ['Which?', ['A', 'B']]];
        yield 'blank option id' => [Choice::class, ['Which?', [' ' => 'A', 'b' => 'B']]];
        yield 'blank option description' => [Choice::class, ['Which?', ['a' => '', 'b' => 'B']]];
        yield 'invalid option description' => [Choice::class, ['Which?', ['a' => [], 'b' => 'B']]];
        yield 'empty score instruction' => [Score::class, ['', ['Low', 'High']]];
        yield 'one level' => [Score::class, ['How much?', ['Low']]];
        yield 'unordered levels' => [Score::class, ['How much?', [1 => 'Low', 0 => 'High']]];
        yield 'blank level' => [Score::class, ['How much?', ['Low', ' ']]];
        yield 'invalid level' => [Score::class, ['How much?', ['Low', 42]]];
        yield 'empty boolean instruction' => [Boolean::class, ['']];
    }

    #[DataProvider('invalid_inputs')]
    public function test_input_must_be_json_compatible_data(string|array $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClassificationRequest($input, ['check' => new Boolean('Is this valid?')]);
    }

    public static function invalid_inputs(): iterable
    {
        yield 'nested object' => [['nested' => [new stdClass()]]];
        yield 'non-finite number' => [['value' => INF]];
        yield 'invalid utf8' => ["\xB1\x31"];
        $recursive = [];
        $recursive['self'] = &$recursive;
        yield 'recursive array' => [$recursive];
    }

    #[DataProvider('invalid_questions')]
    public function test_requests_require_named_typed_questions(array $questions): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClassificationRequest('Input', $questions);
    }

    public static function invalid_questions(): iterable
    {
        yield 'empty' => [[]];
        yield 'unnamed' => [[new Boolean('True?')]];
        yield 'blank name' => [[' ' => new Boolean('True?')]];
        yield 'wrong type' => [['question' => new stdClass()]];
    }

    #[DataProvider('invalid_probabilities')]
    public function test_boolean_rejects_invalid_probabilities(float $probability): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BooleanResult(new Boolean('True?'), $probability);
    }

    public static function invalid_probabilities(): iterable
    {
        yield [-0.1];
        yield [1.1];
        yield [NAN];
        yield [INF];
    }

    public function test_boolean_accepts_both_extremes_without_thresholding(): void
    {
        $definition = new Boolean('True?');

        self::assertSame(0.0, (new BooleanResult($definition, 0.0))->probability);
        self::assertSame(1.0, (new BooleanResult($definition, 1.0))->probability);
    }

    public function test_choice_probabilities_must_match_the_options(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChoiceResult(new Choice('Which?', ['a' => 'A', 'b' => 'B']), ['a' => 0.5, 'c' => 0.5]);
    }

    public function test_score_probabilities_must_match_the_levels(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ScoreResult(new Score('How much?', ['Low', 'High']), [1 => 0.5, 2 => 0.5]);
    }

    #[DataProvider('invalid_answers')]
    public function test_answers_must_match_the_request(array $answers): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClassificationResult(
            new ClassificationRequest('Input', ['check' => new Boolean('True?')]),
            $answers,
        );
    }

    public static function invalid_answers(): iterable
    {
        yield 'missing answer' => [[]];
        yield 'unknown answer' => [['other' => new BooleanResult(new Boolean('True?'), 0.5)]];
        yield 'extra answer' => [[
            'check' => new BooleanResult(new Boolean('True?'), 0.5),
            'extra' => new BooleanResult(new Boolean('True?'), 0.5),
        ]];
        yield 'wrong primitive' => [['check' => new ScoreResult(new Score('True?', ['No', 'Yes']), [0.5, 0.5])]];
        yield 'wrong definition' => [['check' => new BooleanResult(new Boolean('Different?'), 0.5)]];
        yield 'invalid result' => [['check' => new stdClass()]];
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

        new ClassificationResult($request, ['choice' => $answer]);
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
