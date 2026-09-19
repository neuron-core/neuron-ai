<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use InvalidArgumentException;
use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\BooleanResult;
use NeuronAI\Classifier\Choice;
use NeuronAI\Classifier\ChoiceResult;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Classifier\ClassificationResult;
use NeuronAI\Classifier\ClassifierInterface;
use NeuronAI\Classifier\Score;
use NeuronAI\Classifier\ScoreResult;
use NeuronAI\Exceptions\ProviderException;
use PHPUnit\Framework\Assert;

use function array_diff_key;
use function array_is_list;
use function array_shift;
use function count;
use function is_array;
use function is_float;
use function is_int;

class FakeClassifier implements ClassifierInterface
{
    /** @var list<ClassificationRequest> */
    protected array $recorded = [];

    /**
     * @param list<array<string, int|float|array<array-key, int|float>>> $responses One answer map per call.
     */
    public function __construct(protected array $responses = [])
    {
        if (!array_is_list($responses)) {
            throw new InvalidArgumentException('FakeClassifier expects a list of answer maps, one per classify() call.');
        }

        foreach ($responses as $response) {
            if (!is_array($response)) {
                throw new InvalidArgumentException('Each FakeClassifier response must be an answer map.');
            }
        }
    }

    public function classify(ClassificationRequest $request): ClassificationResult
    {
        $this->recorded[] = $request;

        if ($this->responses === []) {
            throw new ProviderException('FakeClassifier response queue is empty. Pass more responses to the constructor.');
        }

        $response = array_shift($this->responses);

        if (count($response) !== count($request->questions)
            || array_diff_key($request->questions, $response) !== []) {
            throw new ProviderException('FakeClassifier answers must match the requested question identifiers.');
        }

        $answers = [];

        foreach ($request->questions as $id => $question) {
            $answers[$id] = $this->answer($id, $question, $response[$id]);
        }

        return new ClassificationResult($request, $answers);
    }

    /**
     * @return list<ClassificationRequest>
     */
    public function getRecorded(): array
    {
        return $this->recorded;
    }

    public function getCallCount(): int
    {
        return count($this->recorded);
    }

    public function assertCallCount(int $expected): void
    {
        Assert::assertCount(
            $expected,
            $this->recorded,
            "Expected {$expected} classification calls, got ".count($this->recorded).'.',
        );
    }

    /**
     * @param callable(ClassificationRequest): bool $callback
     */
    public function assertSent(callable $callback): void
    {
        $matched = false;

        foreach ($this->recorded as $request) {
            if ($callback($request)) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, 'No recorded classification request matched the given assertion callback.');
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty(
            $this->recorded,
            'Expected no classification calls, but '.count($this->recorded).' were recorded.',
        );
    }

    protected function answer(string $id, Choice|Score|Boolean $question, mixed $probabilities): ChoiceResult|ScoreResult|BooleanResult
    {
        try {
            if ($question instanceof Boolean) {
                if (!is_int($probabilities) && !is_float($probabilities)) {
                    throw new InvalidArgumentException('A Boolean answer must be a numeric probability.');
                }

                return new BooleanResult($question, $probabilities);
            }

            if (!is_array($probabilities)) {
                throw new InvalidArgumentException('Choice and Score answers must be probability distributions.');
            }

            return $question instanceof Choice
                ? new ChoiceResult($question, $probabilities)
                : new ScoreResult($question, $probabilities);
        } catch (InvalidArgumentException $exception) {
            throw new ProviderException("Invalid FakeClassifier answer '{$id}': {$exception->getMessage()}", 0, $exception);
        }
    }
}
