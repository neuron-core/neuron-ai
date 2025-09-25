<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Stubs\StructuredOutput;

use NeuronAI\StructuredOutput\SchemaProperty;

class Color
{
    public function __construct(
        #[SchemaProperty(description: "The RED", required: true)]
        public float $r,
        #[SchemaProperty(description: "The GREEN", required: true)]
        public float $g,
        #[SchemaProperty(description: "The BLUE", required: true)]
        public float $b,
    ) {
    }
}
