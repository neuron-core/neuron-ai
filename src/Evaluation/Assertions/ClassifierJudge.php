<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use InvalidArgumentException;
use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Classifier\ClassifierInterface;
use NeuronAI\Classifier\Score;
use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Conversation\Trajectory;

use function count;
use function get_debug_type;
use function is_finite;
use function is_string;

class ClassifierJudge extends AbstractAssertion
{
    /**
     * @param Boolean|Score $criteria Boolean probability or ascending quality levels, normalized to 0–1.
     * @param float $threshold Minimum probability or normalized score required to pass.
     * @param string|null $reference Expected output or supporting context for the judgment.
     */
    public function __construct(
        protected ClassifierInterface $classifier,
        protected Boolean|Score $criteria,
        protected float $threshold = 0.7,
        protected ?string $reference = null,
    ) {
        if (!is_finite($threshold) || $threshold < 0 || $threshold > 1) {
            throw new InvalidArgumentException('Threshold must be finite and between zero and one.');
        }
    }

    public function evaluate(mixed $actual): AssertionResult
    {
        if ($actual instanceof Trajectory) {
            $actual = $actual->toTranscript();
        }

        if (!is_string($actual)) {
            throw new InvalidArgumentException(
                static::class . ' evaluates a string or a Trajectory, got ' . get_debug_type($actual)
            );
        }

        $input = ['actual' => $actual];
        if ($this->reference !== null) {
            $input['reference'] = $this->reference;
        }

        $result = $this->classifier->classify(new ClassificationRequest(
            input: $input,
            questions: ['judgment' => $this->criteria],
        ));

        $context = [
            'threshold' => $this->threshold,
            'criteria' => $this->criteria->instructions,
            'reference' => $this->reference,
        ];

        if ($this->criteria instanceof Boolean) {
            $score = $result->boolean('judgment')->probability;
            $context['type'] = 'boolean';
            $context['probabilities'] = ['false' => 1 - $score, 'true' => $score];
            $metric = 'Probability of true';
        } else {
            $answer = $result->score('judgment');
            $score = $answer->score / (count($this->criteria->levels) - 1);
            $context['type'] = 'score';
            $context['levels'] = $this->criteria->levels;
            $context['probabilities'] = $answer->distribution->probabilities;
            $metric = 'Normalized score';
        }

        if ($score >= $this->threshold) {
            return AssertionResult::pass($score, "{$metric} {$score} meets threshold {$this->threshold}.", $context);
        }

        return AssertionResult::fail($score, "{$metric} {$score} below threshold {$this->threshold}.", $context);
    }
}
