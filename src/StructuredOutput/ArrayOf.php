<?php

namespace NeuronAI\StructuredOutput;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ArrayOf
{
    public function __construct(public string $class)
    {}
}
