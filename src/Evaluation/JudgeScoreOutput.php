<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\InRange;

class JudgeScoreOutput
{
    public function __construct(
        #[SchemaProperty(description: 'Numeric score between 0.0 and 1.0', required: true)]
        #[InRange(min: 0.0, max: 1.0)]
        public readonly float $score,
        #[SchemaProperty(description: 'Detailed reasoning for the given score', required: true)]
        public readonly string $reasoning,
    ) {
    }
}
