<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Stub;

class Headline
{
    public function __construct(public readonly string $title = 'untitled')
    {
    }
}
