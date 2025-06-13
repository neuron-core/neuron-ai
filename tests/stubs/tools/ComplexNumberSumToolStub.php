<?php

namespace NeuronAI\Tests\stubs\tools;

use NeuronAI\Tests\stubs\models\ComplexNumber;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\Tool;

class ComplexNumberSumToolStub extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: "complex_number_sum",
            description: "Process the sum of complex numbers",
        );

        $this->addProperty(
            new ArrayProperty(
                name: "complex_numbers",
                description: "An array of complex numbers",
                required: true,
                items: ObjectProperty::asItem(ComplexNumber::class),
            )
        );

        $this->setCallable($this);
    }

    public function __invoke(array $complex_numbers): ComplexNumber
    {
        $result = new ComplexNumber(0, 0);
        foreach ($complex_numbers as $complexNumber) {
            $result = $result->add($complexNumber);
        }
        return $result;
    }


}
