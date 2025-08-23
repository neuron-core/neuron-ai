<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation;

use NeuronAI\StructuredOutput\SchemaProperty;

class JudgeScore
{
    public function __construct(
        #[SchemaProperty(description: 'Numeric score between 0.0 and 1.0', required: true)]
        public readonly float $score,
        #[SchemaProperty(description: 'Detailed reasoning for the given score', required: true)]
        public readonly string $reasoning,
        #[SchemaProperty(description: 'Whether the output passes based on threshold', required: true)]
        public readonly bool $passed
    ) {
    }
}
