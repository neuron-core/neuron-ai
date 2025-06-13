<?php

namespace NeuronAI\Tests\stubs\models;

use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;
use NeuronAI\Tests\stubs\models\Address;
use NeuronAI\Tests\stubs\models\Tag;

class Person
{
    #[NotBlank]
    public string $firstName;
    public string $lastName;

    public Address $address;

    /**
     * @var \NeuronAI\Tests\stubs\models\Tag[]
     */
    #[ArrayOf(Tag::class, allowEmpty: true)]
    public array $tags;
}
