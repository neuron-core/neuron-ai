<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Stubs\StructuredOutput;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class Person
{
    #[NotBlank]
    public string $firstName;
    public string $lastName;

    public Address $address;

    #[SchemaProperty(anyOf: [Tag::class])]
    #[ArrayOf(Tag::class, allowEmpty: true)]
    public array $tags;
}
