<?php

namespace NeuronAI\Tests\Stubs\StructuredOutput;

use NeuronAI\StructuredOutput\SchemaProperty;

class User
{
    #[SchemaProperty(description: 'The name of the user')]
    public string $name;
}
