<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Classifier\Score;
use NeuronAI\Evaluation\Assertions\ClassifierJudge;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Testing\FakeClassifier;
use NeuronAI\Tests\Evaluation\Stub\ClassifierJudgeEvaluator;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use const INF;
use const NAN;

class ClassifierJudgeTest extends TestCase
{
    #[DataProvider('boolean_verdicts')]
    public function test_boolean_probability_is_thresholded_without_becoming_a_binary_score(
        float $probability,
        float $threshold,
        bool $passed,
    ): void {
        $classifier = new FakeClassifier([['judgment' => $probability]]);
        $judge = new ClassifierJudge($classifier, new Boolean('Is the response relevant?'), $threshold);

        $result = $judge->evaluate('The response');

        self::assertSame($passed, $result->passed);
        self::assertSame($probability, $result->score);
        self::assertSame('boolean', $result->context['type']);
        self::assertSame(['false' => 1 - $probability, 'true' => $probability], $result->context['probabilities']);
        self::assertStringContainsString('Probability of true', $result->message);
        self::assertStringContainsString($passed ? 'meets threshold' : 'below threshold', $result->message);
        $classifier->assertCallCount(1);
    }

    public static function boolean_verdicts(): iterable
    {
        yield 'above threshold' => [0.9, 0.7, true];
        yield 'below threshold' => [0.6, 0.7, false];
        yield 'at threshold' => [0.7, 0.7, true];
        yield 'zero threshold' => [0.0, 0.0, true];
        yield 'one threshold' => [1.0, 1.0, true];
    }

    public function test_score_uses_normalized_expected_position_and_preserves_the_rubric(): void
    {
        $criteria = new Score('How correct is actual compared with reference?', ['Incorrect', 'Partial', 'Correct']);
        $classifier = new FakeClassifier([['judgment' => [0.0, 0.5, 0.5]]]);
        $judge = new ClassifierJudge($classifier, $criteria, threshold: 0.75, reference: 'Paris');

        $result = $judge->evaluate('Paris is the capital of France.');

        self::assertTrue($result->passed);
        self::assertSame(0.75, $result->score);
        self::assertSame('score', $result->context['type']);
        self::assertSame($criteria->instructions, $result->context['criteria']);
        self::assertSame($criteria->levels, $result->context['levels']);
        self::assertSame([0.0, 0.5, 0.5], $result->context['probabilities']);
        self::assertSame(0.75, $result->context['threshold']);
        self::assertSame('Paris', $result->context['reference']);
        self::assertStringContainsString('Normalized score 0.75 meets threshold 0.75', $result->message);
        $classifier->assertSent(fn (ClassificationRequest $request): bool =>
            $request->input === ['actual' => 'Paris is the capital of France.', 'reference' => 'Paris']
            && $request->questions === ['judgment' => $criteria]);
    }

    public function test_equal_scores_preserve_different_distributions(): void
    {
        $classifier = new FakeClassifier([
            ['judgment' => [0.0, 1.0, 0.0]],
            ['judgment' => [0.5, 0.0, 0.5]],
        ]);
        $judge = new ClassifierJudge($classifier, new Score('How complete?', ['None', 'Partial', 'Full']));

        $certain = $judge->evaluate('First output');
        $uncertain = $judge->evaluate('Second output');

        self::assertSame(0.5, $certain->score);
        self::assertSame($certain->score, $uncertain->score);
        self::assertFalse($certain->passed);
        self::assertFalse($uncertain->passed);
        self::assertNotSame($certain->context['probabilities'], $uncertain->context['probabilities']);
        $classifier->assertCallCount(2);
    }

    public function test_trajectory_sends_the_canonical_transcript(): void
    {
        $tool = ToolCall::make('lookup_order')->setInputs(['id' => '123'])->setCallId('call_1');
        $tool->setResult('Shipped');
        $trajectory = Trajectory::fromMessages([
            new UserMessage('Where is order 123?'),
            new ToolCallMessage(null, [$tool]),
            new ToolResultMessage([$tool]),
            new AssistantMessage('Your order has shipped.'),
        ]);
        $classifier = new FakeClassifier([['judgment' => 0.7]]);
        $judge = new ClassifierJudge($classifier, new Boolean('Was the order status retrieved?'));

        self::assertTrue($judge->evaluate($trajectory)->passed);
        $classifier->assertSent(fn (ClassificationRequest $request): bool =>
            $request->input === ['actual' => $trajectory->toTranscript()]);
    }

    #[DataProvider('invalid_thresholds')]
    public function test_invalid_thresholds_are_rejected(float $threshold): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Threshold must be finite and between zero and one.');

        new ClassifierJudge(new FakeClassifier(), new Boolean('Is it correct?'), $threshold);
    }

    public static function invalid_thresholds(): iterable
    {
        yield [-0.1];
        yield [1.1];
        yield [NAN];
        yield [INF];
        yield [-INF];
    }

    #[DataProvider('invalid_inputs')]
    public function test_invalid_inputs_are_rejected_before_classification(mixed $actual): void
    {
        $classifier = new FakeClassifier();
        $judge = new ClassifierJudge($classifier, new Boolean('Is it correct?'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('evaluates a string or a Trajectory');

        try {
            $judge->evaluate($actual);
        } finally {
            $classifier->assertNothingSent();
        }
    }

    public static function invalid_inputs(): iterable
    {
        yield [null];
        yield [42];
        yield [true];
        yield [[]];
        yield [new stdClass()];
    }

    public function test_runner_records_labeled_scores_failures_and_provider_errors(): void
    {
        $classifier = new FakeClassifier([
            ['judgment' => 0.7],
            ['judgment' => 0.2],
        ]);
        $judge = new ClassifierJudge($classifier, new Boolean('Does actual meet reference?'), reference: 'Expected');

        $summary = (new EvaluatorRunner())->run(new ClassifierJudgeEvaluator($judge, [
            ['output' => 'Good output'],
            ['output' => 'Bad output'],
            ['output' => 'Provider error'],
            ['output' => 42],
        ]));

        $results = $summary->getResults();
        self::assertTrue($results[0]->isPassed());
        self::assertFalse($results[1]->isPassed());
        self::assertSame(1, $results[1]->getAssertionsFailed());
        self::assertSame('ClassifierJudge', $judge->getName());
        self::assertSame('quality', $results[1]->getScoreRecords()[0]->label);
        self::assertSame([0.7, 0.2], $summary->getAllAssertionScores());
        self::assertSame('Expected', $results[1]->getAssertionFailures()[0]->getContext()['reference']);
        self::assertSame(['false' => 0.8, 'true' => 0.2], $results[1]->getAssertionFailures()[0]->getContext()['probabilities']);
        self::assertStringContainsString('response queue is empty', $results[2]->getError());
        self::assertSame(0, $results[2]->getAssertionsFailed());
        self::assertSame([], $results[2]->getScoreRecords());
        self::assertStringContainsString('evaluates a string or a Trajectory', $results[3]->getError());
        self::assertSame(0, $results[3]->getAssertionsFailed());
        $classifier->assertCallCount(3);
    }
}
