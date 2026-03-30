<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\JudgeScoreOutput;

use function gettype;
use function implode;
use function is_string;

class AgentJudge extends AbstractAssertion
{
    /**
     * @param AgentInterface $judge The AI agent that will act as the evaluator
     * @param string $criteria The evaluation criteria/instructions
     * @param float $threshold The minimum score (0.0-1.0) required to pass
     * @param string|null $reference Optional reference/expected output for comparison
     * @param array<int, array{input: string, output: string, score: float, reasoning: string}> $examples Optional few-shot examples for calibration
     */
    public function __construct(
        protected AgentInterface $judge,
        protected string $criteria,
        protected float $threshold = 0.7,
        protected ?string $reference = null,
        protected array $examples = [],
    ) {
    }

    public function evaluate(mixed $actual): AssertionResult
    {
        if (!is_string($actual)) {
            return AssertionResult::fail(
                0.0,
                'Expected actual value to be a string, got ' . gettype($actual),
            );
        }

        $prompt = $this->buildPrompt($actual);

        /** @var JudgeScoreOutput $result */
        $result = $this->judge->structured(
            new UserMessage($prompt),
            JudgeScoreOutput::class
        );

        $passed = $result->score >= $this->threshold;

        if ($passed) {
            return AssertionResult::pass(
                $result->score,
                $result->reasoning,
                $this->buildContext()
            );
        }

        return AssertionResult::fail(
            $result->score,
            "Score {$result->score} below threshold {$this->threshold}. Reasoning: {$result->reasoning}",
            $this->buildContext()
        );
    }

    /**
     * Build the evaluation prompt with criteria, actual output, optional reference, and examples
     */
    protected function buildPrompt(string $actual): string
    {
        $parts = ["Evaluate the following output based on these criteria:\n\n**Criteria:** {$this->criteria}"];

        if ($this->reference !== null) {
            $parts[] = "\n**Expected (Reference):**\n{$this->reference}";
        }

        $parts[] = "\n**Actual Output:**\n{$actual}";

        if ($this->examples !== []) {
            $parts[] = "\n**Examples of graded outputs:**";
            foreach ($this->examples as $example) {
                $parts[] = "- Input: \"{$example['input']}\"";
                $parts[] = "  Output: \"{$example['output']}\"";
                $parts[] = "  Score: {$example['score']} - {$example['reasoning']}";
            }
        }

        $parts[] = "\nProvide a score between 0.0 and 1.0 with detailed reasoning.";

        return implode("\n", $parts);
    }

    /**
     * Build context array for the assertion result
     */
    protected function buildContext(): array
    {
        return [
            'threshold' => $this->threshold,
            'criteria' => $this->criteria,
            'reference' => $this->reference,
        ];
    }
}
