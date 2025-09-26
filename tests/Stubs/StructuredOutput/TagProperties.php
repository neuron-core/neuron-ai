<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Stubs\StructuredOutput;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class TagProperties
{
    #[SchemaProperty(description: 'The property value', required: true)]
    #[NotBlank]
    public string $value;
}
