<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Stub;

class ProtectedConstructorModel
{
    public string $source = 'default';

    protected function __construct()
    {
        $this->source = 'constructor';
    }
}
