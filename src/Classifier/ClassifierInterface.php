<?php

declare(strict_types=1);

namespace NeuronAI\Classifier;

interface ClassifierInterface
{
    /**
     * Evaluate each question against the same input, without depending on other answers.
     * Implementations may execute the questions together or separately.
     */
    public function classify(ClassificationRequest $request): ClassificationResult;
}
