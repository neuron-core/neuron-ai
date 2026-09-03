<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Runner;

use DateTimeImmutable;

use function end;
use function explode;

class EvaluatorReport
{
    public function __construct(
        protected readonly string $evaluatorClass,
        protected readonly EvaluatorSummary $summary,
        protected readonly DateTimeImmutable $startedAt,
        protected readonly DateTimeImmutable $finishedAt,
        protected readonly ?string $error = null,
        protected readonly ?string $namespace = null,
    ) {
    }

    public function getEvaluatorClass(): string
    {
        return $this->evaluatorClass;
    }

    public function getShortEvaluatorClass(): string
    {
        $parts = explode('\\', $this->evaluatorClass);
        return end($parts);
    }

    public function getSummary(): EvaluatorSummary
    {
        return $this->summary;
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    public function hasFailures(): bool
    {
        return $this->hasError() || $this->summary->hasFailures();
    }
}
