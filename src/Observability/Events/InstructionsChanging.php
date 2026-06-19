<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

class InstructionsChanging
{
    public function __construct(
        public string $instructions
    ) {
    }
}
