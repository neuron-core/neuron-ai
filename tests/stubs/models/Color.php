<?php

namespace NeuronAI\Tests\stubs\models;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\IsNotNull;

class Color
{
    public function __construct(
        #[IsNotNull]
        #[SchemaProperty(description: "The RED", required: true)]
        public float $r,
        #[IsNotNull]
        #[SchemaProperty(description: "The GREEN", required: true)]
        public float $g,
        #[IsNotNull]
        #[SchemaProperty(description: "The BLUE", required: true)]
        public float $b,
    ) {
    }

    public function __toString(): string
    {
        return 'rgb(' . $this->r . ', ' . $this->g . ', ' . $this->b . ')';
    }


}
