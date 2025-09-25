<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Stubs\StructuredOutput;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class Tag
{
    #[SchemaProperty(description: 'The name of the tag')]
    #[NotBlank]
    public string $name;

    #[SchemaProperty(description: 'Properties can contains additional values', required: false)]
    /**
     * @var \NeuronAI\Tests\Stubs\StructuredOutput\TagProperties[]
     */
    #[ArrayOf(TagProperties::class)]
    public array $properties;
}
