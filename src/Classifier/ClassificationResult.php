<?php

declare(strict_types=1);

namespace NeuronAI\Classifier;

use InvalidArgumentException;

use function array_diff_key;
use function count;
use function get_object_vars;

class ClassificationResult
{
    /**
     * @param array<string, ChoiceResult|ScoreResult|BooleanResult|Refusal> $answers One answer or refusal per requested question.
     */
    public function __construct(
        ClassificationRequest $request,
        public readonly array $answers,
    ) {
        if (count($answers) !== count($request->questions)
            || array_diff_key($request->questions, $answers) !== []) {
            throw new InvalidArgumentException('Classification answers must match the requested question identifiers.');
        }

        foreach ($request->questions as $id => $question) {
            $answer = $answers[$id];

            if ($answer instanceof Refusal) {
                continue;
            }

            if ((!$answer instanceof ChoiceResult && !$answer instanceof ScoreResult && !$answer instanceof BooleanResult)
                || $answer->definition::class !== $question::class
                || get_object_vars($answer->definition) !== get_object_vars($question)) {
                throw new InvalidArgumentException("Answer '{$id}' must match its requested question definition.");
            }
        }
    }

    public function choice(string $id): ChoiceResult
    {
        $answer = $this->answer($id);

        if (!$answer instanceof ChoiceResult) {
            throw new InvalidArgumentException("Classification answer '{$id}' is not a ChoiceResult.");
        }

        return $answer;
    }

    public function score(string $id): ScoreResult
    {
        $answer = $this->answer($id);

        if (!$answer instanceof ScoreResult) {
            throw new InvalidArgumentException("Classification answer '{$id}' is not a ScoreResult.");
        }

        return $answer;
    }

    public function boolean(string $id): BooleanResult
    {
        $answer = $this->answer($id);

        if (!$answer instanceof BooleanResult) {
            throw new InvalidArgumentException("Classification answer '{$id}' is not a BooleanResult.");
        }

        return $answer;
    }

    public function isRefused(string $id): bool
    {
        if (!isset($this->answers[$id])) {
            throw new InvalidArgumentException("Classification answer '{$id}' does not exist.");
        }

        return $this->answers[$id] instanceof Refusal;
    }

    protected function answer(string $id): ChoiceResult|ScoreResult|BooleanResult
    {
        $answer = $this->answers[$id]
            ?? throw new InvalidArgumentException("Classification answer '{$id}' does not exist.");

        if ($answer instanceof Refusal) {
            throw new InvalidArgumentException("Classification answer '{$id}' was refused by the provider.");
        }

        return $answer;
    }
}
