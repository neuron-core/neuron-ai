<?php

declare( strict_types=1 );

namespace NeuronAI\Tests\Stubs\StructuredOutput;

use NeuronAI\StructuredOutput\SchemaProperty;

class ColorWithDefaults
{

    public function __construct(
        #[SchemaProperty( description: "The RED", required: false )]
        public int $r = 0,
        #[SchemaProperty( description: "The GREEN", required: false )]
        public int $g = 0,
        #[SchemaProperty( description: "The BLUE", required: false )]
        public int $b = 0,
    ) {}

}
