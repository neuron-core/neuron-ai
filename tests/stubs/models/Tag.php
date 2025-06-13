<?php

namespace NeuronAI\Tests\stubs\models;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class Tag
{
    #[SchemaProperty(description: 'The name of the tag')]
    #[NotBlank]
    public string $name;
}
