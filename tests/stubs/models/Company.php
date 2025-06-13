<?php

namespace NeuronAI\Tests\stubs\models;

use NeuronAI\StructuredOutput\SchemaProperty;

class Company
{
    #[SchemaProperty(description: 'The name of the company', required: true)]
    public string $name;

    #[SchemaProperty(description: 'The location of the company', required: true)]
    public string $location;
}
