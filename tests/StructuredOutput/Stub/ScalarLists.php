<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Stub;

use NeuronAI\StructuredOutput\SchemaProperty;

class ScalarLists
{
    #[SchemaProperty(anyOf: ['int'])]
    public array $ints = [];

    #[SchemaProperty(anyOf: ['float'])]
    public array $floats = [];

    #[SchemaProperty(anyOf: ['bool'])]
    public array $bools = [];

    #[SchemaProperty(anyOf: ['string'])]
    public array $strings = [];
}
