<?php

namespace NeuronAI\StructuredOutput;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ArrayOf
{
    public function __construct(public string $class)
    {
        if (!class_exists($class) && !enum_exists($class)) {
            throw new InvalidArgumentException("Class $class does not exist.");
        }
    }
}
