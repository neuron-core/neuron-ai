<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Stub;

class Department
{
    public string $title;

    public ?Employee $manager = null;
}
