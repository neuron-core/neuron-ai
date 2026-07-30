<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Stubs\StructuredOutput;

use NeuronAI\StructuredOutput\SchemaPropertiesInterface;
use NeuronAI\StructuredOutput\SchemaProperty;

class DynamicPerson implements SchemaPropertiesInterface
{
    public string $firstName;

    #[SchemaProperty(description: 'Attribute description')]
    public string $lastName;

    #[SchemaProperty(description: 'Attribute description')]
    public string $nickName;

    public array $tags;

    public static function schemaProperties(): array
    {
        return [
            'firstName' => new SchemaProperty(description: 'Runtime '.'description'),
            'nickName' => new SchemaProperty(description: 'Runtime wins'),
            'tags' => new SchemaProperty(anyOf: [Tag::class]),
        ];
    }
}
