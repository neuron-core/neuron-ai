<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Stubs\StructuredOutput;

use NeuronAI\StructuredOutput\SchemaProperty;

class ColorWithDefaults
{
    #[SchemaProperty(description: "The alpha value", required: false)]
    public int $transparency;

    public function __construct(
        #[SchemaProperty(description: "The RED", required: false)]
        public int $r = 100,
        #[SchemaProperty(description: "The GREEN", required: false)]
        public int $g = 100,
        #[SchemaProperty(description: "The BLUE", required: false)]
        public int $b = 100,
    ) {
    }

}
