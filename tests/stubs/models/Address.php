<?php

namespace NeuronAI\Tests\stubs\models;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class Address
{
    #[SchemaProperty(description: 'The name of the street')]
    #[NotBlank]
    public string $street;

    public string $city;

    #[SchemaProperty(description: 'The zip code of the address')]
    #[NotBlank]
    public string $zip;
}
