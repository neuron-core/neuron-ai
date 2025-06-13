<?php

namespace NeuronAI\Tests\stubs\models;

use NeuronAI\StructuredOutput\SchemaProperty;

class ComplexNumber
{
    public function __construct(
        #[SchemaProperty(description: "The real part of this number", required: true)]
        public float $re,
        #[SchemaProperty(description: "The imaginary part of this number", required: true)]
        public float $im,
    ) {
    }

    public function add(ComplexNumber $z): ComplexNumber
    {
        return new ComplexNumber($this->re + $z->re, $this->im + $z->im);
    }

    public function __toString(): string
    {
        return "ComplexNumber[re=".$this->re.", im=".$this->im."]";
    }

}
