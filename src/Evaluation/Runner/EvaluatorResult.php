<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

use NeuronAI\Evaluation\AssertionFailure;

class EvaluatorResult
{
    /**
     * @param array<string, mixed> $input
     * @param array<AssertionFailure> $assertionFailures
     */
    public function __construct(
        private readonly int $index,
        private readonly bool $passed,
        private readonly array $input,
        private readonly mixed $output,
        private readonly float $executionTime,
        private readonly int $assertionsPassed,
        private readonly int $assertionsFailed,
        private readonly array $assertionFailures = [],
        private readonly ?string $error = null
    ) {
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInput(): array
    {
        return $this->input;
    }

    public function getOutput(): mixed
    {
        return $this->output;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    public function getAssertionsPassed(): int
    {
        return $this->assertionsPassed;
    }

    public function getAssertionsFailed(): int
    {
        return $this->assertionsFailed;
    }

    public function getTotalAssertions(): int
    {
        return $this->assertionsPassed + $this->assertionsFailed;
    }

    /**
     * @return array<AssertionFailure>
     */
    public function getAssertionFailures(): array
    {
        return $this->assertionFailures;
    }

    public function hasAssertionFailures(): bool
    {
        return $this->assertionFailures !== [];
    }
}
