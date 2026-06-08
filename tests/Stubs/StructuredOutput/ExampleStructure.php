<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Stubs\StructuredOutput;

use NeuronAI\StructuredOutput\SchemaProperty;

class ExampleStructure
{
    #[SchemaProperty(description: "Value")]
    public int $value;

    #[SchemaProperty(description: "Rounded", required: false)]
    public float $rounded;
}
