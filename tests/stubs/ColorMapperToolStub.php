<?php

namespace NeuronAI\Tests\stubs;

use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\Tool;

class ColorMapperToolStub extends Tool
{
    private const MAP = [
        "rgb(1, 0, 0)" => "red",
        "rgb(0, 1, 0)" => "green",
        "rgb(0, 0, 1)" => "blue",
    ];

    public function __construct()
    {
        parent::__construct(
            'color_mapper',
            'Convert an rgb color in a human readable color',
        );

        $this->addProperty(
            new ObjectProperty(
                name: 'color',
                description: 'A object representing a RGB color',
                required: true,
                class: Color::class,
            ),
        );

        $this->setCallable($this);
    }

    public function __invoke(Color $color): string|array
    {
        return self::MAP[(string) $color] ?? ['warning' => "The provided color could not be found"];
    }

}
